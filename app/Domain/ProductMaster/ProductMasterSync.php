<?php

namespace App\Domain\ProductMaster;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the Product Master in step with the three stores that own the products, so the CRM Products screen, the Product Portfolio and the Signals
 * product list can all be read through one canonical record (slice 1; Docs/agentic-crm/slices/S01-product-master.md).
 *
 *   crm_products          the CRM catalogue          -> an `item` row, or the CRM side of a `sub_product` row
 *   g2g_product_offers    the Product Portfolio      -> a `sub_product` row
 *   g2g_signal_products   an organisation's products -> a `product` row (origin `custom`)
 *
 * Every method is STATE-BASED: it reads the source row as it is now and makes the master row agree with it, so the same call is right after a
 * create, an edit, a delete, a restore or a repair, and calling it twice changes nothing the second time. It is tenant-scoped (a row of another
 * organisation is never read or written) and never overwrites what a person did on the master row: a deliberate archive stays archived
 * (only a CREATED or RESTORED event, or the adoption of a row whose source was deleted, makes a row active again).
 *
 * The write-through entry points (throughCatalogueItems, throughOffer, throughCustomProduct) are GUARDED: a failure is logged and swallowed,
 * because the caller has already saved its own record and the master row can be rebuilt by `php artisan product-master:repair`.
 *
 * Built on the query builder and an explicit connection so the repair command can run it on another host; `$apply = false` makes every method
 * report what it would do and write nothing.
 */
final class ProductMasterSync
{
    public const CREATED = 'created';

    public const SAVED = 'saved';

    public const RESTORED = 'restored';

    // What a method did. The repair command counts these.
    public const ACT_CREATED = 'created';

    public const ACT_UPDATED = 'updated';

    public const ACT_REVIVED = 'revived';

    public const ACT_ARCHIVED = 'archived';

    public const ACT_CONVERTED = 'converted';

    public const ACT_UNCHANGED = 'unchanged';

    public const ACT_SKIPPED = 'skipped';

    private readonly Connection $db;

    /** @var list<string> problems met while syncing, for the repair report */
    private array $issues = [];

    /**
     * @param bool $apply          false: report what would be done and write nothing (the repair command's dry run)
     * @param bool $createMissing  false: never create a row for a source that has none (the repair command leaves creation to the backfill)
     */
    public function __construct(?string $connection = null, private readonly bool $apply = true, private readonly ?int $actor = null, private readonly bool $createMissing = true)
    {
        $this->db = DB::connection($connection);
    }

    // ---- write-through entry points (guarded) ----

    /** @param list<int> $crmProductIds */
    public static function throughCatalogueItems(int $tenant, array $crmProductIds, string $event = self::SAVED, ?int $actor = null): void
    {
        self::guarded('crm_products ' . implode(',', $crmProductIds), function () use ($tenant, $crmProductIds, $event, $actor) {
            $sync = new self(null, true, $actor);
            foreach (array_values(array_unique(array_map('intval', $crmProductIds))) as $id) {
                if ($id > 0) {
                    $sync->catalogueItem($tenant, $id, $event);
                }
            }
        });
    }

    public static function throughOffer(int $tenant, int $offerRowId, string $event = self::SAVED, ?int $actor = null): void
    {
        self::guarded('g2g_product_offers ' . $offerRowId, fn () => (new self(null, true, $actor))->offer($tenant, $offerRowId, $event));
    }

    /** Every offer of one organisation (after a bulk import that does not report which rows it touched). */
    public static function throughOffersOfTenant(int $tenant, ?int $actor = null): void
    {
        self::guarded('g2g_product_offers of organisation ' . $tenant, function () use ($tenant, $actor) {
            $sync = new self(null, true, $actor);
            foreach ($sync->db->table('g2g_product_offers')->where('sub_institute_id', $tenant)->orderBy('id')->pluck('id') as $id) {
                $sync->offer($tenant, (int) $id, self::SAVED);
            }
        });
    }

    public static function throughCustomProduct(int $tenant, string $key, string $event = self::SAVED, ?int $actor = null): void
    {
        self::guarded('g2g_signal_products ' . $key, fn () => (new self(null, true, $actor))->customProduct($tenant, $key, $event));
    }

    /** Runs one step, and logs and swallows anything it throws. @return bool true when it ran without an error */
    public static function guarded(string $what, \Closure $step): bool
    {
        try {
            $step();

            return true;
        } catch (\Throwable $e) {
            try {
                Log::warning('Product Master could not follow a product change (' . $what . '). The product itself was saved. Run product-master:repair to rebuild it.', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
                // Even logging must not reach the caller.
            }

            return false;
        }
    }

    /** @return list<string> */
    public function issues(): array
    {
        return $this->issues;
    }

    // ---- keys (shared with the backfill so both build the same row) ----

    /** `crm/<item number>`, or `crm/<id>` when the item has no number. At most 50 characters. */
    public static function catalogueKey(?string $productNo, int $crmProductId): string
    {
        $code = trim((string) $productNo) !== '' ? strtolower(trim((string) $productNo)) : (string) $crmProductId;

        return substr('crm/' . $code, 0, 50);
    }

    /** `<product key>/<offer id>` in lower case, or null when it would pass the 50 characters a key may have. */
    public static function subProductKey(string $productKey, string $offerId): ?string
    {
        $key = $productKey . '/' . strtolower($offerId);

        return strlen($key) > 50 ? null : $key;
    }

    // ---- the catalogue ----

    public function catalogueItem(int $tenant, int $crmProductId, string $event = self::SAVED): string
    {
        if ($tenant <= 0 || ! $this->has('g2g_product_master') || ! $this->has('crm_products')) {
            return self::ACT_SKIPPED;
        }

        $src = $this->db->table('crm_products')->where('id', $crmProductId)->where('sub_institute_id', $tenant)->first(['id', 'product_no', 'name', 'deleted_at']);
        $row = $this->master($tenant)->where('crm_product_id', $crmProductId)->first();

        if ($src === null || $src->deleted_at !== null) {
            // Gone from the catalogue (in the Recycle Bin, merged away or deleted). A catalogue-only item is archived; a sub-product keeps its
            // pointer, so taking the item out of the Recycle Bin restores the link.
            if ($row !== null && $row->kind === ProductMaster::KIND_ITEM && $row->status === ProductMaster::STATUS_ACTIVE) {
                $this->change($row, ['status' => ProductMaster::STATUS_ARCHIVED]);

                return self::ACT_ARCHIVED;
            }
            if ($row !== null && $row->kind === ProductMaster::KIND_SUB_PRODUCT && $src === null) {
                // Deleted for good: nothing to restore, so the sub-product stops pointing at it.
                $this->change($row, ['crm_product_id' => null]);

                return self::ACT_UPDATED;
            }

            return self::ACT_UNCHANGED;
        }

        $name = $this->clip((string) $src->name, 191);
        if ($row === null) {
            $key = self::catalogueKey($src->product_no, (int) $src->id);
            if ($this->master($tenant)->where('product_key', $key)->exists()) {
                $key = self::catalogueKey(null, (int) $src->id);
            }
            if ($this->master($tenant)->where('product_key', $key)->exists()) {
                $this->issues[] = "catalogue item {$src->id}: the key '{$key}' is already used by another record, so it was skipped";

                return self::ACT_SKIPPED;
            }

            return $this->insert([
                'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => ProductMaster::KIND_ITEM, 'name' => $name, 'origin' => 'crm', 'crm_product_id' => (int) $src->id,
            ]) ? self::ACT_CREATED : self::ACT_UNCHANGED;
        }

        $set = [];
        if ($row->kind === ProductMaster::KIND_ITEM) {
            if ((string) $row->name !== $name) {
                $set['name'] = $name;
            }
            if ($row->status === ProductMaster::STATUS_ARCHIVED && in_array($event, [self::CREATED, self::RESTORED], true)) {
                $set['status'] = ProductMaster::STATUS_ACTIVE;
            }
        }
        if ($set === []) {
            return self::ACT_UNCHANGED;
        }
        $this->change($row, $set);

        return isset($set['status']) ? self::ACT_REVIVED : self::ACT_UPDATED;
    }

    // ---- the Product Portfolio ----

    public function offer(int $tenant, int $offerRowId, string $event = self::SAVED): string
    {
        if ($tenant <= 0 || ! $this->has('g2g_product_master') || ! $this->has('g2g_product_offers')) {
            return self::ACT_SKIPPED;
        }

        $src = $this->db->table('g2g_product_offers')->where('id', $offerRowId)->first(['id', 'sub_institute_id', 'offer_id', 'name', 'parent_product']);
        $row = $this->master($tenant)->where('offer_row_id', $offerRowId)->first();

        // Only the organisation's own offers are followed. A shared offer (no organisation) belongs to the backfill, under organisation 0.
        if ($src === null || $src->sub_institute_id === null || (int) $src->sub_institute_id !== $tenant) {
            return $this->offerGone($tenant, $row);
        }

        $parent = $this->productByGroup($tenant, (string) $src->parent_product);
        if ($parent === null) {
            $this->issues[] = "offer {$src->offer_id} (row {$src->id}): no product named '{$src->parent_product}' exists, so it was skipped";

            return self::ACT_SKIPPED;
        }
        $key = self::subProductKey((string) $parent->product_key, (string) $src->offer_id);
        if ($key === null) {
            $this->issues[] = "offer {$src->offer_id} (row {$src->id}): the key is longer than 50 characters, so it was skipped";

            return self::ACT_SKIPPED;
        }
        $name = $this->clip((string) $src->name, 191);

        if ($row === null) {
            $clash = $this->master($tenant)->where('product_key', $key)->first();
            if ($clash === null) {
                return $this->insert([
                    'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => ProductMaster::KIND_SUB_PRODUCT, 'parent_id' => (int) $parent->id, 'name' => $name,
                    'origin' => 'portfolio', 'offer_row_id' => (int) $src->id,
                ]) ? self::ACT_CREATED : self::ACT_UNCHANGED;
            }
            if ($clash->offer_row_id !== null && (int) $clash->offer_row_id !== (int) $src->id) {
                $this->issues[] = "offer id {$src->offer_id} appears more than once in this organisation; row {$src->id} was skipped";

                return self::ACT_SKIPPED;
            }
            // The row an earlier delete archived (its offer pointer cleared), or one made by hand: the new offer takes it over.
            $this->change($clash, ['offer_row_id' => (int) $src->id, 'parent_id' => (int) $parent->id, 'name' => $name, 'status' => ProductMaster::STATUS_ACTIVE]);

            return self::ACT_REVIVED;
        }

        $set = [];
        if ((string) $row->name !== $name) {
            $set['name'] = $name;
        }
        if ((int) $row->parent_id !== (int) $parent->id) {
            // The offer moved to another product: the row follows it, with the key that product gives it (when that key is free).
            $set['parent_id'] = (int) $parent->id;
            if ($row->product_key !== $key && ! $this->master($tenant)->where('product_key', $key)->where('id', '!=', $row->id)->exists()) {
                $set['product_key'] = $key;
            }
        }
        if ($row->status === ProductMaster::STATUS_ARCHIVED && in_array($event, [self::CREATED, self::RESTORED], true)) {
            $set['status'] = ProductMaster::STATUS_ACTIVE;
        }
        if ($set === []) {
            return self::ACT_UNCHANGED;
        }
        $this->change($row, $set);

        return isset($set['status']) ? self::ACT_REVIVED : self::ACT_UPDATED;
    }

    /** The offer behind a sub-product row no longer exists (or is not this organisation's). */
    private function offerGone(int $tenant, ?object $row): string
    {
        if ($row === null) {
            return self::ACT_UNCHANGED;
        }

        $crm = $row->crm_product_id !== null && $this->has('crm_products')
            ? $this->db->table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->first(['id', 'product_no', 'deleted_at'])
            : null;

        if ($crm !== null) {
            // Still in the CRM catalogue: it carries on as a catalogue-only item.
            $key = self::catalogueKey($crm->product_no, (int) $crm->id);
            if ($this->master($tenant)->where('product_key', $key)->where('id', '!=', $row->id)->exists()) {
                $key = (string) $row->product_key;
            }
            $this->change($row, [
                'kind' => ProductMaster::KIND_ITEM, 'parent_id' => null, 'origin' => 'crm', 'offer_row_id' => null, 'product_key' => $key,
                'status' => $crm->deleted_at !== null || $row->status === ProductMaster::STATUS_ARCHIVED ? ProductMaster::STATUS_ARCHIVED : ProductMaster::STATUS_ACTIVE,
            ]);

            return self::ACT_CONVERTED;
        }

        if ($row->status === ProductMaster::STATUS_ARCHIVED && $row->offer_row_id === null && $row->crm_product_id === null) {
            return self::ACT_UNCHANGED;
        }
        $this->change($row, ['status' => ProductMaster::STATUS_ARCHIVED, 'offer_row_id' => null, 'crm_product_id' => null]);

        return self::ACT_ARCHIVED;
    }

    // ---- an organisation's own product ----

    public function customProduct(int $tenant, string $key, string $event = self::SAVED): string
    {
        $key = strtolower(trim($key));
        if ($tenant <= 0 || $key === '' || ! $this->has('g2g_product_master') || ! $this->has('g2g_signal_products')) {
            return self::ACT_SKIPPED;
        }

        $src = $this->db->table('g2g_signal_products')->where('sub_institute_id', $tenant)->where('key', $key)->where('status', 'active')->first();
        $row = $this->master($tenant)->where('product_key', $key)->where('kind', ProductMaster::KIND_PRODUCT)->first();

        if ($src === null) {
            if ($row !== null && $row->origin === 'custom' && $row->status === ProductMaster::STATUS_ACTIVE) {
                $this->change($row, ['status' => ProductMaster::STATUS_ARCHIVED]);

                return self::ACT_ARCHIVED;
            }

            return self::ACT_UNCHANGED;
        }

        $label = $this->clip((string) $src->label, 191);
        if ($row === null) {
            $names = json_decode((string) $src->portfolio_names, true);

            return $this->insert([
                'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => ProductMaster::KIND_PRODUCT, 'name' => $label, 'origin' => 'custom',
                'signal_key' => (string) $src->business_fit, 'portfolio_name' => is_array($names) && isset($names[0]) ? (string) $names[0] : $label,
            ]) ? self::ACT_CREATED : self::ACT_UNCHANGED;
        }

        $set = [];
        if ((string) $row->name !== $label) {
            $set['name'] = $label;
        }
        if ($row->status === ProductMaster::STATUS_ARCHIVED && in_array($event, [self::CREATED, self::RESTORED], true)) {
            $set['status'] = ProductMaster::STATUS_ACTIVE;
        }
        if ($set === []) {
            return self::ACT_UNCHANGED;
        }
        $this->change($row, $set);

        return isset($set['status']) ? self::ACT_REVIVED : self::ACT_UPDATED;
    }

    // ---- the whole organisation (the repair command) ----

    /**
     * Makes the master rows of one organisation agree with their sources: a row whose source is gone is archived (or becomes a catalogue item),
     * a changed name or product is copied across, and a row an earlier delete archived is taken over by an offer re-created with the same ID.
     * It never makes an archived row active again unless a new source takes it over, and it creates nothing when this instance was built with
     * `createMissing: false` (the repair command: the backfill creates).
     *
     * @return array<string, int> what was done, by action
     */
    public function reconcile(int $tenant): array
    {
        $done = [];
        if ($tenant <= 0 || ! $this->has('g2g_product_master')) {
            return $done;
        }
        $count = function (string $action) use (&$done) {
            $done[$action] = ($done[$action] ?? 0) + 1;
        };

        // First the rows that point at a source (the source may be gone), then the sources themselves (a source may need a row's key taken over).
        $seen = ['crm' => [], 'offer' => [], 'custom' => []];
        $pointers = $this->master($tenant)->orderBy('id')->get(['crm_product_id', 'offer_row_id', 'kind', 'origin', 'product_key']);
        foreach ($pointers as $r) {
            if ($r->crm_product_id !== null && ! isset($seen['crm'][(int) $r->crm_product_id])) {
                $seen['crm'][(int) $r->crm_product_id] = true;
                $count($this->catalogueItem($tenant, (int) $r->crm_product_id, self::SAVED));
            }
            if ($r->offer_row_id !== null && ! isset($seen['offer'][(int) $r->offer_row_id])) {
                $seen['offer'][(int) $r->offer_row_id] = true;
                $count($this->offer($tenant, (int) $r->offer_row_id, self::SAVED));
            }
            if ($r->kind === ProductMaster::KIND_PRODUCT && $r->origin === 'custom' && ! isset($seen['custom'][(string) $r->product_key])) {
                $seen['custom'][(string) $r->product_key] = true;
                $count($this->customProduct($tenant, (string) $r->product_key, self::SAVED));
            }
        }
        if ($this->has('g2g_product_offers')) {
            foreach ($this->db->table('g2g_product_offers')->where('sub_institute_id', $tenant)->orderBy('id')->pluck('id') as $id) {
                if (! isset($seen['offer'][(int) $id])) {
                    $seen['offer'][(int) $id] = true;
                    $count($this->offer($tenant, (int) $id, self::SAVED));
                }
            }
        }
        if ($this->has('crm_products')) {
            foreach ($this->db->table('crm_products')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->orderBy('id')->pluck('id') as $id) {
                if (! isset($seen['crm'][(int) $id])) {
                    $seen['crm'][(int) $id] = true;
                    $count($this->catalogueItem($tenant, (int) $id, self::SAVED));
                }
            }
        }
        if ($this->has('g2g_signal_products')) {
            foreach ($this->db->table('g2g_signal_products')->where('sub_institute_id', $tenant)->where('status', 'active')->orderBy('id')->pluck('key') as $key) {
                if (! isset($seen['custom'][(string) $key])) {
                    $seen['custom'][(string) $key] = true;
                    $count($this->customProduct($tenant, (string) $key, self::SAVED));
                }
            }
        }

        return $done;
    }

    // ---- plumbing ----

    /** The organisation's own product that carries a Portfolio group name: its own first, then a shared one; an active one before an archived one. */
    private function productByGroup(int $tenant, string $group): ?object
    {
        $want = strtolower(trim($group));
        if ($want === '') {
            return null;
        }
        $best = null;
        $rank = fn (object $r) => ((int) $r->sub_institute_id === $tenant ? 0 : 2) + ($r->status === ProductMaster::STATUS_ACTIVE ? 0 : 1);
        foreach ($this->db->table('g2g_product_master')->where('kind', ProductMaster::KIND_PRODUCT)->whereIn('sub_institute_id', [$tenant, ProductMaster::SHARED])->whereNotNull('portfolio_name')->orderBy('id')->get() as $r) {
            if (strtolower((string) $r->portfolio_name) === $want && ($best === null || $rank($r) < $rank($best))) {
                $best = $r;
            }
        }

        return $best;
    }

    private function master(int $tenant): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('g2g_product_master')->where('sub_institute_id', $tenant);
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row): bool
    {
        if (! $this->createMissing) {
            return false;
        }
        if (! $this->apply) {
            return true;
        }
        $now = now();
        $row += [
            'parent_id' => null, 'signal_key' => null, 'portfolio_name' => null, 'offer_row_id' => null, 'crm_product_id' => null, 'status' => ProductMaster::STATUS_ACTIVE,
            'created_by' => $this->actor, 'updated_by' => $this->actor, 'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $this->db->table('g2g_product_master')->insert($row);
        } catch (QueryException $e) {
            if (self::isDuplicate($e)) {
                // A second request made the same row a moment ago: the row exists, which is what was wanted.
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** @param array<string, mixed> $set */
    private function change(object $row, array $set): void
    {
        if (! $this->apply || $set === []) {
            return;
        }
        // A sync never wipes who last edited the row: updated_by is written only when the caller knows the person.
        $this->db->table('g2g_product_master')->where('id', $row->id)->update($set + ($this->actor !== null ? ['updated_by' => $this->actor] : []) + ['updated_at' => now()]);
    }

    private function has(string $table): bool
    {
        return Schema::connection($this->db->getName())->hasTable($table);
    }

    private function clip(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    private static function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed') || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
