<?php

namespace App\Domain\ProductMaster;

use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * Fills the Product Master from the records that already hold the products. Safe to repeat: a row that already exists (by organisation and
 * key, offer pointer or catalogue pointer) is counted and left alone, nothing is ever overwritten, and nothing is deleted.
 *
 * In this order:
 *   1. the shared products (organisation 0): the built-in products from config/signals_products.php, then every other Product Portfolio group
 *      named in config/g2g_taxonomy.php (HE, Bundle), then any offer that belongs to no organisation;
 *   2. the organisation's own products from g2g_signal_products;
 *   3. its sub-products from g2g_product_offers, each under the product whose Portfolio group name it carries;
 *   4. its catalogue-only items from crm_products (no name matching: a catalogue row is its own item until a person links it).
 *
 * A dry run (the default) reads and counts but writes nothing, and uses no database ids. Problems are listed, never guessed away.
 */
final class ProductMasterBackfill
{
    private bool $apply = false;

    private int $fakeId = 0;

    /** True in a dry run on a connection that has no Product Master table yet: everything is then "would create", and nothing is read from it. */
    private bool $noTable = false;

    /** @var list<array{id:int, tenant:int, key:string, signal:?string, portfolio:?string, name:string}> products known so far, existing and newly planned */
    private array $products = [];

    /** @var array<string, int> in a dry run, "organisation|key" => the offer or catalogue row a planned row would point at (so a repeated id is found) */
    private array $planned = [];

    /** @var array{tenant:int, apply:bool, created:array<string,int>, existing:array<string,int>, issues:list<string>} */
    private array $report = [];

    public function __construct(private readonly ?string $connection = null) {}

    /** @return array{tenant:int, apply:bool, created:array<string,int>, existing:array<string,int>, issues:list<string>} */
    public function run(int $tenantId, bool $apply): array
    {
        $this->apply = $apply;
        $this->fakeId = 0;
        $this->products = [];
        $this->planned = [];
        $this->report = ['tenant' => $tenantId, 'apply' => $apply, 'created' => [], 'existing' => [], 'issues' => []];

        $db = \Illuminate\Support\Facades\DB::connection($this->connection);
        $this->noTable = ! $this->tableExists($db, 'g2g_product_master');
        if ($this->noTable && $apply) {
            throw new \RuntimeException('g2g_product_master does not exist on this connection: run its migration first.');
        }
        if ($this->noTable) {
            $this->report['issues'][] = 'g2g_product_master does not exist on this connection yet, so every row is shown as "would create".';
        }

        if ($apply) {
            $db->beginTransaction();
        }
        try {
            $this->loadKnownProducts($db, $tenantId);
            $this->shared($db);
            $this->customProducts($db, $tenantId);
            $this->offers($db, $tenantId);
            $this->catalogueItems($db, $tenantId);
            if ($apply) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $db->rollBack();
            }
            throw $e;
        }

        return $this->report;
    }

    private function shared(Connection $db): void
    {
        $coveredGroups = [];
        foreach (ProductCatalog::definitions() as $key => $d) {
            foreach ((array) ($d['portfolio_names'] ?? []) as $g) {
                $coveredGroups[strtolower((string) $g)] = true;
            }
            $this->ensure($db, [
                'sub_institute_id' => ProductMaster::SHARED, 'product_key' => (string) $key, 'kind' => ProductMaster::KIND_PRODUCT,
                'name' => (string) $d['label'], 'origin' => 'builtin', 'signal_key' => (string) $d['business_fit'],
                'portfolio_name' => ((array) ($d['portfolio_names'] ?? []))[0] ?? null,
            ], 'shared product');
        }

        // Product Portfolio groups with no Signals product of their own (HE, Bundle) are still products of the Product Master.
        foreach ((array) config('g2g_taxonomy.parent_products', []) as $group => $label) {
            if (isset($coveredGroups[strtolower((string) $group)])) {
                continue;
            }
            $this->ensure($db, [
                'sub_institute_id' => ProductMaster::SHARED, 'product_key' => $this->slug((string) $group), 'kind' => ProductMaster::KIND_PRODUCT,
                'name' => (string) $label, 'origin' => 'portfolio', 'portfolio_name' => (string) $group,
            ], 'shared product');
        }

        // An offer that belongs to no organisation is shared too.
        if ($this->tableExists($db, 'g2g_product_offers')) {
            foreach ($db->table('g2g_product_offers')->whereNull('sub_institute_id')->orderBy('id')->get(['id', 'offer_id', 'name', 'parent_product']) as $o) {
                $this->subProduct($db, ProductMaster::SHARED, $o);
            }
        }
    }

    private function customProducts(Connection $db, int $tenantId): void
    {
        if ($tenantId <= 0 || ! $this->tableExists($db, 'g2g_signal_products')) {
            return;
        }
        foreach ($db->table('g2g_signal_products')->where('sub_institute_id', $tenantId)->where('status', 'active')->orderBy('id')->get() as $r) {
            $names = json_decode((string) $r->portfolio_names, true);
            $this->ensure($db, [
                'sub_institute_id' => $tenantId, 'product_key' => (string) $r->key, 'kind' => ProductMaster::KIND_PRODUCT, 'name' => (string) $r->label,
                'origin' => 'custom', 'signal_key' => (string) $r->business_fit,
                'portfolio_name' => is_array($names) && isset($names[0]) ? (string) $names[0] : (string) $r->label,
            ], 'own product');
        }
    }

    private function offers(Connection $db, int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        if (! $this->tableExists($db, 'g2g_product_offers')) {
            $this->report['issues'][] = 'g2g_product_offers does not exist on this connection: no sub-products were read.';

            return;
        }
        foreach ($db->table('g2g_product_offers')->where('sub_institute_id', $tenantId)->orderBy('id')->get(['id', 'offer_id', 'name', 'parent_product']) as $o) {
            $this->subProduct($db, $tenantId, $o);
        }
    }

    private function subProduct(Connection $db, int $tenant, object $offer): void
    {
        $parent = $this->productByGroup($tenant, (string) $offer->parent_product);
        if ($parent === null) {
            $this->report['issues'][] = "offer {$offer->offer_id} (row {$offer->id}): no product named '{$offer->parent_product}' exists, so it was skipped";

            return;
        }
        // The key is built by the same function the write-through uses (ProductMasterSync), so both make the same row.
        $key = ProductMasterSync::subProductKey($parent['key'], (string) $offer->offer_id);
        if ($key === null) {
            $this->report['issues'][] = "offer {$offer->offer_id} (row {$offer->id}): its key is longer than 50 characters, so it was skipped";

            return;
        }
        $clash = $this->noTable ? null : $db->table('g2g_product_master')->where('sub_institute_id', $tenant)->where('product_key', $key)->first();
        $plannedFor = $this->planned[$tenant . '|' . $key] ?? null;
        if (($clash !== null && (int) $clash->offer_row_id !== (int) $offer->id) || ($plannedFor !== null && $plannedFor !== (int) $offer->id)) {
            $this->report['issues'][] = "offer id {$offer->offer_id} appears more than once in this organisation; row {$offer->id} was skipped";

            return;
        }
        if (strtolower((string) $offer->offer_id) === strtolower((string) ($parent['signal'] ?? '')) || strtolower((string) $offer->offer_id) === $parent['key']) {
            $this->report['issues'][] = "offer {$offer->offer_id} has the same id as its product '{$parent['name']}'; kept as a sub-product";
        }
        $this->ensure($db, [
            'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => ProductMaster::KIND_SUB_PRODUCT, 'parent_id' => $parent['id'],
            'name' => (string) $offer->name, 'origin' => 'portfolio', 'offer_row_id' => (int) $offer->id,
        ], 'sub-product');
    }

    private function catalogueItems(Connection $db, int $tenantId): void
    {
        if ($tenantId <= 0 || ! $this->tableExists($db, 'crm_products')) {
            return;
        }
        foreach ($db->table('crm_products')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'product_no', 'name']) as $p) {
            $this->ensure($db, [
                'sub_institute_id' => $tenantId, 'product_key' => ProductMasterSync::catalogueKey($p->product_no, (int) $p->id), 'kind' => ProductMaster::KIND_ITEM,
                'name' => (string) $p->name, 'origin' => 'crm', 'crm_product_id' => (int) $p->id,
            ], 'catalogue item');
        }
    }

    /** The product rows already in the table that this organisation can see, so a repeat run finds them. */
    private function loadKnownProducts(Connection $db, int $tenantId): void
    {
        if ($this->noTable) {
            return;
        }
        foreach ($db->table('g2g_product_master')->where('kind', ProductMaster::KIND_PRODUCT)->whereIn('sub_institute_id', array_values(array_unique([max(0, $tenantId), 0])))->get() as $r) {
            $this->products[] = ['id' => (int) $r->id, 'tenant' => (int) $r->sub_institute_id, 'key' => (string) $r->product_key, 'signal' => $r->signal_key, 'portfolio' => $r->portfolio_name, 'name' => (string) $r->name];
        }
    }

    /** @return array{id:int, tenant:int, key:string, signal:?string, portfolio:?string, name:string}|null the organisation's own product first, then a shared one */
    private function productByGroup(int $tenant, string $group): ?array
    {
        $want = strtolower(trim($group));
        $hit = null;
        foreach ($this->products as $p) {
            if ($p['portfolio'] !== null && strtolower($p['portfolio']) === $want && in_array($p['tenant'], [$tenant, ProductMaster::SHARED], true)) {
                if ($p['tenant'] === $tenant) {
                    return $p;
                }
                $hit ??= $p;
            }
        }

        return $hit;
    }

    /** Finds an existing row (by key, offer pointer or catalogue pointer) or creates it. Returns its id (a negative placeholder in a dry run). */
    private function ensure(Connection $db, array $row, string $bucket): int
    {
        $row += ['parent_id' => null, 'signal_key' => null, 'portfolio_name' => null, 'offer_row_id' => null, 'crm_product_id' => null, 'created_by' => null, 'updated_by' => null];
        $q = fn () => $db->table('g2g_product_master')->where('sub_institute_id', $row['sub_institute_id']);

        $existing = $this->noTable ? null : $q()->where('product_key', $row['product_key'])->first();
        if ($existing === null && ! $this->noTable && $row['offer_row_id'] !== null) {
            $existing = $q()->where('offer_row_id', $row['offer_row_id'])->first();
        }
        if ($existing === null && ! $this->noTable && $row['crm_product_id'] !== null) {
            $existing = $q()->where('crm_product_id', $row['crm_product_id'])->first();
        }
        if ($existing !== null) {
            $this->report['existing'][$bucket] = ($this->report['existing'][$bucket] ?? 0) + 1;

            return (int) $existing->id;
        }

        $this->report['created'][$bucket] = ($this->report['created'][$bucket] ?? 0) + 1;
        if ($this->apply) {
            $now = now();
            $id = (int) $db->table('g2g_product_master')->insertGetId($row + ['status' => ProductMaster::STATUS_ACTIVE, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $id = --$this->fakeId;
            $this->planned[$row['sub_institute_id'] . '|' . $row['product_key']] = (int) ($row['offer_row_id'] ?? $row['crm_product_id'] ?? 0);
        }
        if ($row['kind'] === ProductMaster::KIND_PRODUCT) {
            $this->products[] = ['id' => $id, 'tenant' => (int) $row['sub_institute_id'], 'key' => (string) $row['product_key'], 'signal' => $row['signal_key'], 'portfolio' => $row['portfolio_name'], 'name' => (string) $row['name']];
        }

        return $id;
    }

    private function tableExists(Connection $db, string $table): bool
    {
        return Schema::connection($db->getName())->hasTable($table);
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_');
    }
}
