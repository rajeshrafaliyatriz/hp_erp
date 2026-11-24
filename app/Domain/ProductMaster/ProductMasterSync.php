<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
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
 *
 * One record, not linked records (slice 15, D7; the owner's rule of 13 Nov 2026): when a row holds a Portfolio offer and a catalogue item as well, a change of
 * NAME or PRODUCT made through either door reaches all three copies in ONE transaction, through mirror(). catalogueItem() (the CRM door) and offer() (the
 * Portfolio door) call it; the write-through entry points above stay guarded, so a legacy endpoint still answers as before when the master cannot follow.
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

    /** @var array<string, bool> "table.column" => the host has it (asked once per instance) */
    private array $columns = [];

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

        $cols = ['id', 'product_no', 'name', 'deleted_at'];
        if (ColumnExists::has('crm_products', 'product_master_id', $this->db)) {
            $cols[] = 'product_master_id';
        }
        $src = $this->db->table('crm_products')->where('id', $crmProductId)->where('sub_institute_id', $tenant)->first($cols);
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
        $parentId = isset($src->product_master_id) && (int) $src->product_master_id > 0 ? (int) $src->product_master_id : null;

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
                'sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => ProductMaster::KIND_ITEM, 'parent_id' => $parentId, 'name' => $name, 'origin' => 'crm', 'crm_product_id' => (int) $src->id,
            ]) ? self::ACT_CREATED : self::ACT_UNCHANGED;
        }

        // The CRM door (slice 15, D7): the catalogue item is the copy a person has just saved, so its name and its product line are what EVERY copy of this record
        // must hold, and mirror() writes them to the master row and to the Portfolio offer it is sold through, in one transaction.
        //
        // D8: a Sub-product that is sold through this item KEEPS its product when the item has no line. An item that was never given one (the Products form leaves it
        // empty) used to take the Sub-product's product away on its next save or restore, and the Product Master list, which groups Sub-products by their product,
        // then no longer showed it. The item learns the product from the Sub-product instead (mirror() writes it to the item).
        $set = [];
        $copies = [];
        if ($row->kind === ProductMaster::KIND_ITEM || $row->kind === ProductMaster::KIND_SUB_PRODUCT) {
            if ($row->status === ProductMaster::STATUS_ARCHIVED && in_array($event, [self::CREATED, self::RESTORED], true)) {
                $set['status'] = ProductMaster::STATUS_ACTIVE;
            }
            $keepsItsProduct = $parentId === null && $row->kind === ProductMaster::KIND_SUB_PRODUCT && $row->parent_id !== null;
            $copies = $this->mirror($tenant, (int) $row->id, ['name' => $name, 'parent_id' => $keepsItsProduct ? (int) $row->parent_id : $parentId], $set);
        }

        return isset($set['status']) ? self::ACT_REVIVED : ($copies !== [] ? self::ACT_UPDATED : self::ACT_UNCHANGED);
    }

    // ---- the Product Portfolio ----

    public function offer(int $tenant, int $offerRowId, string $event = self::SAVED): string
    {
        if ($tenant <= 0 || ! $this->has('g2g_product_master') || ! $this->has('g2g_product_offers')) {
            return self::ACT_SKIPPED;
        }

        $src = $this->db->table('g2g_product_offers')->where('id', $offerRowId)->first(['id', 'sub_institute_id', 'offer_id', 'name', 'parent_product']);
        $row = $this->master($tenant)->where('offer_row_id', $offerRowId)->first();

        // Only the organisation's own offers are followed. (An offer with no organisation no longer exists after the isolation: product-master:isolate-tenants gave it to its owner.)
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
            // The row an earlier delete archived (its offer pointer cleared), or one made by hand: the new offer takes it over, and the copies it holds (an item it was
            // sold through) take the offer's name and product with it.
            $this->mirror($tenant, (int) $clash->id, ['name' => $name, 'parent_id' => (int) $parent->id], ['offer_row_id' => (int) $src->id, 'status' => ProductMaster::STATUS_ACTIVE]);

            return self::ACT_REVIVED;
        }

        // The Portfolio door (slice 15, D7): the offer is the copy a person has just saved, so its name and its product are what EVERY copy of this record must hold.
        // mirror() writes them to the master row (which follows the offer to another product, with the key that product gives it when that key is free) and to the
        // catalogue item it is sold through, in one transaction. Before, the name stopped at the master row and only the product reached the item.
        $set = [];
        if ($row->status === ProductMaster::STATUS_ARCHIVED && in_array($event, [self::CREATED, self::RESTORED], true)) {
            $set['status'] = ProductMaster::STATUS_ACTIVE;
        }
        $copies = $this->mirror($tenant, (int) $row->id, ['name' => $name, 'parent_id' => (int) $parent->id], $set);

        return isset($set['status']) ? self::ACT_REVIVED : ($copies !== [] ? self::ACT_UPDATED : self::ACT_UNCHANGED);
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

    // ---- one record: a name and a product in every copy (slice 15, D7) ----

    /**
     * THE one place a product's name and its Product (level 1) are written to every copy that exists, in ONE database transaction, so that no reader ever sees the
     * master row, the Portfolio offer and the catalogue item of one record differ. The owner's rule of 13 Nov 2026 is "one record, not linked records": a name has one
     * value, whichever door changed it. catalogueItem() (the CRM door) and offer() (the Portfolio door) call it, and so will the one writer (P2.4) and the pair review.
     *
     * A row holds up to three copies, found ONLY through its own pointers and only in its own organisation: itself, the offer behind `offer_row_id` and the
     * catalogue item behind `crm_product_id` (a catalogue item in the Recycle Bin is a copy that exists too, so it is renamed with the rest and a restore does not
     * bring back an old name). A row that holds one pointer changes only what exists; a record that merely has the same name is never looked at. Only what differs
     * is written, so a call that finds every copy in agreement sends no statement at all.
     *
     * `$identity` says what every copy must hold: `name` and/or `parent_id` (the Product the Sub-product sits under; null for none, which a Sub-product that has an
     * offer cannot do). A key that is absent is left alone. A parent change also gives the offer its group (the Product's Portfolio name) and the Sub-product's key
     * follows the Product when that key is free, as the Portfolio always did. `$masterOnly` are other columns of the MASTER row (the status, or the pointer a
     * take-over sets) that go in the same statement and are taken back with the rest on a failure.
     *
     * Nothing is swallowed here: a copy that cannot take the change (a blank name, a product that is not one of the organisation's Products, a Product with no
     * Portfolio name for an offer to sit under, a write that fails) throws, and the transaction takes back whatever was written before it. That is the difference from
     * the `through*` entry points, which stay GUARDED (they log and swallow) because the legacy endpoints that call them have already saved their own record and must
     * answer as before. A dry run (`$apply` false) reads, writes nothing, opens no transaction and returns what it would write.
     *
     * @param array{name?: string, parent_id?: int|null} $identity
     * @param array<string, mixed> $masterOnly
     * @return list<string> the tables written, in the order they were written: g2g_product_master, g2g_product_offers, crm_products (in a dry run: that would be written)
     *
     * @throws ProductMasterInvalid 404 the row is not one of this organisation's; 422 a copy cannot take the change
     * @throws \InvalidArgumentException a key other than name and parent_id (a mistake in the caller, not in the data)
     */
    public function mirror(int $tenant, int $masterId, array $identity, array $masterOnly = []): array
    {
        $unknown = array_diff(array_keys($identity), ['name', 'parent_id']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('mirror() writes a name and a parent_id, not: ' . implode(', ', $unknown));
        }
        if ($tenant <= 0 || ! $this->has('g2g_product_master')) {
            return [];
        }

        $name = null;
        if (array_key_exists('name', $identity)) {
            $name = $this->clip((string) $identity['name'], 191);
            if ($name === '') {
                throw ProductMasterInvalid::field('name', 'A product needs a name.');
            }
        }
        $wantParent = array_key_exists('parent_id', $identity);
        $parentId = $wantParent && $identity['parent_id'] !== null && (int) $identity['parent_id'] > 0 ? (int) $identity['parent_id'] : null;

        $work = fn (): array => $this->writeCopies($tenant, $masterId, $name, $wantParent, $parentId, $masterOnly);

        return $this->apply ? $this->db->transaction($work) : $work();
    }

    /**
     * What mirror() does inside its transaction: read the row (locked) and its other copies, decide what differs, write it.
     *
     * @param array<string, mixed> $masterOnly
     * @return list<string>
     */
    private function writeCopies(int $tenant, int $masterId, ?string $name, bool $wantParent, ?int $parentId, array $masterOnly): array
    {
        $query = $this->db->table('g2g_product_master')->where('id', $masterId)->where('sub_institute_id', $tenant);
        if ($this->apply) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw ProductMasterInvalid::field('id', 'That product is not one of yours.', 404);
        }
        if ($row->kind === ProductMaster::KIND_PRODUCT) {
            // A Product (level 1) has no offer and no catalogue item to mirror to; its name is the group its Sub-products are filed under and the label of its signal
            // product, two stores this method does not write. Renaming one stays unsupported (ProductWriter::updateProduct says so), and is refused here, not half done.
            throw ProductMasterInvalid::field('kind', 'A product is not renamed here: its name is the group its sub-products are filed under.');
        }

        // The other copies this row holds. The organisation's own only: a row that points at another organisation's offer or item (damage the checker calls I2)
        // never gets to write there.
        $offer = $row->offer_row_id !== null && $this->has('g2g_product_offers')
            ? $this->db->table('g2g_product_offers')->where('id', $row->offer_row_id)->where('sub_institute_id', $tenant)->first(['id', 'offer_id', 'name', 'parent_product'])
            : null;
        $hasLine = $this->has('crm_products') && ColumnExists::has('crm_products', 'product_master_id', $this->db);
        $crm = $row->crm_product_id !== null && $this->has('crm_products')
            ? $this->db->table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->first(array_merge(['id', 'name'], $hasLine ? ['product_master_id'] : []))
            : null;

        // The Product the record moves to: one of the organisation's own, and a Product (level 1), never a Sub-product or an item. An offer needs it
        // (its group is the Product's Portfolio name), so for a row that has one nothing less is accepted; a row with no offer keeps what it always did.
        $parent = null;
        if ($wantParent) {
            if ($parentId === null) {
                if ($offer !== null) {
                    throw ProductMasterInvalid::field('parent_id', 'A sub-product sits under a product. Choose one of your products.');
                }
            } else {
                $parent = $this->db->table('g2g_product_master')->where('id', $parentId)->where('kind', ProductMaster::KIND_PRODUCT)
                    ->where('sub_institute_id', $tenant)->first(['id', 'product_key', 'portfolio_name']);
                if ($parent === null && $offer !== null) {
                    throw ProductMasterInvalid::field('parent_id', 'Choose one of your products.');
                }
                if ($parent !== null && $offer !== null && trim((string) $parent->portfolio_name) === '') {
                    throw ProductMasterInvalid::field('parent_id', 'That product has no Portfolio name, so a sub-product with an offer cannot sit under it.');
                }
            }
        }

        // The master row.
        $set = [];
        foreach ($masterOnly as $column => $value) {
            if ($this->differs($row->{$column} ?? null, $value)) {
                $set[$column] = $value;
            }
        }
        if ($name !== null && (string) $row->name !== $name) {
            $set['name'] = $name;
        }
        if ($wantParent && (int) ($row->parent_id ?? 0) !== (int) ($parentId ?? 0)) {
            $set['parent_id'] = $parentId;
            if ($offer !== null && $parent !== null) {
                // The Sub-product follows its Product with the key that Product gives it, when that key is free.
                $key = self::subProductKey((string) $parent->product_key, (string) $offer->offer_id);
                if ($key !== null && (string) $row->product_key !== $key && ! $this->master($tenant)->where('product_key', $key)->where('id', '!=', $row->id)->exists()) {
                    $set['product_key'] = $key;
                }
            }
        }

        // The Portfolio offer: its name, and its group (compared the way the checker does: trimmed, any letter case).
        $offerSet = [];
        if ($offer !== null) {
            if ($name !== null && trim((string) $offer->name) !== $name) {
                $offerSet['name'] = $name;
            }
            if ($parent !== null && mb_strtolower(trim((string) $offer->parent_product)) !== mb_strtolower(trim((string) $parent->portfolio_name))) {
                $offerSet['parent_product'] = (string) $parent->portfolio_name;
            }
        }

        // The catalogue item: its name, and the Product its line names.
        $crmSet = [];
        if ($crm !== null) {
            if ($name !== null && trim((string) $crm->name) !== $name) {
                $crmSet['name'] = $name;
            }
            if ($wantParent && $hasLine && (int) ($crm->product_master_id ?? 0) !== (int) ($parentId ?? 0)) {
                $crmSet['product_master_id'] = $parentId;
            }
        }

        $written = array_keys(array_filter(['g2g_product_master' => $set, 'g2g_product_offers' => $offerSet, 'crm_products' => $crmSet], fn (array $changes): bool => $changes !== []));
        if (! $this->apply || $written === []) {
            return $written;
        }

        $now = now();
        if ($set !== []) {
            $this->db->table('g2g_product_master')->where('id', $row->id)->update($set + $this->stamp('g2g_product_master', $now));
        }
        if ($offerSet !== []) {
            $this->db->table('g2g_product_offers')->where('id', $offer->id)->update($offerSet + $this->stamp('g2g_product_offers', $now));
        }
        if ($crmSet !== []) {
            $this->db->table('crm_products')->where('id', $crm->id)->update($crmSet + $this->stamp('crm_products', $now));
        }

        return $written;
    }

    /** The columns that say who changed a row and when, for a copy the mirror writes (who: only when the caller knows the person, and the table has the column). @return array<string, mixed> */
    private function stamp(string $table, mixed $now): array
    {
        $stamp = ['updated_at' => $now];
        if ($this->actor !== null && $this->hasColumn($table, 'updated_by')) {
            $stamp['updated_by'] = $this->actor;
        }

        return $stamp;
    }

    /** Two column values are different (a NULL equals only a NULL; anything else is compared as text, so 7 and '7' are the same). */
    private function differs(mixed $current, mixed $want): bool
    {
        if ($current === null || $want === null) {
            return $current !== $want;
        }

        return (string) $current !== (string) $want;
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
                $count($this->repairing("catalogue item {$r->crm_product_id}", fn () => $this->catalogueItem($tenant, (int) $r->crm_product_id, self::SAVED)));
            }
            if ($r->offer_row_id !== null && ! isset($seen['offer'][(int) $r->offer_row_id])) {
                $seen['offer'][(int) $r->offer_row_id] = true;
                $count($this->repairing("offer {$r->offer_row_id}", fn () => $this->offer($tenant, (int) $r->offer_row_id, self::SAVED)));
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
                    $count($this->repairing("offer {$id}", fn () => $this->offer($tenant, (int) $id, self::SAVED)));
                }
            }
        }
        if ($this->has('crm_products')) {
            foreach ($this->db->table('crm_products')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->orderBy('id')->pluck('id') as $id) {
                if (! isset($seen['crm'][(int) $id])) {
                    $seen['crm'][(int) $id] = true;
                    $count($this->repairing("catalogue item {$id}", fn () => $this->catalogueItem($tenant, (int) $id, self::SAVED)));
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

    /**
     * One row of a repair pass. A row whose copies cannot all take the change (mirror() refuses: a blank name, a product that is not one of the organisation's) is
     * reported as an issue and the pass goes on with the next row, so one bad row cannot stop an organisation's repair. A failure of the database itself is not
     * caught: it stops the pass, as it always did.
     *
     * @param \Closure(): string $step
     */
    private function repairing(string $what, \Closure $step): string
    {
        try {
            return $step();
        } catch (ProductMasterInvalid $e) {
            $this->issues[] = "{$what}: {$e->getMessage()}, so it was skipped";

            return self::ACT_SKIPPED;
        }
    }

    // ---- plumbing ----

    /** The organisation's own product that carries a Portfolio group name; an active one before an archived one. */
    private function productByGroup(int $tenant, string $group): ?object
    {
        $want = strtolower(trim($group));
        if ($want === '') {
            return null;
        }
        $best = null;
        $rank = fn (object $r) => $r->status === ProductMaster::STATUS_ACTIVE ? 0 : 1;
        foreach ($this->db->table('g2g_product_master')->where('kind', ProductMaster::KIND_PRODUCT)->where('sub_institute_id', $tenant)->whereNotNull('portfolio_name')->orderBy('id')->get() as $r) {
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

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns[$table . '.' . $column] ??= $this->has($table) && ColumnExists::has($table, $column, $this->db);
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
