<?php

namespace App\Domain\ProductMaster;

use App\Services\Events\EventRecorder;
use App\Support\ColumnExists;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a catalogue item and a Portfolio Sub-product that a person says are the SAME product into ONE record (slice 15, track P2-D;
 * Docs/agentic-crm/slices/S15-product-portfolio-merge.md sections 3 and 6). One pair, one transaction, and nothing is ever deleted.
 *
 * Before: the Sub-product's master row holds an offer (what it is) and no catalogue pointer; the item's master row holds the catalogue row (what it costs) and no offer.
 * After: ONE master row, the Sub-product's, holds both pointers. The item's master row becomes the HUSK: archived, origin `merged`, no pointers, kept for ever, and
 * g2g_product_match_decisions says item -> survivor, so any old reference to the husk can be followed. The CRM row (eleven tables point at it: quote, order and invoice
 * lines, price books, ...), the offer row, the husk and every claim are never deleted and never change identity, so not one quote, order, invoice, price-book line or
 * signal can be lost.
 *
 * In this order, inside ONE transaction, under row locks (the two master rows in id order, the catalogue row, the offer row):
 *   1. validate: both rows are the organisation's OWN (a shared row, or another organisation's, is "not one of yours": 404); the survivor is an active `sub_product`
 *      with an offer row and NO catalogue pointer; the item is a live `item` (active, with a catalogue row that is not in the Recycle Bin); the item's Product is the
 *      survivor's Product or the item has none. A pair already merged is a no-op that writes nothing.
 *   2. the checker (ProductIntegrity) must find NO error in the organisation before anything is written, and the census of the pointer tables is taken;
 *   3. free the unique `crm_product_id` slot: the item's master row becomes the husk (status archived, origin merged, crm_product_id and offer_row_id NULL);
 *   4. set the survivor's `crm_product_id`;
 *   5. mirror the kept name (the Portfolio's by default; a person may keep the catalogue's) and the Product to the master row, the offer row and the CRM row with
 *      ProductMasterSync::mirror (P2-B2): one record, one name;
 *   6. repoint every `product_master_id` that holds the husk's id to the survivor's: the nine columns of ProductPointers::toMaster() EXCEPT crm_products.product_master_id,
 *      which names the PRODUCT an item sits under and never holds an item's id. Where g2g_product_claims would collide on (organisation, product, claim hash) the
 *      SURVIVOR's claim stays and the duplicate is retired (status `retired`, still pointing at the husk, never deleted). The refs left at the husk are counted: none, but
 *      the retired duplicates;
 *   7. write the decision row (`same`), with the score and the signals the suggester saw;
 *   8. run the checker again: any error takes the whole merge back; and the census again: every line count and sum must be exactly what it was, else the merge is
 *      taken back. (Under MariaDB's REPEATABLE READ both reads see one snapshot, so another user's concurrent quote line cannot make the comparison lie.)
 * After the commit the audit event is recorded (an unrecordable event is reported in the result and the log, never undone: the merge is a fact).
 *
 * A claim is the one thing a merge can make STRICTER, never looser: if the survivor already carried the same sentence the husk's duplicate is retired, even when the husk's
 * was approved and the survivor's is not. The outreach gate then treats the sentence as unapproved for the merged product until a person approves it again. That is the
 * safe direction, and the count of retired-but-approved claims is in the result and in the event.
 *
 * `decideDifferent` is the other answer: it writes only the decision row, so the pair is not offered again.
 *
 * Built on the query builder and an explicit connection, MariaDB 10.1 safe (plain UPDATE / SELECT ... FOR UPDATE, no JSON, no window functions, no CTEs).
 */
final class ProductMerger
{
    public const NAME_PORTFOLIO = 'portfolio';

    public const NAME_CATALOGUE = 'catalogue';

    public const SAME = 'same';

    public const DIFFERENT = 'different';

    /** The event types written after a commit (registered in App\Services\Events\EventCatalogue::SHIPPED). */
    public const EVENT_MERGED = 'product.merged';

    public const EVENT_DECIDED = 'product.match_decided';

    public const EVENT_UNDONE = 'product.merge_undone';

    /** The origin of a husk. */
    public const ORIGIN_MERGED = 'merged';

    /**
     * A registered pointer at a master row that the merge leaves alone on purpose: "table.column" => the reason. The scan test (tests/Unit/ProductMergerScanTest.php)
     * wants a sentence of at least 30 characters, and fails when a registry row has no decision.
     */
    private const LEFT_ALONE = [
        'crm_products.product_master_id' => 'It names the PRODUCT (level 1) a catalogue item sits under and never holds the id of an item, so there is nothing to move; moving it would point an item at a Sub-product, which the checker rejects (I1).',
    ];

    /**
     * A pointer that sits in a UNIQUE key: "table.column" => the OTHER columns of that key. A row of the husk that carries the same values in them as a row of the survivor
     * is not moved (it would break the key) and is retired instead. The scan test fails when a migration has such a key that is not listed here.
     */
    private const UNIQUE = [
        'g2g_product_claims.product_master_id' => ['sub_institute_id', 'claim_hash'],
    ];

    private const CHUNK = 400;

    private readonly Connection $db;

    /** @var array<string, bool> */
    private array $present = [];

    public function __construct(private readonly ?string $connection = null)
    {
        $this->db = DB::connection($connection);
    }

    // ------------------------------------------------------------------ what the merge does with every registered pointer

    /**
     * @return array<string, array{moves: bool, unique: list<string>, reason: ?string}> for each of the ten pointers at a master row
     */
    public static function handling(): array
    {
        $out = [];
        foreach (ProductPointers::toMaster() as $e) {
            $key = $e['table'] . '.' . $e['column'];
            $reason = self::LEFT_ALONE[$key] ?? null;
            $out[$key] = ['moves' => $reason === null, 'unique' => self::UNIQUE[$key] ?? [], 'reason' => $reason];
        }

        return $out;
    }

    /** @return list<string> "table.column" of the pointers the merge repoints, in registry order */
    public static function repointed(): array
    {
        return array_keys(array_filter(self::handling(), fn (array $how): bool => $how['moves']));
    }

    /** @return array<string, string> */
    public static function leftAlone(): array
    {
        return self::LEFT_ALONE;
    }

    /** @return array<string, list<string>> */
    public static function uniqueKeys(): array
    {
        return self::UNIQUE;
    }

    // ------------------------------------------------------------------ the merge

    /**
     * Makes the item and the Sub-product ONE record. Re-running a merge that is already done writes nothing.
     *
     * @param array{sub_institute_id: int, user_id?: int|null, user?: object} $identity the caller; without `user` it is the command (a person's map file applied by the owner)
     * @param string $nameKept `portfolio` (the Sub-product's name; the default) or `catalogue` (the item's name)
     * @return array<string, mixed>
     *
     * @throws ProductMasterInvalid 404 not one of this organisation's; 403 not an administrator; 422 not a valid pair; 409 a clash, or the checker or the census says no
     */
    public function merge(array $identity, int $itemMasterId, int $survivorMasterId, string $nameKept = self::NAME_PORTFOLIO): array
    {
        [$tenant, $actor] = $this->caller($identity);
        $this->guardInput($itemMasterId, $survivorMasterId, $nameKept);
        $this->requireTables();
        $suggester = new ProductMatchSuggester($this->connection);

        $result = $this->db->transaction(function () use ($tenant, $actor, $itemMasterId, $survivorMasterId, $nameKept, $suggester): array {
            $ctx = $this->context($tenant, $itemMasterId, $survivorMasterId, true);
            if ($ctx['alreadyMerged']) {
                return ['result' => 'already_merged'] + $this->describe($ctx, $nameKept);
            }

            $name = $this->keptName($ctx, $nameKept);
            $evidence = $suggester->pair($tenant, $itemMasterId, $survivorMasterId);

            $integrity = new ProductIntegrity($this->connection, $tenant);
            $pre = $integrity->check();
            if ($pre['errors'] > 0) {
                throw $this->integrityRefusal("The product checker finds {$pre['errors']} error(s) in this organisation, so nothing was merged. Fix them first (php artisan product-master:check --tenant={$tenant}).", $pre['findings'], 409);
            }
            $censusBefore = $integrity->census();
            $refs = $this->huskRefs($tenant, $itemMasterId);
            $now = now();

            // 3. the husk: this frees the unique (organisation, crm_product_id) slot, so the survivor can take it
            $husk = $this->db->table('g2g_product_master')->where('id', $itemMasterId)->where('sub_institute_id', $tenant)->update(
                ['status' => ProductMaster::STATUS_ARCHIVED, 'origin' => self::ORIGIN_MERGED, 'crm_product_id' => null, 'offer_row_id' => null, 'updated_at' => $now] + $this->by($actor)
            );
            if ($husk !== 1) {
                throw ProductMasterInvalid::field('itemMasterId', 'That catalogue item changed while it was being merged. Nothing was merged; try again.', 409);
            }

            // 4. the survivor takes the catalogue row (the WHERE says "still without one": a second merge into the same Sub-product finds it taken)
            $taken = $this->db->table('g2g_product_master')->where('id', $survivorMasterId)->where('sub_institute_id', $tenant)->whereNull('crm_product_id')->update(
                ['crm_product_id' => $ctx['crmId'], 'updated_at' => $now] + $this->by($actor)
            );
            if ($taken !== 1) {
                throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product was given a catalogue item by someone else. Nothing was merged; try again.', 409);
            }

            // 5. one name and one Product in the master row, the offer and the catalogue row, in the same transaction
            $copies = (new ProductMasterSync($this->connection, true, $actor))->mirror($tenant, $survivorMasterId, ['name' => $name, 'parent_id' => $ctx['productId']]);

            // 6. every pointer at the husk now points at the survivor
            $moved = $this->repoint($tenant, $itemMasterId, $survivorMasterId, $refs);

            // 7. the decision (the alias of the husk)
            $decisionId = $this->writeDecision($tenant, $itemMasterId, $survivorMasterId, self::SAME, $evidence, $nameKept, $actor, $now);

            // 8. the checker, and the census: the merge is taken back unless both say nothing was lost
            $post = $integrity->check();
            if ($post['errors'] > 0) {
                throw $this->integrityRefusal("The merge was taken back: the product checker found {$post['errors']} error(s) after it.", $post['findings'], 409);
            }
            // What the census must show after: what it showed before, except that an item that had no Product now has one (one more catalogue row with a product pointer set).
            if ($ctx['itemGainsProduct'] && isset($censusBefore['tables']['crm_products']['pointers']['product_master_id']['set'])) {
                $censusBefore['tables']['crm_products']['pointers']['product_master_id']['set']++;
            }
            $differences = ProductIntegrity::differences($censusBefore, $integrity->census());
            if ($differences !== []) {
                throw new ProductMasterInvalid(['census' => array_slice($differences, 0, 10)], 409, 'The merge was taken back: a count or a sum of the quote, order, invoice or other lines would have changed (' . $differences[0] . ').');
            }

            return [
                'result' => 'merged', 'copies' => $copies, 'moved' => $moved['moved'], 'claims' => $moved['claims'], 'decisionId' => $decisionId, 'evidence' => $evidence,
                'warnings' => $post['warnings'], 'previous' => ['portfolio' => (string) $ctx['survivor']->name, 'catalogue' => (string) $ctx['crmName']],
            ] + $this->describe($ctx, $nameKept);
        });

        if ($result['result'] === 'merged') {
            $result['audit'] = $this->emit(self::EVENT_MERGED, $tenant, $actor, (int) $result['survivorMasterId'], [
                'itemMasterId' => $result['itemMasterId'], 'survivorMasterId' => $result['survivorMasterId'], 'crmProductId' => $result['crmProductId'], 'offerRowId' => $result['offerRowId'],
                'productId' => $result['productId'], 'nameKept' => $nameKept, 'name' => $result['name'], 'previous' => $result['previous'], 'moved' => $result['moved'], 'claims' => $result['claims'],
                'score' => $result['evidence']['score'] ?? null, 'signals' => $result['evidence']['signals'] ?? null, 'decisionId' => $result['decisionId'], 'via' => $actor === null ? 'command' : 'screen',
            ], self::EVENT_MERGED . ':' . $tenant . ':' . $itemMasterId . ':' . $survivorMasterId . ':' . $this->stamp());
        }

        return $result;
    }

    /**
     * "Different": writes the decision row and nothing else, so the pair is not offered again. An item already merged cannot be called different (undo the merge first).
     *
     * @param array{sub_institute_id: int, user_id?: int|null, user?: object} $identity
     * @return array<string, mixed>
     */
    public function decideDifferent(array $identity, int $itemMasterId, int $survivorMasterId): array
    {
        [$tenant, $actor] = $this->caller($identity);
        $this->guardInput($itemMasterId, $survivorMasterId, self::NAME_PORTFOLIO);
        $this->requireTables();
        $suggester = new ProductMatchSuggester($this->connection);

        $result = $this->db->transaction(function () use ($tenant, $actor, $itemMasterId, $survivorMasterId, $suggester): array {
            $rows = $this->db->table('g2g_product_master')->whereIn('id', [$itemMasterId, $survivorMasterId])->where('sub_institute_id', $tenant)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $item = $rows[$itemMasterId] ?? null;
            $survivor = $rows[$survivorMasterId] ?? null;
            if ($item === null || $survivor === null) {
                throw ProductMasterInvalid::field('itemMasterId', 'That product is not one of yours.', 404);
            }
            if ($item->kind !== ProductMaster::KIND_ITEM) {
                throw ProductMasterInvalid::field('itemMasterId', 'That is not a catalogue item.');
            }
            if ($survivor->kind !== ProductMaster::KIND_SUB_PRODUCT) {
                throw ProductMasterInvalid::field('survivorMasterId', 'That is not a Sub-product.');
            }
            $existing = $this->decision($tenant, $itemMasterId, $survivorMasterId, true);
            if ($existing !== null && $existing->decision === self::SAME) {
                throw ProductMasterInvalid::field('decision', 'These two were already merged into one record. Undo the merge first (php artisan product-master:unify --rollback=<snapshot>).', 409);
            }
            if ($existing !== null) {
                return ['result' => 'already_decided', 'decision' => self::DIFFERENT, 'itemMasterId' => $itemMasterId, 'survivorMasterId' => $survivorMasterId, 'decisionId' => (int) $existing->id];
            }
            // The evidence is for the log: a pair nobody suggested may be called different too, and then it has a score of its own to show.
            $id = $this->writeDecision($tenant, $itemMasterId, $survivorMasterId, self::DIFFERENT, $suggester->pair($tenant, $itemMasterId, $survivorMasterId), null, $actor, now());

            return ['result' => 'recorded', 'decision' => self::DIFFERENT, 'itemMasterId' => $itemMasterId, 'survivorMasterId' => $survivorMasterId, 'decisionId' => $id];
        });

        if ($result['result'] === 'recorded') {
            $result['audit'] = $this->emit(self::EVENT_DECIDED, $tenant, $actor, $survivorMasterId, [
                'itemMasterId' => $itemMasterId, 'survivorMasterId' => $survivorMasterId, 'decision' => self::DIFFERENT, 'decisionId' => $result['decisionId'],
            ], self::EVENT_DECIDED . ':' . $tenant . ':' . $itemMasterId . ':' . $survivorMasterId . ':' . $this->stamp());
        }

        return $result;
    }

    /**
     * Everything a merge of this pair would touch, found WITHOUT writing and without taking a lock: the rows the snapshot must keep, and the validation the real merge
     * will repeat. Used by the command to refuse a whole map file before the first write, and to save the rows before anything changes.
     *
     * @return array<string, mixed>
     *
     * @throws ProductMasterInvalid the same refusals as merge()
     */
    public function plan(int $tenant, int $itemMasterId, int $survivorMasterId, string $nameKept = self::NAME_PORTFOLIO): array
    {
        $this->guardInput($itemMasterId, $survivorMasterId, $nameKept);
        $this->requireTables();
        $ctx = $this->context($tenant, $itemMasterId, $survivorMasterId, false);
        $name = $ctx['alreadyMerged'] ? null : $this->keptName($ctx, $nameKept);
        $refs = $this->huskRefs($tenant, $itemMasterId);
        $touched = [
            'g2g_product_master' => [$itemMasterId, $survivorMasterId], 'crm_products' => [$ctx['crmId']], 'g2g_product_offers' => [$ctx['offerRowId']],
        ];
        foreach ($refs as $key => $ids) {
            if ($ids !== []) {
                $table = explode('.', $key)[0];
                $touched[$table] = array_values(array_unique(array_merge($touched[$table] ?? [], $ids)));
            }
        }
        $decision = $this->decision($tenant, $itemMasterId, $survivorMasterId, false);

        return $this->describe($ctx, $nameKept) + ['name' => $name, 'touched' => $touched, 'decisionId' => $decision !== null ? (int) $decision->id : null];
    }

    // ------------------------------------------------------------------ validation

    /** @param array<string, mixed> $identity @return array{0: int, 1: ?int} */
    private function caller(array $identity): array
    {
        $tenant = (int) ($identity['sub_institute_id'] ?? 0);
        if ($tenant <= 0) {
            throw ProductMasterInvalid::field('sub_institute_id', 'An organisation is needed.');
        }
        // A person is checked here as well as at the route: a merge changes what a quote can sell, so it is not left to one gate. The command has no person.
        if (isset($identity['user']) && ! ProductAccess::isAdministrator($identity)) {
            throw ProductMasterInvalid::field('decision', 'Only an administrator can confirm that two products are the same.', 403);
        }
        $actor = isset($identity['user_id']) && (int) $identity['user_id'] > 0 ? (int) $identity['user_id'] : null;

        return [$tenant, $actor];
    }

    private function guardInput(int $itemMasterId, int $survivorMasterId, string $nameKept): void
    {
        if ($itemMasterId <= 0 || $survivorMasterId <= 0) {
            throw ProductMasterInvalid::field('itemMasterId', 'Choose a catalogue item and a Sub-product.');
        }
        if ($itemMasterId === $survivorMasterId) {
            throw ProductMasterInvalid::field('survivorMasterId', 'A product cannot be merged with itself.');
        }
        if (! in_array($nameKept, [self::NAME_PORTFOLIO, self::NAME_CATALOGUE], true)) {
            throw ProductMasterInvalid::field('nameKept', "nameKept is '" . self::NAME_PORTFOLIO . "' or '" . self::NAME_CATALOGUE . "'.");
        }
    }

    private function requireTables(): void
    {
        foreach (['g2g_product_master', 'g2g_product_offers', 'crm_products'] as $table) {
            if (! $this->has($table)) {
                throw ProductMasterInvalid::field('table', "This host has no {$table} table, so products cannot be merged here.", 409);
            }
        }
        if (! $this->has('g2g_product_match_decisions')) {
            throw ProductMasterInvalid::field('table', 'This host has no g2g_product_match_decisions table. Run its migration first (2026_12_23_100000_create_g2g_product_match_decisions_table).', 409);
        }
    }

    /**
     * Reads (and, for a merge, locks) the rows of the pair and refuses what is not a valid pair.
     *
     * @return array<string, mixed>
     */
    private function context(int $tenant, int $itemMasterId, int $survivorMasterId, bool $lock): array
    {
        $masters = $this->db->table('g2g_product_master')->whereIn('id', [$itemMasterId, $survivorMasterId])->where('sub_institute_id', $tenant)->orderBy('id');
        if ($lock) {
            $masters->lockForUpdate();
        }
        $rows = $masters->get()->keyBy('id');
        $item = $rows[$itemMasterId] ?? null;
        $survivor = $rows[$survivorMasterId] ?? null;
        // A shared row (organisation 0) and another organisation's row are both "not yours": a person learns nothing about either.
        if ($item === null) {
            throw ProductMasterInvalid::field('itemMasterId', 'That catalogue item is not one of yours.', 404);
        }
        if ($survivor === null) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product is not one of yours.', 404);
        }
        if ($item->kind !== ProductMaster::KIND_ITEM) {
            throw ProductMasterInvalid::field('itemMasterId', 'That is not a catalogue item.');
        }
        if ($survivor->kind !== ProductMaster::KIND_SUB_PRODUCT) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That is not a Sub-product.');
        }

        // Already merged: the husk, the decision and the survivor's catalogue pointer all say so. Nothing is written.
        $decision = $this->decision($tenant, $itemMasterId, $survivorMasterId, $lock);
        $merged = $item->status === ProductMaster::STATUS_ARCHIVED && $item->origin === self::ORIGIN_MERGED && $item->crm_product_id === null;
        if ($merged && $decision !== null && $decision->decision === self::SAME && $survivor->crm_product_id !== null) {
            return $this->contextOf($tenant, $item, $survivor, $this->crmRow($tenant, (int) $survivor->crm_product_id, $lock), $this->offerRow($tenant, $survivor->offer_row_id, $lock), true);
        }
        if ($merged) {
            $into = $this->db->table('g2g_product_match_decisions')->where('sub_institute_id', $tenant)->where('item_master_id', $itemMasterId)->where('decision', self::SAME)->value('survivor_master_id');
            throw ProductMasterInvalid::field('itemMasterId', 'That catalogue item was already merged' . ($into !== null ? " into Sub-product {$into}" : '') . '.', 409);
        }
        if ($item->status !== ProductMaster::STATUS_ACTIVE) {
            throw ProductMasterInvalid::field('itemMasterId', 'That catalogue item is archived. Restore it first.', 422);
        }
        if ($item->crm_product_id === null) {
            throw ProductMasterInvalid::field('itemMasterId', 'That item has no catalogue row.');
        }
        $crm = $this->crmRow($tenant, (int) $item->crm_product_id, $lock);
        if ($crm === null) {
            throw ProductMasterInvalid::field('itemMasterId', 'That catalogue row is not one of yours.', 404);
        }
        if ($crm->deleted_at !== null) {
            throw ProductMasterInvalid::field('itemMasterId', 'That catalogue item is in the Recycle Bin. Restore it first.');
        }
        if ($survivor->status !== ProductMaster::STATUS_ACTIVE) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product is archived. Restore it first.');
        }
        if ($survivor->offer_row_id === null) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product has no Portfolio offer.');
        }
        $offer = $this->offerRow($tenant, $survivor->offer_row_id, $lock);
        if ($offer === null) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product\'s Portfolio offer is not one of yours.', 404);
        }
        if ($survivor->crm_product_id !== null) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product is already sold through catalogue item ' . (int) $survivor->crm_product_id . '.', 409);
        }

        return $this->contextOf($tenant, $item, $survivor, $crm, $offer, false);
    }

    /** @return array<string, mixed> */
    private function contextOf(int $tenant, object $item, object $survivor, ?object $crm, ?object $offer, bool $alreadyMerged): array
    {
        $survivorProduct = $survivor->parent_id !== null && (int) $survivor->parent_id > 0 ? (int) $survivor->parent_id : null;
        if ($survivorProduct === null) {
            throw ProductMasterInvalid::field('survivorMasterId', 'That Sub-product has no Product.');
        }
        $masterLine = $item->parent_id !== null && (int) $item->parent_id > 0 ? (int) $item->parent_id : null;
        $crmLine = $crm !== null && isset($crm->product_master_id) && (int) $crm->product_master_id > 0 ? (int) $crm->product_master_id : null;
        if (! $alreadyMerged) {
            if ($masterLine !== null && $crmLine !== null && $masterLine !== $crmLine) {
                throw ProductMasterInvalid::field('itemMasterId', "That item's two product pointers disagree (the master row says {$masterLine}, the catalogue row {$crmLine}). Run the product checker.", 409);
            }
            $itemProduct = $masterLine ?? $crmLine;
            if ($itemProduct !== null && $itemProduct !== $survivorProduct) {
                throw ProductMasterInvalid::field('survivorMasterId', 'They sit under different products (the item under product ' . $itemProduct . ', the Sub-product under product ' . $survivorProduct . '). Nothing merges across products.', 422);
            }
        }

        return [
            'tenant' => $tenant, 'item' => $item, 'survivor' => $survivor, 'crmId' => (int) ($crm->id ?? $survivor->crm_product_id ?? 0), 'crmName' => (string) ($crm->name ?? ''),
            'offerRowId' => (int) ($offer->id ?? $survivor->offer_row_id ?? 0), 'productId' => $survivorProduct, 'alreadyMerged' => $alreadyMerged,
            // An item with no Product of its own is given the Sub-product's by the mirror: the one legitimate change in the census (see merge()).
            'itemGainsProduct' => ! $alreadyMerged && $masterLine === null && $crmLine === null,
        ];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private function describe(array $ctx, string $nameKept): array
    {
        return [
            'tenant' => $ctx['tenant'], 'itemMasterId' => (int) $ctx['item']->id, 'survivorMasterId' => (int) $ctx['survivor']->id, 'crmProductId' => $ctx['crmId'], 'offerRowId' => $ctx['offerRowId'],
            'productId' => $ctx['productId'], 'nameKept' => $nameKept, 'name' => $ctx['alreadyMerged'] ? (string) $ctx['survivor']->name : $this->keptName($ctx, $nameKept),
            'alreadyMerged' => $ctx['alreadyMerged'],
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function keptName(array $ctx, string $nameKept): string
    {
        $name = trim($nameKept === self::NAME_CATALOGUE ? (string) $ctx['crmName'] : (string) $ctx['survivor']->name);
        if ($name === '') {
            throw ProductMasterInvalid::field('nameKept', 'The name to keep is empty. Keep the other one.');
        }

        return mb_strlen($name) > 191 ? mb_substr($name, 0, 191) : $name;
    }

    private function crmRow(int $tenant, int $crmId, bool $lock): ?object
    {
        $q = $this->db->table('crm_products')->where('id', $crmId)->where('sub_institute_id', $tenant);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    private function offerRow(int $tenant, mixed $offerRowId, bool $lock): ?object
    {
        if ($offerRowId === null) {
            return null;
        }
        $q = $this->db->table('g2g_product_offers')->where('id', (int) $offerRowId)->where('sub_institute_id', $tenant);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    private function decision(int $tenant, int $itemMasterId, int $survivorMasterId, bool $lock): ?object
    {
        $q = $this->db->table('g2g_product_match_decisions')->where('sub_institute_id', $tenant)->where('item_master_id', $itemMasterId)->where('survivor_master_id', $survivorMasterId);

        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    // ------------------------------------------------------------------ repointing

    /**
     * The ids of the rows that hold the husk's id, per repointed pointer ("table.column" => ids), inside the organisation.
     *
     * @return array<string, list<int>>
     */
    private function huskRefs(int $tenant, int $huskId): array
    {
        $out = [];
        foreach ($this->entries() as $e) {
            $key = $e['table'] . '.' . $e['column'];
            $out[$key] = [];
            if (! $this->usable($e)) {
                continue;
            }
            foreach ($this->rows($e, $tenant)->where($e['column'], $huskId)->orderBy('id')->pluck('id') as $id) {
                $out[$key][] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * Moves every reference to the husk onto the survivor and counts what was left behind.
     *
     * @param array<string, list<int>> $refs what held the husk's id before the merge
     * @return array{moved: array<string, int>, claims: array{moved: int, retired: int, retiredApproved: int}}
     */
    private function repoint(int $tenant, int $huskId, int $survivorId, array $refs): array
    {
        $moved = [];
        $claims = ['moved' => 0, 'retired' => 0, 'retiredApproved' => 0];
        $keep = [];

        foreach ($this->entries() as $e) {
            $table = $e['table'];
            $column = $e['column'];
            $key = $table . '.' . $column;
            if (! $this->usable($e)) {
                continue;
            }
            $ids = $refs[$key] ?? [];
            $retiredIds = [];

            if (isset(self::UNIQUE[$key])) {
                // The survivor's rows win: a row of the husk that says what the survivor already says is retired, not moved (and never deleted).
                [$retiredIds, $approved] = $this->collisions($e, $tenant, $survivorId, $ids);
                if ($retiredIds !== []) {
                    foreach (array_chunk($retiredIds, self::CHUNK) as $chunk) {
                        $this->db->table($table)->whereIn('id', $chunk)->update(['status' => 'retired', 'updated_at' => now()]);
                    }
                }
                if ($table === 'g2g_product_claims') {
                    $claims['retired'] = count($retiredIds);
                    $claims['retiredApproved'] = $approved;
                }
                $keep[$key] = $retiredIds;
            }

            $move = array_values(array_diff($ids, $retiredIds));
            $n = 0;
            foreach (array_chunk($move, self::CHUNK) as $chunk) {
                $n += $this->rows($e, $tenant)->whereIn('id', $chunk)->where($column, $huskId)->update([$column => $survivorId]);
            }
            if ($n !== count($move)) {
                throw new ProductMasterInvalid(['pointers' => ["{$key}: " . count($move) . " row(s) held the husk and {$n} were moved"]], 409, "The merge was taken back: {$key} changed while it ran.");
            }
            if ($n > 0) {
                $moved[$key] = $n;
            }
            if ($table === 'g2g_product_claims') {
                $claims['moved'] = $n;
            }
        }

        // Nothing may still point at the husk, except the duplicates that were retired on purpose.
        foreach ($this->entries() as $e) {
            $key = $e['table'] . '.' . $e['column'];
            if (! $this->usable($e)) {
                continue;
            }
            $left = $this->rows($e, $tenant)->where($e['column'], $huskId)->count();
            $allowed = count($keep[$key] ?? []);
            if ($left !== $allowed) {
                throw new ProductMasterInvalid(['pointers' => ["{$key}: {$left} row(s) still point at the retired record (expected {$allowed})"]], 409, "The merge was taken back: {$key} still points at the retired record.");
            }
        }

        return ['moved' => $moved, 'claims' => $claims];
    }

    /**
     * The husk's rows that would collide with a row of the survivor in the unique key of this pointer.
     *
     * @param array<string, mixed> $e
     * @param list<int> $ids the husk's rows
     * @return array{0: list<int>, 1: int} the ids to retire, and how many of them were `approved`
     */
    private function collisions(array $e, int $tenant, int $survivorId, array $ids): array
    {
        if ($ids === []) {
            return [[], 0];
        }
        // The key is (organisation, this pointer, ...others): the organisation is the one the rows are scoped to already.
        $others = array_values(array_diff(self::UNIQUE[$e['table'] . '.' . $e['column']], ['sub_institute_id']));
        $columns = array_merge(['id'], $others, $this->column($e['table'], 'status') ? ['status'] : []);
        $taken = [];
        foreach ($this->db->table($e['table'])->where('sub_institute_id', $tenant)->where($e['column'], $survivorId)->get($others) as $row) {
            $taken[$this->print($row, $others)] = true;
        }
        $retire = [];
        $approved = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->db->table($e['table'])->whereIn('id', $chunk)->get($columns) as $row) {
                if (isset($taken[$this->print($row, $others)])) {
                    $retire[] = (int) $row->id;
                    $approved += (($row->status ?? null) === 'approved') ? 1 : 0;
                }
            }
        }

        return [$retire, $approved];
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

    /** @return list<array<string, mixed>> the pointers the merge moves */
    private function entries(): array
    {
        $moves = self::repointed();

        return array_values(array_filter(ProductPointers::toMaster(), fn (array $e): bool => in_array($e['table'] . '.' . $e['column'], $moves, true)));
    }

    /**
     * The rows of a pointer's table that belong to the organisation: its own sub_institute_id, or the organisation of the document the row hangs from.
     *
     * @param array<string, mixed> $e
     */
    private function rows(array $e, int $tenant): Builder
    {
        $scope = $e['scope'];
        if (is_string($scope)) {
            return $this->db->table($e['table'])->where($scope, $tenant);
        }

        return $this->db->table($e['table'])->whereIn($scope['via'], fn ($q) => $q->select($scope['parent_key'])->from($scope['parent'])->where($scope['parent_tenant'], $tenant));
    }

    /** @param array<string, mixed> $e this host has the table, the pointer column, the id, and what the organisation scope needs */
    private function usable(array $e): bool
    {
        $scope = $e['scope'];
        $columns = ['id', $e['column']];
        if (is_string($scope)) {
            $columns[] = $scope;
        } else {
            $columns[] = $scope['via'];
            if (! $this->has($scope['parent']) || ! $this->column($scope['parent'], $scope['parent_key']) || ! $this->column($scope['parent'], $scope['parent_tenant'])) {
                return false;
            }
        }
        if (! $this->has($e['table'])) {
            return false;
        }
        foreach ($columns as $c) {
            if (! $this->column($e['table'], $c)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ the decision row and the audit trail

    /** @param ?array<string, mixed> $evidence what the suggester saw */
    private function writeDecision(int $tenant, int $itemMasterId, int $survivorMasterId, string $decision, ?array $evidence, ?string $nameKept, ?int $actor, mixed $now): int
    {
        $values = [
            'decision' => $decision, 'score' => $evidence['score'] ?? null, 'signals' => isset($evidence['signals']) ? mb_substr((string) $evidence['signals'], 0, 500) : null,
            'name_kept' => $decision === self::SAME ? $nameKept : null, 'decided_by' => $actor, 'decided_at' => $now, 'updated_at' => $now,
        ];
        $existing = $this->decision($tenant, $itemMasterId, $survivorMasterId, true);
        if ($existing !== null) {
            $this->db->table('g2g_product_match_decisions')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) $this->db->table('g2g_product_match_decisions')->insertGetId($values + [
            'sub_institute_id' => $tenant, 'item_master_id' => $itemMasterId, 'survivor_master_id' => $survivorMasterId, 'created_at' => $now,
        ]);
    }

    /** The columns that say who changed a master row (only when the caller is a person). @return array<string, int> */
    private function by(?int $actor): array
    {
        return $actor !== null ? ['updated_by' => $actor] : [];
    }

    private function stamp(): string
    {
        return str_replace('.', '', sprintf('%.6f', microtime(true))) . bin2hex(random_bytes(2));
    }

    /**
     * Records the audit event through EventRecorder, AFTER the commit. A failure to record it is reported, never undone.
     *
     * @param array<string, mixed> $payload
     */
    public function emit(string $type, int $tenant, ?int $actor, int $entityId, array $payload, string $key): string
    {
        try {
            app(EventRecorder::class)->record($type, $tenant, 'g2g_product_master', $entityId, $actor, $payload, null, mb_substr($key, 0, 120));

            return 'recorded';
        } catch (\Throwable $e) {
            try {
                Log::warning('The product merge audit event could not be recorded. The merge itself is done.', ['type' => $type, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
                // not even logging may reach the caller
            }

            return 'failed: ' . $e->getMessage();
        }
    }

    /** @param list<array<string, mixed>> $findings */
    private function integrityRefusal(string $message, array $findings, int $status): ProductMasterInvalid
    {
        $lines = [];
        foreach (array_slice($findings, 0, 5) as $f) {
            $lines[] = '[' . $f['code'] . '] ' . $f['message'];
        }

        return new ProductMasterInvalid(['integrity' => $lines], $status, $message . ($lines !== [] ? ' First: ' . $lines[0] : ''));
    }

    private function has(string $table): bool
    {
        return $this->present[$table] ??= Schema::connection($this->db->getName())->hasTable($table);
    }

    private function column(string $table, string $column): bool
    {
        return $this->present[$table . '.' . $column] ??= $this->has($table) && ColumnExists::has($table, $column, $this->db);
    }
}
