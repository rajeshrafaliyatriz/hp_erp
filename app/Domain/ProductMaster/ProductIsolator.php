<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every product row ONE organisation (S15 follow-up, 24 Nov 2026; contract Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md section 3).
 * It is the engine behind `php artisan product-master:isolate-tenants`, and it is written for ONE run on each host: the rows of the old sharing (a Product of organisation 0 that
 * every organisation could read, an About with no organisation) are moved to the organisation they belong to, and after it the checker's `not_isolated` rule finds nothing.
 *
 * What it does, in this order (an organisation is a transaction; the others never wait for it, and one failing leaves the rest done and the failure told):
 *   1. Every OTHER organisation that points at a shared Product (its Sub-products' parent_id, or any of the registered pointers: leads, opportunities, campaigns, Sell items,
 *      signals, research runs, claims, playbooks, outreach) gets its OWN Product, made once, with the same key, name, signal key and Portfolio name, active. If it already has a
 *      Product with that key, THAT one is used: the organisation's own row wins. Every pointer of that organisation at the shared row is moved to its own, through the
 *      registry (ProductPointers::toMaster() and the master's own parent_id): no table is listed here by hand, so a pointer added to the registry is moved too.
 *   2. The OWNER organisation (--owner, which must be one of config product_isolation.builtin_owner_tenants) takes the shared Products: a row of organisation 0 becomes its row
 *      (same id, so nothing that points at it changes). If the owner already has a Product with the same key, the owner's row wins: its children and pointers are moved to it
 *      and the shared row is deleted, but only when nothing else points at it any more.
 *   3. An About (g2g_product_offers) with no organisation (NULL) gets the owner, together with its shared Sub-product row.
 *   4. Anything it cannot place is LISTED and left exactly as it is: a shared row another organisation still points at, an organisation that holds a key with another
 *      meaning, a pointer table the registry scopes in a way this class does not know, a unique key that would collide. The `not_isolated` rule keeps reporting it.
 *
 * `plan()` only reads (SELECT statements; the dry run of the command also asks the server to refuse writes). `apply()` first saves the before-image of every row it will touch
 * (full rows, JSON, under storage/app/product-isolation/) and only then writes. `rollback()` puts the changed columns back, all or nothing, and refuses when a row was changed
 * since, or when a new record already points at a Product this run made.
 *
 * It never touches crm_products' own rows beyond the registry's `product_master_id` pointer (Sell is already per organisation), and it writes only the pointer columns, the
 * master row's `sub_institute_id` and the Products it inserts: not even `updated_at` of a pointer row moves.
 *
 * Built on the query builder and an explicit connection, MariaDB 10.1 safe (plain SELECT / UPDATE / INSERT / DELETE, no JSON functions, no window functions, no CTEs).
 */
final class ProductIsolator
{
    public const FORMAT = 'product-isolation/1';

    private const MASTER = ProductPointers::MASTER;

    private const OFFERS = ProductPointers::OFFERS;

    private const CHUNK = 400;

    private readonly Connection $db;

    /** @var array<string, bool> */
    private array $present = [];

    /** @var array<string, bool> "table#id#column" => the before-image names it: the apply writes nothing the before-image did not save */
    private array $known = [];

    public function __construct(private readonly ?string $connection = null)
    {
        $this->db = DB::connection($connection);
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    // ------------------------------------------------------------------ the plan (READ ONLY)

    /**
     * What a run would do, and what it cannot. Only SELECT statements.
     *
     * @return array{
     *     owner: int, shared: array{products: int, others: int}, nullOffers: int,
     *     orgs: array<int, array{tenant: int, owner: bool, actions: list<array<string, mixed>>}>,
     *     blocked: list<string>, notes: list<string>, work: int
     * }
     */
    public function plan(int $owner): array
    {
        $plan = ['owner' => $owner, 'shared' => ['products' => 0, 'others' => 0], 'nullOffers' => 0, 'orgs' => [], 'blocked' => [], 'notes' => [], 'work' => 0];
        if (! $this->hasTable(self::MASTER)) {
            $plan['notes'][] = 'g2g_product_master is not on this host, so there is no product row to isolate.';

            return $plan;
        }

        $shared = $this->db->table(self::MASTER)->where('sub_institute_id', 0)->orderBy('id')->get()->keyBy('id');
        $products = $shared->where('kind', ProductMaster::KIND_PRODUCT);
        $others = $shared->where('kind', '!=', ProductMaster::KIND_PRODUCT);
        $plan['shared'] = ['products' => $products->count(), 'others' => $others->count()];

        $nullOffers = $this->hasTable(self::OFFERS) ? $this->db->table(self::OFFERS)->whereNull('sub_institute_id')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
        $plan['nullOffers'] = count($nullOffers);

        $refs = $shared->isEmpty() ? ['by' => [], 'unplaced' => []] : $this->references($shared->keys()->map(fn ($v) => (int) $v)->all(), $plan['notes']);
        foreach ($refs['unplaced'] as $line) {
            $plan['blocked'][] = $line;
        }

        // 1. every other organisation that points at a shared row
        foreach ($refs['by'] as $tenant => $mine) {
            if ($tenant === $owner) {
                continue;
            }
            $zids = array_values(array_unique(array_merge(array_keys($mine['children']), $this->zidsOf($mine['pointers']))));
            sort($zids);
            foreach ($zids as $zid) {
                $row = $shared[$zid];
                $children = $mine['children'][$zid] ?? [];
                $pointers = $this->pointersAt($mine['pointers'], $zid);
                if ($row->kind !== ProductMaster::KIND_PRODUCT) {
                    $plan['blocked'][] = "organisation {$tenant} points at the shared {$row->kind} {$zid} '{$row->name}', which is not a Product and cannot be copied for it: left as it is";

                    continue;
                }
                $action = $this->copyAction($tenant, $row, $children, $pointers, $plan['blocked']);
                if ($action !== null) {
                    $plan['orgs'][$tenant]['tenant'] = $tenant;
                    $plan['orgs'][$tenant]['owner'] = false;
                    $plan['orgs'][$tenant]['actions'][] = $action;
                }
            }
        }

        // 2 and 3. the owner takes the shared rows, and the Abouts with no organisation
        $actions = [];
        $mineOfOwner = $refs['by'][$owner] ?? ['children' => [], 'pointers' => []];
        // 2a. a shared Sub-product or item follows the owner, when no other organisation points at it and no unique key would collide
        $stampedRows = [];
        foreach ($others as $zid => $row) {
            $foreign = $this->foreignReferences((int) $zid, $owner, $refs['by']);
            if ($foreign > 0) {
                $plan['blocked'][] = "the shared {$row->kind} {$zid} '{$row->name}' is still pointed at by another organisation ({$foreign} record(s)): left as it is";

                continue;
            }
            $clash = $this->ownerClash($owner, $row);
            if ($clash !== null) {
                $plan['blocked'][] = "the shared {$row->kind} {$zid} '{$row->name}' cannot become organisation {$owner}'s: {$clash}";

                continue;
            }
            $actions[] = ['type' => 'stamp_row', 'org' => $owner, 'from' => (int) $zid, 'key' => (string) $row->product_key, 'name' => (string) $row->name, 'kind' => (string) $row->kind, 'offer' => $row->offer_row_id !== null ? (int) $row->offer_row_id : null, 'parent' => $row->parent_id !== null ? (int) $row->parent_id : null];
            $stampedRows[(int) $zid] = true;
        }
        // 2b. the Products. A shared Sub-product that is stamped now hangs under the Product the owner keeps, so when its Product is merged away it is one of the rows moved.
        foreach ($products as $zid => $row) {
            $children = $mineOfOwner['children'][$zid] ?? [];
            foreach ($actions as $a) {
                if ($a['type'] === 'stamp_row' && $a['parent'] === (int) $zid) {
                    $children[] = $a['from'];
                }
            }
            $pointers = $this->pointersAt($mineOfOwner['pointers'], (int) $zid);
            $action = $this->ownerAction($owner, $row, $children, $pointers, $plan['blocked']);
            if ($action !== null) {
                $actions[] = $action;
            }
        }
        $offerIds = [];
        foreach ($nullOffers as $id) {
            $holder = $this->db->table(self::MASTER)->where('offer_row_id', $id)->get(['id', 'sub_institute_id']);
            $blocked = false;
            foreach ($holder as $h) {
                if ((int) $h->sub_institute_id === 0 && ! isset($stampedRows[(int) $h->id])) {
                    $blocked = true;
                }
            }
            if ($blocked) {
                $plan['blocked'][] = "the About {$id} has no organisation but its shared Sub-product could not be given to organisation {$owner}: left as it is";

                continue;
            }
            $offerIds[] = $id;
        }
        if ($offerIds !== []) {
            $actions[] = ['type' => 'offers', 'org' => $owner, 'ids' => $offerIds];
        }
        if ($actions !== []) {
            $plan['orgs'][$owner] = ['tenant' => $owner, 'owner' => true, 'actions' => $actions];
        }

        ksort($plan['orgs']);
        // the owner always goes last: the shared rows are the template of every other organisation's copy, and they are deleted or given away only then
        if (isset($plan['orgs'][$owner])) {
            $own = $plan['orgs'][$owner];
            unset($plan['orgs'][$owner]);
            $plan['orgs'][$owner] = $own;
        }
        $plan['work'] = array_sum(array_map(fn (array $o): int => count($o['actions']), $plan['orgs']));

        return $plan;
    }

    /**
     * The action that gives ONE organisation its own copy of a shared Product (or points it at the Product of the same key it already has).
     *
     * @param list<int> $children master rows of the organisation whose parent_id is the shared Product
     * @param array<string, list<int>> $pointers "table.column" => ids of the organisation's rows that point at it
     * @param list<string> $blocked
     * @return array<string, mixed>|null
     */
    private function copyAction(int $tenant, object $row, array $children, array $pointers, array &$blocked): ?array
    {
        $existing = $this->db->table(self::MASTER)->where('sub_institute_id', $tenant)->where('product_key', $row->product_key)->first();
        $label = "organisation {$tenant}: the shared Product {$row->id} '{$row->name}' ({$row->product_key})";
        $action = ['org' => $tenant, 'from' => (int) $row->id, 'key' => (string) $row->product_key, 'name' => (string) $row->name, 'children' => $children, 'pointers' => $pointers, 'copy' => [
            'sub_institute_id' => $tenant, 'product_key' => $row->product_key, 'kind' => ProductMaster::KIND_PRODUCT, 'parent_id' => null, 'name' => $row->name,
            'status' => ProductMaster::STATUS_ACTIVE, 'origin' => $row->origin, 'signal_key' => $row->signal_key, 'portfolio_name' => $row->portfolio_name,
            'offer_row_id' => null, 'crm_product_id' => null, 'created_by' => null, 'updated_by' => null,
        ]];

        if ($existing === null) {
            if ($row->signal_key !== null && $row->signal_key !== '') {
                $other = $this->db->table(self::MASTER)->where('sub_institute_id', $tenant)->where('signal_key', $row->signal_key)->first(['id', 'product_key']);
                if ($other !== null) {
                    $blocked[] = "{$label}: the organisation already holds row {$other->id} ('{$other->product_key}') with the signal key '{$row->signal_key}', so a copy would break the unique key; left as it is";

                    return null;
                }
            }

            return ['type' => 'create'] + $action;
        }
        if ($existing->kind !== ProductMaster::KIND_PRODUCT) {
            $blocked[] = "{$label}: the organisation holds a {$existing->kind} (row {$existing->id}) under the key '{$row->product_key}', so a Product cannot be made under it; left as it is";

            return null;
        }
        $why = $this->cannotRepoint($tenant, $row, $existing, $children);
        if ($why !== null) {
            $blocked[] = "{$label}: {$why}; left as it is";

            return null;
        }

        return ['type' => 'reuse', 'to' => (int) $existing->id] + $action;
    }

    /**
     * What the OWNER does with one shared Product: becomes its row, or (when it has a Product with the same key) lets its own row win.
     *
     * @param list<int> $children
     * @param array<string, list<int>> $pointers
     * @param list<string> $blocked
     * @return array<string, mixed>|null
     */
    private function ownerAction(int $owner, object $row, array $children, array $pointers, array &$blocked): ?array
    {
        $label = "the shared Product {$row->id} '{$row->name}' ({$row->product_key})";
        $existing = $this->db->table(self::MASTER)->where('sub_institute_id', $owner)->where('product_key', $row->product_key)->first();
        if ($existing === null) {
            $clash = $this->ownerClash($owner, $row);
            if ($clash !== null) {
                $blocked[] = "{$label} cannot become organisation {$owner}'s: {$clash}; left as it is";

                return null;
            }

            return ['type' => 'stamp', 'org' => $owner, 'from' => (int) $row->id, 'key' => (string) $row->product_key, 'name' => (string) $row->name];
        }
        if ($existing->kind !== ProductMaster::KIND_PRODUCT) {
            $blocked[] = "{$label}: organisation {$owner} holds a {$existing->kind} (row {$existing->id}) under that key, so the Product cannot become its; left as it is";

            return null;
        }
        $why = $this->cannotRepoint($owner, $row, $existing, $children);
        if ($why !== null) {
            $blocked[] = "{$label}: {$why}; left as it is";

            return null;
        }

        return ['type' => 'merge', 'org' => $owner, 'from' => (int) $row->id, 'to' => (int) $existing->id, 'key' => (string) $row->product_key, 'name' => (string) $row->name, 'children' => $children, 'pointers' => $pointers];
    }

    /** Why a shared row cannot be stamped with the owner (a unique key of the master table would collide), or null. */
    private function ownerClash(int $owner, object $row): ?string
    {
        $q = fn () => $this->db->table(self::MASTER)->where('sub_institute_id', $owner)->where('id', '!=', $row->id);
        if ($q()->where('product_key', $row->product_key)->exists()) {
            return "the organisation already holds a row with the key '{$row->product_key}'";
        }
        if ($row->signal_key !== null && $row->signal_key !== '' && $q()->where('signal_key', $row->signal_key)->exists()) {
            return "the organisation already holds a row with the signal key '{$row->signal_key}'";
        }
        if ($row->offer_row_id !== null && $q()->where('offer_row_id', $row->offer_row_id)->exists()) {
            return 'the organisation already holds a row for the same About';
        }
        if ($row->crm_product_id !== null && $q()->where('crm_product_id', $row->crm_product_id)->exists()) {
            return 'the organisation already holds a row for the same catalogue item';
        }

        return null;
    }

    /**
     * Why an organisation's records cannot be moved from the shared Product to the Product it already has, or null: a Sub-product with an About would sit under a Product with
     * another Portfolio name (the checker's I5), or a unique key of a pointer table would collide.
     *
     * @param list<int> $children
     */
    private function cannotRepoint(int $tenant, object $from, object $to, array $children): ?string
    {
        if ($children !== [] && mb_strtolower(trim((string) $from->portfolio_name)) !== mb_strtolower(trim((string) $to->portfolio_name))
            && $this->db->table(self::MASTER)->whereIn('id', $children)->whereNotNull('offer_row_id')->exists()) {
            return "its Sub-products with an About would move under Product {$to->id}, whose Portfolio name is '{$to->portfolio_name}' and not '{$from->portfolio_name}'";
        }
        $collisions = $this->uniqueCollisions($tenant, (int) $from->id, (int) $to->id);
        if ($collisions !== []) {
            return 'a unique key would collide on ' . implode(', ', $collisions);
        }

        return null;
    }

    /**
     * The pointer tables where a row of the organisation that holds `$from` carries the same values in the rest of a unique key as a row that holds `$to`
     * (ProductMerger::uniqueKeys()), so moving it would break the key.
     *
     * @return list<string> "table.column"
     */
    private function uniqueCollisions(int $tenant, int $from, int $to): array
    {
        $out = [];
        foreach (ProductMerger::uniqueKeys() as $key => $others) {
            [$table, $column] = explode('.', $key, 2);
            $others = array_values(array_diff($others, ['sub_institute_id']));
            if (! $this->usable($table, array_merge(['id', $column, 'sub_institute_id'], $others))) {
                continue;
            }
            $taken = [];
            foreach ($this->db->table($table)->where('sub_institute_id', $tenant)->where($column, $to)->get($others) as $r) {
                $taken[$this->print($r, $others)] = true;
            }
            foreach ($this->db->table($table)->where('sub_institute_id', $tenant)->where($column, $from)->get($others) as $r) {
                if (isset($taken[$this->print($r, $others)])) {
                    $out[] = $key;
                    break;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $columns */
    private function print(object $row, array $columns): string
    {
        $parts = [];
        foreach ($columns as $c) {
            $parts[] = $row->{$c} === null ? "\x00" : (string) $row->{$c};
        }

        return implode("\x1f", $parts);
    }

    // ------------------------------------------------------------------ what points at the shared rows (READ ONLY)

    /**
     * Every record that points at one of the shared rows, by the organisation it belongs to: the master rows whose parent_id is one of them, and the rows of every
     * registered pointer (ProductPointers::toMaster()) that hold one of their ids. Rows with no organisation cannot be attributed and are returned in `unplaced`.
     *
     * @param list<int> $zids
     * @param list<string> $notes
     * @return array{by: array<int, array{children: array<int, list<int>>, pointers: array<string, array<int, list<int>>>}>, unplaced: list<string>}
     */
    private function references(array $zids, array &$notes): array
    {
        $by = [];
        $unplaced = [];

        foreach (array_chunk($zids, self::CHUNK) as $chunk) {
            foreach ($this->db->table(self::MASTER)->whereIn('parent_id', $chunk)->where('sub_institute_id', '!=', 0)->orderBy('id')->get(['id', 'parent_id', 'sub_institute_id']) as $r) {
                $by[(int) $r->sub_institute_id]['children'][(int) $r->parent_id][] = (int) $r->id;
            }
        }
        foreach (ProductPointers::toMaster() as $e) {
            $label = $e['table'] . '.' . $e['column'];
            if (! is_string($e['scope'])) {
                $unplaced[] = "{$label}: the registry says its organisation is found through another table, which this command does not follow, so its rows were not examined";

                continue;
            }
            if (! $this->usable($e['table'], ['id', $e['column'], $e['scope']])) {
                $notes[] = "{$label}: not on this host, so it was not examined";

                continue;
            }
            $lost = 0;
            foreach (array_chunk($zids, self::CHUNK) as $chunk) {
                foreach ($this->db->table($e['table'])->whereIn($e['column'], $chunk)->orderBy('id')->get(['id', $e['column'] . ' as pid', $e['scope'] . ' as tid']) as $r) {
                    $tid = $r->tid === null ? 0 : (int) $r->tid;
                    if ($tid <= 0) {
                        $lost++;

                        continue;
                    }
                    $by[$tid]['pointers'][$label][(int) $r->pid][] = (int) $r->id;
                }
            }
            if ($lost > 0) {
                $unplaced[] = "{$label}: {$lost} row(s) with no organisation point at a shared row, so they cannot be given to any organisation: left as they are";
            }
        }
        foreach ($by as $tenant => $mine) {
            $by[$tenant] = ['children' => $mine['children'] ?? [], 'pointers' => $mine['pointers'] ?? []];
        }
        ksort($by);

        return ['by' => $by, 'unplaced' => $unplaced];
    }

    /** @param array<string, array<int, list<int>>> $pointers @return list<int> the shared ids an organisation's pointer rows hold */
    private function zidsOf(array $pointers): array
    {
        $out = [];
        foreach ($pointers as $byZid) {
            foreach (array_keys($byZid) as $zid) {
                $out[(int) $zid] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param array<string, array<int, list<int>>> $pointers
     * @return array<string, list<int>> "table.column" => ids of the rows that hold $zid
     */
    private function pointersAt(array $pointers, int $zid): array
    {
        $out = [];
        foreach ($pointers as $label => $byZid) {
            if (isset($byZid[$zid])) {
                $out[$label] = $byZid[$zid];
            }
        }

        return $out;
    }

    /** How many records of organisations other than the owner still point at a shared row. @param array<int, array<string, mixed>> $by */
    private function foreignReferences(int $zid, int $owner, array $by): int
    {
        $n = 0;
        foreach ($by as $tenant => $mine) {
            if ($tenant === $owner) {
                continue;
            }
            $n += count($mine['children'][$zid] ?? []);
            foreach ($mine['pointers'] as $byZid) {
                $n += count($byZid[$zid] ?? []);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ the text of a plan

    /**
     * What a plan says, line by line (the dry run prints it; the apply prints it before it writes).
     *
     * @param array<string, mixed> $plan
     * @return list<string>
     */
    public function describe(array $plan, bool $apply): array
    {
        $would = $apply ? 'will' : 'would';
        $lines = [];
        $lines[] = sprintf('Shared rows found: %d Product(s) and %d other row(s) of organisation 0; %d About row(s) with no organisation. Owner organisation: %d.', $plan['shared']['products'], $plan['shared']['others'], $plan['nullOffers'], $plan['owner']);
        foreach ($plan['notes'] as $note) {
            $lines[] = 'Note: ' . $note;
        }
        foreach ($plan['orgs'] as $tenant => $org) {
            $lines[] = '';
            $lines[] = "Organisation {$tenant}" . ($org['owner'] ? ' (the owner: takes the shared rows)' : '');
            foreach ($org['actions'] as $a) {
                $counts = $this->countsText($a);
                switch ($a['type']) {
                    case 'create':
                        $lines[] = "  {$would} make its own Product '{$a['name']}' (key {$a['key']}) from shared Product {$a['from']}{$counts}";
                        break;
                    case 'reuse':
                        $lines[] = "  {$would} use its own Product {$a['to']} (key {$a['key']}) in place of shared Product {$a['from']}{$counts}";
                        break;
                    case 'stamp':
                        $lines[] = "  {$would} take shared Product {$a['from']} '{$a['name']}' (key {$a['key']}): it becomes organisation {$tenant}'s row, the id stays";
                        break;
                    case 'merge':
                        $lines[] = "  {$would} let its own Product {$a['to']} win over shared Product {$a['from']} '{$a['name']}' (key {$a['key']}){$counts}, then delete the shared row if nothing else points at it";
                        break;
                    case 'stamp_row':
                        $lines[] = "  {$would} take shared {$a['kind']} {$a['from']} '{$a['name']}'" . ($a['offer'] !== null ? " with its About {$a['offer']}" : '');
                        break;
                    case 'offers':
                        $lines[] = "  {$would} take " . count($a['ids']) . ' About row(s) that have no organisation (ids ' . $this->idList($a['ids']) . ')';
                        break;
                }
            }
        }
        if ($plan['blocked'] !== []) {
            $lines[] = '';
            $lines[] = 'Cannot place (listed and left exactly as they are):';
            foreach ($plan['blocked'] as $b) {
                $lines[] = '  ! ' . $b;
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $a */
    private function countsText(array $a): string
    {
        $parts = [];
        if (! empty($a['children'])) {
            $parts[] = count($a['children']) . ' Sub-product(s) (parent_id)';
        }
        foreach ($a['pointers'] ?? [] as $label => $ids) {
            $parts[] = count($ids) . " in {$label}";
        }

        return $parts === [] ? ': nothing of the organisation points at it yet' : ': moves ' . implode(', ', $parts);
    }

    /** @param list<int> $ids */
    private function idList(array $ids): string
    {
        $shown = array_slice($ids, 0, 12);

        return implode(', ', $shown) . (count($ids) > 12 ? ', ... +' . (count($ids) - 12) : '');
    }

    // ------------------------------------------------------------------ the census: row counts and checksums, so a run and its rollback can be PROVEN

    /**
     * The tables a run can touch: the master, the Portfolio offers and every table of the registry that holds a `product_master_id`.
     *
     * @return list<string>
     */
    public function censusTables(): array
    {
        $tables = [self::MASTER, self::OFFERS];
        foreach (ProductPointers::toMaster() as $e) {
            $tables[] = $e['table'];
        }

        return array_values(array_unique($tables));
    }

    /**
     * For each table: how many rows it has, ONE checksum over every column of every row (in id order, every value as text), how many rows each organisation has, and the same
     * count and checksum for the rows the run touches (`touched`) and for all the others (`untouched`). A row the run MADE is in neither, so after a run the `untouched` figures must
     * equal the ones before it, and after a rollback every table's figures must equal the ones before the run, byte for byte. Only SELECT statements.
     *
     * @param array<string, array<int|string, mixed>> $touched table => [id => anything]: the rows the run touches (the keys of the before-image's `tables`)
     * @param array<string, list<int>> $made table => the ids of the rows the run made
     * @return array<string, array{rows: int, checksum: string, byOrganisation: array<string, int>, touched: array{rows: int, checksum: string}, untouched: array{rows: int, checksum: string}}>
     */
    public function census(array $touched = [], array $made = []): array
    {
        $out = [];
        foreach ($this->censusTables() as $table) {
            if (! $this->usable($table, ['id'])) {
                continue;
            }
            $tenantColumn = $this->usable($table, ['sub_institute_id']) ? 'sub_institute_id' : null;
            $all = hash_init('md5');
            $hit = hash_init('md5');
            $rest = hash_init('md5');
            $n = ['rows' => 0, 'touched' => 0, 'untouched' => 0];
            $by = [];
            $last = 0;
            $mine = $touched[$table] ?? [];
            $new = array_flip(array_map('intval', $made[$table] ?? []));
            while (true) {
                $chunk = $this->db->table($table)->where('id', '>', $last)->orderBy('id')->limit(500)->get();
                if ($chunk->isEmpty()) {
                    break;
                }
                foreach ($chunk as $row) {
                    $line = json_encode(array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                    $id = (int) $row->id;
                    $last = $id;
                    $n['rows']++;
                    hash_update($all, $line);
                    if (isset($mine[$id])) {
                        $n['touched']++;
                        hash_update($hit, $line);
                    } elseif (! isset($new[$id])) {
                        $n['untouched']++;
                        hash_update($rest, $line);
                    }
                    if ($tenantColumn !== null) {
                        $k = $row->{$tenantColumn} === null ? 'NULL' : (string) $row->{$tenantColumn};
                        $by[$k] = ($by[$k] ?? 0) + 1;
                    }
                }
            }
            ksort($by);
            $out[$table] = [
                'rows' => $n['rows'], 'checksum' => hash_final($all), 'byOrganisation' => $by,
                'touched' => ['rows' => $n['touched'], 'checksum' => hash_final($hit)], 'untouched' => ['rows' => $n['untouched'], 'checksum' => hash_final($rest)],
            ];
        }

        return $out;
    }

    /**
     * The census as text.
     *
     * @param array<string, array<string, mixed>> $census
     * @return list<string>
     */
    public function describeCensus(array $census, string $title): array
    {
        $lines = [$title];
        foreach ($census as $table => $c) {
            $by = [];
            foreach ($c['byOrganisation'] as $k => $n) {
                $by[] = "{$k}:{$n}";
            }
            $lines[] = sprintf('  %-28s rows %6d  md5 %s  by organisation %s', $table, $c['rows'], $c['checksum'], $by === [] ? '-' : implode(' ', $by));
            if ($c['touched']['rows'] > 0) {
                $lines[] = sprintf('  %-28s   the %d row(s) the run touches: md5 %s; the other %d row(s): md5 %s', '', $c['touched']['rows'], $c['touched']['checksum'], $c['untouched']['rows'], $c['untouched']['checksum']);
            }
        }

        return $lines;
    }

    /**
     * What the plan would change, by table and by organisation (the numbers a person compares with the census after the run).
     *
     * @param array<string, mixed> $plan
     * @param array<string, array<string, mixed>> $census the census before
     * @return list<string>
     */
    public function describeChanges(array $plan, array $census): array
    {
        $made = 0;
        $stamped = 0;
        $mayDelete = 0;
        $parent = [];      // organisation => rows whose parent_id changes
        $pointer = [];     // "table.column" => organisation => rows
        $offers = [];
        foreach ($plan['orgs'] as $tenant => $org) {
            foreach ($org['actions'] as $a) {
                switch ($a['type']) {
                    case 'create':
                    case 'reuse':
                    case 'merge':
                        $made += $a['type'] === 'create' ? 1 : 0;
                        $mayDelete += $a['type'] === 'merge' ? 1 : 0;
                        $parent[$tenant] = ($parent[$tenant] ?? 0) + count($a['children']);
                        foreach ($a['pointers'] as $label => $ids) {
                            $pointer[$label][$tenant] = ($pointer[$label][$tenant] ?? 0) + count($ids);
                        }
                        break;
                    case 'stamp':
                    case 'stamp_row':
                        $stamped++;
                        if (! empty($a['offer'])) {
                            $offers[(int) $a['offer']] = true;
                        }
                        break;
                    case 'offers':
                        foreach ($a['ids'] as $id) {
                            $offers[(int) $id] = true;
                        }
                        break;
                }
            }
        }
        $before = $census[self::MASTER]['rows'] ?? 0;
        $lines = ['WHAT THE APPLY WOULD CHANGE (by table and by organisation; every table or column not named here is not written)'];
        $lines[] = sprintf('  %-28s rows %d -> %d (%d Product(s) made, up to %d shared row(s) deleted)', self::MASTER, $before, $before + $made, $made, $mayDelete);
        $lines[] = sprintf('  %-28s   sub_institute_id changes (organisation 0 -> owner %d): %d row(s)', '', $plan['owner'], $stamped);
        foreach ($parent as $tenant => $n) {
            $lines[] = sprintf('  %-28s   parent_id changes, organisation %d: %d row(s)', '', $tenant, $n);
        }
        $offerRows = $census[self::OFFERS]['rows'] ?? 0;
        $lines[] = sprintf('  %-28s rows %d -> %d; sub_institute_id changes (no organisation -> owner %d): %d row(s)', self::OFFERS, $offerRows, $offerRows, $plan['owner'], count($offers));
        foreach (ProductPointers::toMaster() as $e) {
            if ($e['table'] === self::MASTER) {
                continue;
            }
            $parts = [];
            foreach ($pointer[$e['table'] . '.' . $e['column']] ?? [] as $tenant => $n) {
                $parts[] = "organisation {$tenant}: {$n} row(s)";
            }
            $rows = $census[$e['table']]['rows'] ?? 0;
            $lines[] = sprintf('  %-28s rows %d -> %d; %s changes: %s', $e['table'], $rows, $rows, $e['column'], $parts === [] ? 'none' : implode(', ', $parts));
        }
        $lines[] = '  (a row\'s other columns, `updated_at` included, are never written: the apply refuses a row the before-image does not name)';

        return $lines;
    }

    /**
     * Whether two censuses are the same, table by table: row count and the checksum of all rows.
     *
     * @param array<string, array<string, mixed>> $a
     * @param array<string, array<string, mixed>> $b
     * @return list<string> what differs, empty when the two are byte-identical
     */
    public static function censusDifferences(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $table) {
            if (! isset($a[$table]) || ! isset($b[$table])) {
                $out[] = "{$table}: not in both censuses";

                continue;
            }
            if ($a[$table]['rows'] !== $b[$table]['rows'] || $a[$table]['checksum'] !== $b[$table]['checksum']) {
                $out[] = "{$table}: rows {$a[$table]['rows']} -> {$b[$table]['rows']}, md5 {$a[$table]['checksum']} -> {$b[$table]['checksum']}";
            }
        }

        return $out;
    }

    /**
     * After a run: the rows the run did not touch (and did not make) must be the ones before it.
     *
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @return list<string>
     */
    public static function untouchedDifferences(array $before, array $after): array
    {
        $out = [];
        foreach ($before as $table => $b) {
            $a = $after[$table] ?? null;
            if ($a === null || $a['untouched']['rows'] !== $b['untouched']['rows'] || $a['untouched']['checksum'] !== $b['untouched']['checksum']) {
                $out[] = "{$table}: the rows the run did not touch are not the ones from before (" . $b['untouched']['rows'] . ' rows, md5 ' . $b['untouched']['checksum'] . ' -> ' . ($a === null ? 'gone' : $a['untouched']['rows'] . ' rows, md5 ' . $a['untouched']['checksum']) . ')';
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ the before-image

    /**
     * The rows a plan will touch, in full, and the columns it will change in each, read BEFORE the first write.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $meta what the command knows about this run
     * @return array<string, mixed>
     */
    public function beforeImage(array $plan, array $meta): array
    {
        $ids = [];       // table => [id => true]
        $columns = [];   // table => [id => [column => true]]
        $note = function (string $table, int $id, string $column) use (&$ids, &$columns): void {
            $ids[$table][$id] = true;
            $columns[$table][$id][$column] = true;
        };

        foreach ($plan['orgs'] as $org) {
            foreach ($org['actions'] as $a) {
                switch ($a['type']) {
                    case 'create':
                    case 'reuse':
                    case 'merge':
                        foreach ($a['children'] as $id) {
                            $note(self::MASTER, (int) $id, 'parent_id');
                        }
                        foreach ($a['pointers'] as $label => $rowIds) {
                            [$table, $column] = explode('.', $label, 2);
                            foreach ($rowIds as $id) {
                                $note($table, (int) $id, $column);
                            }
                        }
                        if ($a['type'] === 'merge') {
                            $note(self::MASTER, (int) $a['from'], 'sub_institute_id');
                        }
                        break;
                    case 'stamp':
                    case 'stamp_row':
                        $note(self::MASTER, (int) $a['from'], 'sub_institute_id');
                        if (! empty($a['offer'])) {
                            $note(self::OFFERS, (int) $a['offer'], 'sub_institute_id');
                        }
                        break;
                    case 'offers':
                        foreach ($a['ids'] as $id) {
                            $note(self::OFFERS, (int) $id, 'sub_institute_id');
                        }
                        break;
                }
            }
        }

        $tables = [];
        foreach ($ids as $table => $rows) {
            $tables[$table] = [];
            foreach (array_chunk(array_keys($rows), self::CHUNK) as $chunk) {
                foreach ($this->db->table($table)->whereIn('id', $chunk)->get() as $row) {
                    $tables[$table][(int) $row->id] = (array) $row;
                }
            }
            ksort($tables[$table]);
        }
        $cols = [];
        foreach ($columns as $table => $rows) {
            foreach ($rows as $id => $set) {
                $cols[$table][$id] = array_keys($set);
            }
            ksort($cols[$table]);
        }

        $orgs = [];
        foreach ($plan['orgs'] as $tenant => $org) {
            $orgs[$tenant] = ['state' => 'pending', 'actions' => array_map(fn (array $a): array => array_diff_key($a, ['copy' => 1]), $org['actions'])];
        }

        return [
            'format' => self::FORMAT, 'meta' => $meta, 'owner' => (int) $plan['owner'], 'orgs' => $orgs,
            'tables' => $tables, 'columns' => $cols, 'created' => [], 'deleted' => [], 'changes' => [], 'kept' => [],
        ];
    }

    /**
     * Writes a before-image file. The first write refuses to overwrite a file (a before-image is the way back); the updates after each organisation replace it, atomically.
     *
     * @param array<string, mixed> $image
     */
    public static function write(string $path, array $image, bool $replace = false): void
    {
        if (! $replace && file_exists($path)) {
            throw new \RuntimeException("Refusing to overwrite {$path}: a before-image is the way back.");
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("The folder {$dir} cannot be created.");
        }
        $json = json_encode($image, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$tmp}.");
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Could not move the before-image into place at {$path}.");
        }
    }

    /** @return ?array<string, mixed> a before-image, or null with the reason in $problem */
    public static function read(string $path, ?string &$problem = null): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $problem = "The before-image {$path} does not exist or cannot be read.";

            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $problem = "The before-image {$path} is not valid JSON: " . $e->getMessage();

            return null;
        }
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! isset($data['tables'], $data['columns'], $data['owner']) || ! is_array($data['tables'])) {
            $problem = "The file {$path} is not a before-image written by product-master:isolate-tenants (format " . self::FORMAT . ').';

            return null;
        }

        return $data;
    }

    // ------------------------------------------------------------------ the apply

    /**
     * Does what the plan says: every other organisation first, one transaction each, then the owner. The before-image file is written BEFORE the first write and
     * completed after each organisation, so it always says what is done.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $image the result of beforeImage()
     * @return array{done: array<int, string>, failed: array<int, string>, kept: list<string>, image: array<string, mixed>}
     */
    public function apply(array $plan, array $image, string $path): array
    {
        $this->known = [];
        foreach ($image['columns'] as $table => $rows) {
            foreach ($rows as $id => $cols) {
                foreach ($cols as $col) {
                    $this->known["{$table}#{$id}#{$col}"] = true;
                }
            }
        }
        self::write($path, $image);
        $done = [];
        $failed = [];
        foreach ($plan['orgs'] as $tenant => $org) {
            $log = ['created' => [], 'deleted' => [], 'changes' => [], 'kept' => []];
            try {
                $this->db->transaction(function () use ($org, $plan, &$log): void {
                    $this->applyOrganisation((int) $plan['owner'], $org, $log);
                });
            } catch (\Throwable $e) {
                $failed[(int) $tenant] = $e->getMessage();
                $image['orgs'][$tenant]['state'] = 'failed: ' . $e->getMessage();
                self::write($path, $image, true);

                continue;
            }
            $done[(int) $tenant] = $this->summary($log);
            $image['orgs'][$tenant]['state'] = 'applied';
            $image['created'] = array_merge($image['created'], $log['created']);
            $image['deleted'] = array_merge($image['deleted'], $log['deleted']);
            $image['kept'] = array_merge($image['kept'], $log['kept']);
            foreach ($log['changes'] as $table => $rows) {
                foreach ($rows as $id => $cols) {
                    foreach ($cols as $col => $change) {
                        $image['changes'][$table][$id][$col] = $change;
                    }
                }
            }
            self::write($path, $image, true);
        }

        return ['done' => $done, 'failed' => $failed, 'kept' => array_values(array_map(fn (array $k): string => $k['why'], $image['kept'])), 'image' => $image];
    }

    /** @param array<string, mixed> $log */
    private function summary(array $log): string
    {
        $rows = 0;
        foreach ($log['changes'] as $byId) {
            $rows += count($byId);
        }

        return sprintf('%d row(s) changed, %d Product(s) made, %d shared row(s) deleted, %d kept', $rows, count($log['created']), count($log['deleted']), count($log['kept']));
    }

    /**
     * @param array<string, mixed> $org
     * @param array<string, mixed> $log
     */
    private function applyOrganisation(int $owner, array $org, array &$log): void
    {
        $tenant = (int) $org['tenant'];
        // Rows stamped for the owner first (a shared Sub-product follows its parent), then the Products.
        $actions = $org['actions'];
        usort($actions, fn (array $a, array $b): int => $this->rank($a['type']) <=> $this->rank($b['type']));

        foreach ($actions as $a) {
            switch ($a['type']) {
                case 'stamp_row':
                    $this->stampRow($tenant, (int) $a['from'], $log);
                    if (! empty($a['offer'])) {
                        $this->stampOffers($tenant, [(int) $a['offer']], $log);
                    }
                    break;
                case 'offers':
                    $this->stampOffers($tenant, $a['ids'], $log);
                    break;
                case 'create':
                    $this->requireShared((int) $a['from']);
                    $to = $this->createCopy($a['copy'], $log);
                    $this->repoint($tenant, (int) $a['from'], $to, $log);
                    $this->assertNoneLeft($tenant, (int) $a['from']);
                    break;
                case 'reuse':
                    $this->requireShared((int) $a['from']);
                    $this->repoint($tenant, (int) $a['from'], (int) $a['to'], $log);
                    $this->assertNoneLeft($tenant, (int) $a['from']);
                    break;
                case 'stamp':
                    $this->requireShared((int) $a['from']);
                    $foreign = $this->foreignNow((int) $a['from'], $owner);
                    if ($foreign > 0) {
                        $log['kept'][] = ['table' => self::MASTER, 'id' => (int) $a['from'], 'why' => "shared Product {$a['from']} '{$a['name']}' was kept: {$foreign} record(s) of another organisation still point at it"];
                        break;
                    }
                    $this->stampRow($tenant, (int) $a['from'], $log);
                    break;
                case 'merge':
                    $this->requireShared((int) $a['from']);
                    $this->repoint($tenant, (int) $a['from'], (int) $a['to'], $log);
                    $this->assertNoneLeft($tenant, (int) $a['from']);
                    $left = $this->totalNow((int) $a['from']);
                    if ($left > 0) {
                        $log['kept'][] = ['table' => self::MASTER, 'id' => (int) $a['from'], 'why' => "shared Product {$a['from']} '{$a['name']}' was kept: {$left} record(s) still point at it"];
                        break;
                    }
                    $this->deleteShared((int) $a['from'], $log);
                    break;
            }
        }
    }

    private function rank(string $type): int
    {
        return match ($type) {
            'stamp_row', 'offers' => 0,
            default => 1,
        };
    }

    /** The before-image names every column the apply writes: a row the plan did not see (it appeared after the dry run) stops that organisation, and its transaction is taken back. */
    private function assertKnown(string $table, int $id, string $column): void
    {
        if (! isset($this->known["{$table}#{$id}#{$column}"])) {
            throw new \RuntimeException("{$table} row {$id} ({$column}) is not in the before-image: the data changed after the plan was made, run the dry run again.");
        }
    }

    private function requireShared(int $id): void
    {
        if (! $this->db->table(self::MASTER)->where('id', $id)->where('sub_institute_id', 0)->exists()) {
            throw new \RuntimeException("The shared row {$id} is no longer one of organisation 0: the plan is out of date, run the dry run again.");
        }
    }

    /**
     * @param array<string, mixed> $copy
     * @param array<string, mixed> $log
     */
    private function createCopy(array $copy, array &$log): int
    {
        $now = now();
        $id = (int) $this->db->table(self::MASTER)->insertGetId($copy + ['created_at' => $now, 'updated_at' => $now]);
        $log['created'][] = ['table' => self::MASTER, 'id' => $id, 'org' => (int) $copy['sub_institute_id'], 'key' => (string) $copy['product_key']];

        return $id;
    }

    /** @param array<string, mixed> $log */
    private function stampRow(int $owner, int $id, array &$log): void
    {
        $this->assertKnown(self::MASTER, $id, 'sub_institute_id');
        $n = $this->db->table(self::MASTER)->where('id', $id)->where('sub_institute_id', 0)->update(['sub_institute_id' => $owner]);
        if ($n === 1) {
            $log['changes'][self::MASTER][$id]['sub_institute_id'] = ['from' => 0, 'to' => $owner];
        }
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $log
     */
    private function stampOffers(int $owner, array $ids, array &$log): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->table(self::OFFERS)->whereIn('id', $chunk)->whereNull('sub_institute_id')->pluck('id');
            if ($rows->isEmpty()) {
                continue;
            }
            foreach ($rows as $id) {
                $this->assertKnown(self::OFFERS, (int) $id, 'sub_institute_id');
            }
            $this->db->table(self::OFFERS)->whereIn('id', $rows->all())->whereNull('sub_institute_id')->update(['sub_institute_id' => $owner]);
            foreach ($rows as $id) {
                $log['changes'][self::OFFERS][(int) $id]['sub_institute_id'] = ['from' => null, 'to' => $owner];
            }
        }
    }

    /**
     * Moves every pointer of ONE organisation from one master row to another: the master rows' own parent_id and every column of the registry. Only the pointer column
     * is written.
     *
     * @param array<string, mixed> $log
     */
    private function repoint(int $tenant, int $from, int $to, array &$log): void
    {
        foreach ($this->pointerColumns() as [$table, $column, $scope]) {
            if (! $this->usable($table, ['id', $column, $scope])) {
                continue;
            }
            $ids = $this->db->table($table)->where($scope, $tenant)->where($column, $from)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            foreach ($ids as $id) {
                $this->assertKnown($table, $id, $column);
            }
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $n = $this->db->table($table)->whereIn('id', $chunk)->where($scope, $tenant)->where($column, $from)->update([$column => $to]);
                if ($n !== count($chunk)) {
                    throw new \RuntimeException("{$table}.{$column}: " . count($chunk) . " row(s) held the shared row {$from} and {$n} were moved: the data changed while the command ran.");
                }
                foreach ($chunk as $id) {
                    $log['changes'][$table][$id][$column] = ['from' => $from, 'to' => $to];
                }
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> [table, pointer column, organisation column] of every pointer at a master row, the master's own parent_id included */
    private function pointerColumns(): array
    {
        $out = [];
        foreach (ProductPointers::toMaster() as $e) {
            if (is_string($e['scope'])) {
                $out[] = [$e['table'], $e['column'], $e['scope']];
            }
        }
        $out[] = [self::MASTER, 'parent_id', 'sub_institute_id'];

        return $out;
    }

    private function assertNoneLeft(int $tenant, int $from): void
    {
        foreach ($this->pointerColumns() as [$table, $column, $scope]) {
            if (! $this->usable($table, ['id', $column, $scope])) {
                continue;
            }
            $left = $this->db->table($table)->where($scope, $tenant)->where($column, $from)->count();
            if ($left > 0) {
                throw new \RuntimeException("{$table}.{$column}: {$left} row(s) of organisation {$tenant} still point at shared row {$from}.");
            }
        }
    }

    /** How many records of organisations other than $owner (or of no organisation) point at a shared row now. */
    private function foreignNow(int $zid, int $owner): int
    {
        $n = 0;
        foreach ($this->pointerColumns() as [$table, $column, $scope]) {
            if (! $this->usable($table, ['id', $column, $scope])) {
                continue;
            }
            $q = $this->db->table($table)->where($column, $zid)->where(fn ($w) => $w->whereNull($scope)->orWhere($scope, '!=', $owner));
            if ($table === self::MASTER && $column === 'parent_id') {
                // a shared Sub-product that is itself waiting to be stamped does not count: it is the owner's in this same run
                $q->where('sub_institute_id', '!=', 0);
            }
            $n += $q->count();
        }

        return $n;
    }

    /** How many records of any organisation point at a shared row now. */
    private function totalNow(int $zid): int
    {
        $n = 0;
        foreach ($this->pointerColumns() as [$table, $column, $scope]) {
            if ($this->usable($table, ['id', $column, $scope])) {
                $n += $this->db->table($table)->where($column, $zid)->count();
            }
        }

        return $n;
    }

    /** @param array<string, mixed> $log */
    private function deleteShared(int $id, array &$log): void
    {
        $this->db->table(self::MASTER)->where('id', $id)->where('sub_institute_id', 0)->delete();
        $log['deleted'][] = ['table' => self::MASTER, 'id' => $id];
    }

    // ------------------------------------------------------------------ the way back

    /**
     * Puts the changed columns back as the before-image says, removes the Products the run made and brings back the shared rows it deleted, all or nothing.
     *
     * @param array<string, mixed> $image
     * @return array{restored: int, removed: int, reinserted: int}
     *
     * @throws ProductMasterInvalid 409 a row was changed since the run, a record points at a Product the run made, or a row differs after it was put back
     */
    public function rollback(array $image): array
    {
        $conflicts = [];
        $deleted = [];
        foreach ($image['deleted'] ?? [] as $d) {
            $deleted[$d['table'] . '#' . $d['id']] = true;
            if ($this->db->table($d['table'])->where('id', (int) $d['id'])->exists()) {
                $conflicts[] = "{$d['table']} row {$d['id']} was deleted by the run but a row with that id exists again";
            }
        }
        // a column is put back only while it still holds what the run wrote
        foreach ($image['columns'] as $table => $rows) {
            foreach ($rows as $id => $cols) {
                if (isset($deleted[$table . '#' . $id])) {
                    continue;
                }
                $now = $this->db->table($table)->where('id', (int) $id)->first();
                if ($now === null) {
                    $conflicts[] = "{$table} row {$id} no longer exists";

                    continue;
                }
                foreach ($cols as $col) {
                    $change = $image['changes'][$table][$id][$col] ?? null;
                    if ($change !== null && (string) ($now->{$col} ?? '') !== (string) ($change['to'] ?? '')) {
                        $conflicts[] = "{$table} row {$id}: {$col} is " . var_export($now->{$col}, true) . ' now, and the run set it to ' . var_export($change['to'], true);
                    }
                }
            }
        }
        foreach ($image['created'] ?? [] as $c) {
            if (! $this->db->table($c['table'])->where('id', (int) $c['id'])->exists()) {
                $conflicts[] = "the Product {$c['id']} made by the run no longer exists";
            }
        }
        if ($conflicts !== []) {
            throw new ProductMasterInvalid(['rows' => array_slice($conflicts, 0, 20)], 409, 'Nothing was undone: ' . count($conflicts) . ' row(s) changed after the run, and the old values would overwrite those changes. First: ' . $conflicts[0]);
        }

        $result = ['restored' => 0, 'removed' => 0, 'reinserted' => 0];
        $this->db->transaction(function () use ($image, $deleted, &$result): void {
            // the shared rows the run deleted come back first, with their ids
            foreach ($image['deleted'] ?? [] as $d) {
                $row = $image['tables'][$d['table']][$d['id']] ?? null;
                if ($row === null) {
                    throw new ProductMasterInvalid(['rows' => ["the before-image has no copy of {$d['table']} row {$d['id']}"]], 409, "The undo was taken back: the before-image has no copy of {$d['table']} row {$d['id']}.");
                }
                $this->db->table($d['table'])->insert($row);
                $result['reinserted']++;
            }
            foreach ($image['columns'] as $table => $rows) {
                foreach ($rows as $id => $cols) {
                    if (isset($deleted[$table . '#' . $id])) {
                        continue;
                    }
                    $before = $image['tables'][$table][$id] ?? null;
                    if ($before === null) {
                        throw new ProductMasterInvalid(['rows' => ["the before-image has no copy of {$table} row {$id}"]], 409, "The undo was taken back: the before-image has no copy of {$table} row {$id}.");
                    }
                    $set = [];
                    foreach ($cols as $col) {
                        $set[$col] = $before[$col] ?? null;
                    }
                    $this->db->table($table)->where('id', (int) $id)->update($set);
                    $result['restored']++;
                }
            }
            // the Products the run made go last, and only when nothing points at them any more
            foreach ($image['created'] ?? [] as $c) {
                $left = $this->totalNow((int) $c['id']);
                if ($left > 0) {
                    throw new ProductMasterInvalid(['rows' => ["{$left} record(s) point at the Product {$c['id']} the run made"]], 409, "The undo was taken back: {$left} record(s) made since the run point at the Product {$c['id']} ('{$c['key']}' of organisation {$c['org']}), so it cannot be removed.");
                }
                $this->db->table($c['table'])->where('id', (int) $c['id'])->delete();
                $result['removed']++;
            }
            // byte for byte: every changed column is read again and compared with the saved row
            foreach ($image['columns'] as $table => $rows) {
                foreach ($rows as $id => $cols) {
                    $now = $this->db->table($table)->where('id', (int) $id)->first();
                    $before = $image['tables'][$table][$id] ?? [];
                    foreach ($cols as $col) {
                        if ($now === null || (string) ($now->{$col} ?? '') !== (string) ($before[$col] ?? '') || (($now->{$col} ?? null) === null) !== (($before[$col] ?? null) === null)) {
                            throw new ProductMasterInvalid(['rows' => ["{$table} row {$id}: {$col} differs from the saved row after it was put back"]], 409, "The undo was taken back: {$table} row {$id}: {$col} differs from the saved row after it was put back.");
                        }
                    }
                }
            }
        });

        return $result;
    }

    // ------------------------------------------------------------------ plumbing

    private function hasTable(string $table): bool
    {
        return $this->present[$table] ??= Schema::connection($this->db->getName())->hasTable($table);
    }

    /** This host has the table and every one of the columns. @param list<string> $columns */
    private function usable(string $table, array $columns): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }
        foreach ($columns as $column) {
            $this->present["{$table}.{$column}"] ??= ColumnExists::has($table, $column, $this->db);
            if (! $this->present["{$table}.{$column}"]) {
                return false;
            }
        }

        return true;
    }
}
