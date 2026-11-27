<?php

namespace App\Domain\ProductMaster;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Products\CustomProduct;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\SubProductIcp;
use App\Support\ColumnExists;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a Product, a Sub-product or an item, and what it costs first (S15 follow-up, 24 Nov 2026; contract section 4.1 and 4.3 of
 * Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md; the owner's decisions D-4 and D-5).
 *
 * `GET /api/product-master/{id}/removal` is {@see preview()}: what blocks the delete, in a plain sentence each, which Sub-products hang under a Product and whether each can go, and
 * the Products the Sub-products may be moved to. `POST /api/product-master/{id}/remove` is {@see remove()}.
 *
 * What blocks a delete (D-5, never data loss): the pointers the registry knows (ProductPointers) and the About's ICPs.
 *   sold_on_documents       a quote, sales order or invoice holds the Sell row of the record (the three document pointers at the catalogue id; the price book is gone since
 *                           S16 and its dormant rows are no block: ProductPointers::DORMANT)
 *   records_point_at_it     a lead, opportunity, campaign, ticket, asset, FAQ, bundle, signal, run, claim or outreach message points at the record, by its catalogue id, its master id or
 *                           the About's row number (the other pointers at the catalogue id, ProductPointers::toMaster() for the master id and toOffer() for the About)
 *   offer_in_use            an ICP that is not the About's own lists the About's offer id (the check PortfolioController::destroy makes; the About's OWN ICP rows are deleted with it,
 *                           so they are not "use": OfferIcpSync writes the offer's id into them)
 *   has_sub_products_blocked  (a Product only) at least one of its Sub-products is blocked, which matters only when they are to be deleted with it
 * The catalogue row stays in the count when it is already in the Recycle Bin: the documents still hold its number, and the master row is the only link from a document line back
 * to its Product page.
 *
 * What a delete removes, per row, all in ONE transaction (all or nothing, the checks are made again inside it, on the locked row): the master row, its About and the ICP rows keyed
 * to that About (the people they point at lose the pointer, as ProductCatalog::deleteProduct does), and its Sell row goes to the Recycle Bin (the soft delete of the CRM's own
 * "Delete" on the Products screen; never a hard delete, and only when nothing holds it, which the check above guarantees). A Product also removes its own custom row
 * (g2g_signal_products) and the ICPs of its product line, or, when it is a built-in Product, writes the organisation's `deleted` override so ProductCatalog::definitionsFor() stops
 * listing it. Its Sub-products are moved to another Product or deleted with it: the caller chooses (D-4), the code never decides.
 *
 * Rights (the caller's, per record, never the request's): the gate of archive (ProductWriter::setStatus) with the DELETE action: an administrator for a Product; for a Sub-product the
 * Portfolio's right when it has an About and the right of its own type on Products or Services when it has a Sell row (both when it has both, because deleting it bins the Sell row).
 * One line per record is written to the event store through the recorder (product.removed, crm.product.deleted for the Sell row), after the commit.
 *
 * THE COST. Found on the dev database (one query 150 to 250 ms, PHP limit 60 s): the preview of a Product with Sub-products answered 500 after 74 seconds, because every record asked
 * the pointer registry its own questions (about 40 queries a record). Now the Product and its Sub-products are loaded TOGETHER, once (load()): the Sell rows, the About rows, ONE
 * grouped query per pointer column over all their ids (ProductPointerCounts), the organisation's ICPs once, and the rights once per kind of right (ProductChangeRights). The deletes
 * are one statement per table over all the rows. The number of queries depends on the number of tables and not on the number of Sub-products (ProductRemovalQueryCountTest).
 */
final class ProductRemoval
{
    /** The catalogue pointers that are DOCUMENTS (code sold_on_documents); every other pointer is a record that points at it. */
    private const DOCUMENT_POINTERS = [
        'crm_quote_line_items.product_id', 'crm_sales_order_line_items.product_id', 'crm_invoice_line_items.product_id',
    ];

    /** @var ?list<array{id: int, key: string, name: string, line: string, offerIds: list<string>}> the organisation's ICPs, read once per load */
    private ?array $icps = null;

    /**
     * What the Product and its Sub-products hold, per master id, read in ONE batch by load().
     *
     * @var array<int, array{sell: ?object, offer: ?ProductOffer, catalogue: list<array<string, mixed>>, master: list<array<string, mixed>>, aboutRows: list<array<string, mixed>>}>
     */
    private array $data = [];

    private readonly ProductPointerCounts $counts;

    private readonly ProductChangeRights $rights;

    /** @var ?list<string> the columns of crm_products, read once */
    private ?array $catalogueColumns = null;

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public function __construct(private readonly array $identity)
    {
        $this->counts = new ProductPointerCounts;
        $this->rights = new ProductChangeRights($identity);
    }

    // ================================================================== the preview (4.1)

    /**
     * @return array{id: int, kind: string, name: string, canDelete: bool, blockedBy: list<array{code: string, count: int, message: string}>, subProducts: list<array<string, mixed>>, targets: list<array{id: int, name: string}>}
     *
     * @throws ProductRemovalRefused 403 the caller may not delete this record
     */
    public function preview(ProductMaster $row): array
    {
        $this->assertMayRemove($row);
        $isProduct = $row->kind === ProductMaster::KIND_PRODUCT;

        $children = $isProduct ? $this->children($row) : collect();
        $this->load($children->concat([$row]));
        $ignore = $isProduct ? $this->productLineIcpIds($row) : [];
        $subProducts = $children->map(fn (ProductMaster $child) => $this->subProductRow($child, $ignore))->values()->all();
        $blocked = $isProduct ? $this->productBlockers($row) : $this->recordBlockers($row, []);
        if ($isProduct && array_filter($subProducts, fn (array $s) => ! $s['canDelete']) !== []) {
            $blocked[] = $this->subProductsBlocked($subProducts);
        }

        return [
            'id' => (int) $row->id, 'kind' => (string) $row->kind, 'name' => (string) $row->name,
            'canDelete' => $blocked === [], 'blockedBy' => $blocked,
            'subProducts' => $subProducts,
            'targets' => $this->targets($row),
        ];
    }

    // ================================================================== the delete (4.3)

    /**
     * @param  array<string, mixed>  $body  for a Product with Sub-products: `subProducts` = `move` (with `toProductId`) or `delete`; for anything else nothing
     * @return array{removed: list<int>, moved: list<int>, toProductId: ?int}
     *
     * @throws ProductRemovalRefused 403 not allowed, 409 `blocked` something is in use (nothing was changed), 422 `choose_sub_products` or `invalid_target`
     */
    public function remove(ProductMaster $row, array $body): array
    {
        $this->assertMayRemove($row);

        return $row->kind === ProductMaster::KIND_PRODUCT ? $this->removeProduct($row, $body) : $this->removeRecord($row);
    }

    /** @return array{removed: list<int>, moved: list<int>, toProductId: ?int} */
    private function removeRecord(ProductMaster $row): array
    {
        $tenant = (int) $this->identity['sub_institute_id'];
        $events = new ProductChangeEvents($tenant, (int) $this->identity['user_id']);

        DB::transaction(function () use ($row, $tenant, $events): void {
            $locked = ProductMaster::query()->where('sub_institute_id', $tenant)->whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw ProductRemovalRefused::notFound();
            }
            // Read again inside the transaction, on the locked row: what blocks it NOW.
            $this->forget();
            $this->load(collect([$locked]));
            if (($blocked = $this->recordBlockers($locked, [])) !== []) {
                throw ProductRemovalRefused::blocked($this->blockedMessage('Sub-product', $blocked), $blocked);
            }
            $this->deleteRecords(collect([$locked]), $events, []);
            $events->flushAfterCommit();
        });

        return ['removed' => [(int) $row->id], 'moved' => [], 'toProductId' => null];
    }

    /** @param array<string, mixed> $body @return array{removed: list<int>, moved: list<int>, toProductId: ?int} */
    private function removeProduct(ProductMaster $row, array $body): array
    {
        $tenant = (int) $this->identity['sub_institute_id'];
        $mover = new ProductMover($this->identity);
        $choice = isset($body['subProducts']) && is_string($body['subProducts']) ? $body['subProducts'] : null;
        $children = $this->children($row);

        // What the person chose for the Sub-products, judged before anything else: a Product that has Sub-products is never deleted without the answer to "and them?".
        $target = null;
        if ($children->isNotEmpty()) {
            if (! in_array($choice, ['move', 'delete'], true)) {
                throw ProductRemovalRefused::invalid('choose_sub_products', 'Choose what happens to its Sub-products: move them to another Product, or delete them together with it.');
            }
            if ($choice === 'move') {
                $target = $mover->targetOf($body['toProductId'] ?? null, (int) $row->id);
            }
            // The right of each Sub-product: the types of their Sell rows in one query, each kind of right asked once.
            $types = $this->rights->sellTypes($children);
            foreach ($children as $child) {
                $type = $types[(int) $child->id] ?? null;
                $refusal = $choice === 'move' ? $this->rights->moveRefusal($child, $type) : $this->rights->removeRefusal($child, $type);
                if ($refusal !== null) {
                    throw ProductRemovalRefused::forbidden($refusal);
                }
            }
        } else {
            $choice = null;
        }

        $events = new ProductChangeEvents($tenant, (int) $this->identity['user_id']);

        return DB::transaction(function () use ($row, $tenant, $choice, $target, $mover, $events): array {
            $locked = ProductMaster::query()->where('sub_institute_id', $tenant)->whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw ProductRemovalRefused::notFound();
            }
            // Read again inside the transaction, on the locked row, ALL AT ONCE: what blocks it, and which Sub-products there are now.
            $this->forget();
            $children = $this->children($locked);
            $this->load($children->concat([$locked]));
            $ignore = $this->productLineIcpIds($locked);
            $subProducts = $children->map(fn (ProductMaster $child) => $this->subProductRow($child, $ignore))->values()->all();
            $blocked = $this->productBlockers($locked);
            if ($choice === 'delete' && array_filter($subProducts, fn (array $s) => ! $s['canDelete']) !== []) {
                $blocked[] = $this->subProductsBlocked($subProducts);
            }
            if ($blocked !== []) {
                throw ProductRemovalRefused::blocked($this->blockedMessage('Product', $blocked), $blocked, $subProducts);
            }

            $removed = [(int) $locked->id];
            $moved = [];
            if ($choice === 'move' && $target !== null) {
                $moved = $mover->moveRows($children, $target, $events)['moved'];
            } elseif ($choice === 'delete') {
                $this->deleteRecords($children, $events, ['withProduct' => (string) $locked->name]);
                foreach ($children as $child) {
                    $removed[] = (int) $child->id;
                }
            }
            $this->deleteProductRow($locked, $events, $choice, $target);
            $events->flushAfterCommit();

            return ['removed' => $removed, 'moved' => $moved, 'toProductId' => $target !== null ? (int) $target->id : null];
        });
    }

    // ================================================================== what is deleted

    /**
     * Sub-products and items together: their ICP rows, their Abouts, their Sell rows (to the Recycle Bin) and their master rows, ONE statement per table over all the rows. The history
     * lines are made per row, in the order the rows are given: the Sell row's line, then the row's own.
     *
     * @param  Collection<int, ProductMaster>  $rows
     * @param  array<string, mixed>  $facts
     */
    private function deleteRecords(Collection $rows, ProductChangeEvents $events, array $facts): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        $tenant = (int) $this->identity['sub_institute_id'];
        $userId = (int) $this->identity['user_id'];
        $this->load($rows);

        $offerIds = [];
        $icpIds = [];
        $binned = [];
        $sellIds = [];
        foreach ($rows as $row) {
            $d = $this->dataOf($row);
            if ($d['offer'] !== null) {
                $offerIds[] = (int) $d['offer']->id;
                $icpIds = array_merge($icpIds, $this->keyedIcpIds($d['offer']));
            }
            if ($d['sell'] !== null) {
                $sellIds[] = (int) $d['sell']->id;
            }
            if ($d['sell'] !== null && $d['sell']->deleted_at === null) {
                $binned[(int) $row->id] = (int) $d['sell']->id;
            }
        }
        $this->deleteIcps(array_values(array_unique($icpIds)));
        if ($offerIds !== []) {
            ProductOffer::query()->where('sub_institute_id', $tenant)->whereIn('id', $offerIds)->delete();
        }
        $deletedAt = now();
        if ($binned !== []) {
            $data = ['deleted_at' => $deletedAt];
            if (in_array('deleted_by', $this->catalogueColumns(), true)) {
                $data['deleted_by'] = $userId;
            }
            DB::table('crm_products')->where('sub_institute_id', $tenant)->whereIn('id', array_values($binned))->update($data);
        }
        // The dormant price-book rows of the Sell rows that go (S16: the Price Book is gone and nothing reads them; ProductPointers::DORMANT): one statement for all of them, never a block.
        if ($sellIds !== [] && Schema::hasTable('crm_price_book_items')) {
            DB::table('crm_price_book_items')->whereIn('product_id', $sellIds)->delete();
        }

        $parentIds = $rows->pluck('parent_id')->filter()->unique()->values()->all();
        $parentNames = $parentIds === [] ? collect() : ProductMaster::visibleTo($tenant)->whereIn('id', $parentIds)->pluck('name', 'id');
        DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->whereIn('id', $rows->pluck('id')->all())->delete();

        foreach ($rows as $row) {
            if (isset($binned[(int) $row->id])) {
                $events->sellBinned($binned[(int) $row->id], $deletedAt->toDateTimeString(), $userId);
            }
            $offer = $this->dataOf($row)['offer'];
            $events->removed($row, $facts + [
                'parentId' => $row->parent_id !== null ? (int) $row->parent_id : null, 'parentName' => $row->parent_id !== null ? ($parentNames[(int) $row->parent_id] ?? null) : null,
                'offerId' => $offer?->offer_id, 'crmProductId' => $row->crm_product_id !== null ? (int) $row->crm_product_id : null,
            ]);
        }
    }

    /** A Product: its custom row, or the `deleted` override of a built-in one; the ICPs of its product line; the pointer its Sell rows keep at it; the master row. */
    private function deleteProductRow(ProductMaster $row, ProductChangeEvents $events, ?string $choice, ?ProductMaster $target): void
    {
        $tenant = (int) $this->identity['sub_institute_id'];
        $key = (string) $row->product_key;

        if (Schema::hasTable('g2g_signal_products')) {
            if (ProductCatalog::isBuiltIn($key)) {
                // A built-in Product is defined in code, so it cannot be deleted from a table; the organisation's own `deleted` row is what ProductCatalog::definitionsFor() reads
                // to stop listing it (the same row ProductCatalog::deleteProduct writes). An override the organisation already has (a renamed built-in) is kept and marked deleted.
                $def = ProductCatalog::definitions()[$key];
                $existing = CustomProduct::query()->where('sub_institute_id', $tenant)->where('key', $key)->first();
                if ($existing !== null) {
                    $existing->update(['status' => 'deleted']);
                } else {
                    CustomProduct::create([
                        'sub_institute_id' => $tenant, 'key' => $key, 'label' => (string) $def['label'], 'business_fit' => (string) $def['business_fit'], 'icp_product_line' => (string) $def['icp_product_line'],
                        'portfolio_names' => (array) ($def['portfolio_names'] ?? [$def['label']]), 'status' => 'deleted', 'created_by' => (int) $this->identity['user_id'],
                    ]);
                }
            } else {
                CustomProduct::query()->where('sub_institute_id', $tenant)->where('key', $key)->delete();
            }
            ProductCatalog::forgetCustom();
        }

        $this->deleteIcps($this->productLineIcpIds($row));

        // The Sell rows of its Sub-products keep the number of their Product in `product_master_id`; a Product that is gone must not be left pointed at (a moved Sub-product's row
        // already points at its new Product, so what is left here is a row that went to the Recycle Bin with the Product).
        if (Schema::hasTable('crm_products') && in_array('product_master_id', $this->catalogueColumns(), true)) {
            DB::table('crm_products')->where('sub_institute_id', $tenant)->where('product_master_id', $row->id)->update(['product_master_id' => null]);
        }

        DB::table('g2g_product_master')->where('id', $row->id)->where('sub_institute_id', $tenant)->delete();
        $events->removed($row, [
            'subProducts' => $choice === null ? 'none' : ($choice === 'move' ? 'moved' : 'deleted'),
            'toProductId' => $target !== null ? (int) $target->id : null, 'toProductName' => $target?->name,
        ]);
    }

    /**
     * Deletes ICP rows with what hangs off them: the target accounts and research runs that were made for one lose the pointer (they are history, not use), the versions go.
     *
     * @param list<int> $ids
     */
    private function deleteIcps(array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable('g2g_icp_profiles')) {
            return;
        }
        $tenant = (int) $this->identity['sub_institute_id'];
        foreach (['g2g_target_accounts', 'g2g_research_runs'] as $table) {
            if (Schema::hasTable($table) && in_array('icp_profile_id', ColumnExists::all($table), true)) {
                DB::table($table)->where('sub_institute_id', $tenant)->whereIn('icp_profile_id', $ids)->update(['icp_profile_id' => null]);
            }
        }
        if (Schema::hasTable('g2g_icp_profile_versions')) {
            DB::table('g2g_icp_profile_versions')->whereIn('icp_profile_id', $ids)->delete();
        }
        IcpProfile::query()->where('sub_institute_id', $tenant)->whereIn('id', $ids)->delete();
    }

    // ================================================================== what blocks it

    /**
     * @param  list<int>  $ignoreIcpIds  ICP rows that are being deleted together with the record (the ICPs of the Product line of a Product whose Sub-products go with it)
     * @return list<array{code: string, count: int, message: string}>
     */
    private function recordBlockers(ProductMaster $row, array $ignoreIcpIds): array
    {
        $blocked = [];
        $documents = [];
        $records = [];

        $d = $this->dataOf($row);
        foreach ($d['catalogue'] as $used) {
            if (in_array($used['table'] . '.' . $used['column'], self::DOCUMENT_POINTERS, true)) {
                $documents[] = $used;
            } else {
                $records[] = $used;
            }
        }
        foreach ($d['master'] as $used) {
            $records[] = $used;
        }
        foreach ($d['aboutRows'] as $used) {
            $records[] = $used;
        }
        $offer = $d['offer'];

        if ($documents !== []) {
            $blocked[] = ['code' => 'sold_on_documents', 'count' => ProductUseCount::total($documents), 'message' => 'Documents hold it: ' . $this->words($documents) . '.'];
        }
        if ($records !== []) {
            $blocked[] = ['code' => 'records_point_at_it', 'count' => ProductUseCount::total($records), 'message' => 'Records point at it: ' . $this->words($records) . '.'];
        }
        if ($offer !== null && ($icps = $this->icpsListing($offer, $ignoreIcpIds)) !== []) {
            $blocked[] = ['code' => 'offer_in_use', 'count' => count($icps), 'message' => 'It is selected in an ideal customer profile (' . implode(', ', array_column($icps, 'name')) . '). Remove it from there first.'];
        }

        return $blocked;
    }

    /** @return list<array{code: string, count: int, message: string}> a Product has no Sell row and no About: only the records that name it */
    private function productBlockers(ProductMaster $row): array
    {
        $records = $this->dataOf($row)['master'];

        return $records === [] ? [] : [['code' => 'records_point_at_it', 'count' => ProductUseCount::total($records), 'message' => 'Records point at it: ' . $this->words($records) . '.']];
    }

    /**
     * The blocker a Product has when some of its Sub-products are in use: it names them, so the person need not hunt for which.
     *
     * @param  list<array{name: string, canDelete: bool}>  $subProducts  the Sub-product rows of the preview
     * @return array{code: string, count: int, message: string}
     */
    private function subProductsBlocked(array $subProducts): array
    {
        $names = array_column(array_filter($subProducts, fn (array $s) => ! $s['canDelete']), 'name');
        $count = count($names);
        $list = implode(', ', $names);

        return [
            'code' => 'has_sub_products_blocked', 'count' => $count,
            'message' => $count === 1 ? "One of its Sub-products is in use and cannot be deleted ({$list}). Move it to another Product instead." : "{$count} of its Sub-products are in use and cannot be deleted ({$list}). Move them to another Product instead.",
        ];
    }

    /** "quote lines: 3, tickets: 1": the pointers that hold something, by what a person calls them (one label once, with the sum). @param list<array{label: string, count: int}> $used */
    private function words(array $used): string
    {
        $byLabel = [];
        foreach ($used as $u) {
            $byLabel[$u['label']] = ($byLabel[$u['label']] ?? 0) + $u['count'];
        }

        return implode(', ', array_map(fn (string $label, int $count) => "{$label} {$count}", array_keys($byLabel), $byLabel));
    }

    /** @param list<array{code: string, count: int, message: string}> $blocked */
    private function blockedMessage(string $what, array $blocked): string
    {
        return "This {$what} is in use, so it cannot be deleted. " . implode(' ', array_column($blocked, 'message')) . ' You can archive it instead, which loses nothing.';
    }

    /**
     * The ICPs of the organisation that LIST the offer (config.offer_ids), except the About's own ICP rows (those are deleted with it) and the rows named in `$ignoreIds`.
     *
     * @param  list<int>  $ignoreIds
     * @return list<array{id: int, name: string}>
     */
    private function icpsListing(ProductOffer $offer, array $ignoreIds): array
    {
        $own = $this->ownIcpKeys($offer);
        $found = [];
        foreach ($this->icps() as $icp) {
            if (in_array($icp['id'], $ignoreIds, true) || in_array($icp['key'], $own, true)) {
                continue;
            }
            if (in_array((string) $offer->offer_id, $icp['offerIds'], true)) {
                $found[] = ['id' => $icp['id'], 'name' => $icp['name']];
            }
        }

        return $found;
    }

    /** @return list<array{id: int, key: string, name: string, line: string, offerIds: list<string>}> the organisation's ICPs: ONE read, however many records ask */
    private function icps(): array
    {
        if ($this->icps !== null) {
            return $this->icps;
        }
        $this->icps = [];
        if (Schema::hasTable('g2g_icp_profiles')) {
            foreach (DB::table('g2g_icp_profiles')->where('sub_institute_id', (int) $this->identity['sub_institute_id'])->get(['id', 'key', 'name', 'product_line', 'config']) as $icp) {
                $config = json_decode((string) $icp->config, true);
                $ids = is_array($config) ? (array) ($config['offer_ids'] ?? []) : [];
                $this->icps[] = [
                    'id' => (int) $icp->id, 'key' => (string) $icp->key, 'name' => (string) $icp->name, 'line' => (string) $icp->product_line,
                    'offerIds' => array_values(array_map('strval', array_filter($ids, 'is_scalar'))),
                ];
            }
        }

        return $this->icps;
    }

    /** @return list<string> the keys of the ICP rows an About owns (one per customer model, and the older base key) */
    private function ownIcpKeys(ProductOffer $offer): array
    {
        $id = (string) $offer->offer_id;

        return array_values(array_unique([SubProductIcp::key($id, 'b2b'), SubProductIcp::key($id, 'b2c'), SubProductIcp::key($id, 'b2g'), SubProductIcp::baseKey($id)]));
    }

    /** @return list<int> the ICP rows an About owns, from the one read of the ICPs */
    private function keyedIcpIds(ProductOffer $offer): array
    {
        $own = $this->ownIcpKeys($offer);

        return array_values(array_map(fn (array $icp): int => $icp['id'], array_filter($this->icps(), fn (array $icp): bool => in_array($icp['key'], $own, true))));
    }

    /** @return list<int> the ICP rows of a Product's own line (the seed copies and the line's profiles): they go with the Product */
    private function productLineIcpIds(ProductMaster $product): array
    {
        $key = (string) $product->product_key;
        $line = (string) (ProductCatalog::definitions()[$key]['icp_product_line'] ?? $key);
        $lines = array_values(array_unique([$line, $key]));

        return array_values(array_map(fn (array $icp): int => $icp['id'], array_filter($this->icps(), fn (array $icp): bool => in_array($icp['line'], $lines, true))));
    }

    // ================================================================== the one batch of reads

    /**
     * Reads what the given records hold, ALL AT ONCE (the cost does not depend on how many records there are): their Sell rows and About rows in one query each, and ONE grouped
     * query per pointer column over all their ids (ProductPointerCounts). A record already read is not read again; forget() drops everything (inside a transaction, after the lock).
     *
     * @param  Collection<int, ProductMaster>  $rows
     */
    private function load(Collection $rows): void
    {
        $rows = $rows->filter(fn (ProductMaster $r): bool => ! isset($this->data[(int) $r->id]))->unique(fn (ProductMaster $r): int => (int) $r->id)->values();
        if ($rows->isEmpty()) {
            return;
        }
        $tenant = (int) $this->identity['sub_institute_id'];

        $crmIds = $rows->pluck('crm_product_id')->filter()->unique()->values()->all();
        $sell = $crmIds !== [] && Schema::hasTable('crm_products')
            ? DB::table('crm_products')->where('sub_institute_id', $tenant)->whereIn('id', $crmIds)->get(['id', 'item_type', 'deleted_at'])->keyBy(fn ($r) => (int) $r->id)
            : collect();
        $offerIds = $rows->pluck('offer_row_id')->filter()->unique()->values()->all();
        $offers = $offerIds !== [] && Schema::hasTable('g2g_product_offers')
            ? ProductOffer::query()->where('sub_institute_id', $tenant)->whereIn('id', $offerIds)->get()->keyBy(fn (ProductOffer $o) => (int) $o->id)
            : collect();

        $atCatalogue = $this->counts->atCatalogueItems($sell->keys()->all());
        $atMaster = $this->counts->atMasters($rows->pluck('id')->all());
        $atAbout = $this->counts->atOffers($offers->keys()->all());

        foreach ($rows as $row) {
            $crm = $row->crm_product_id !== null ? $sell->get((int) $row->crm_product_id) : null;
            $offer = $row->offer_row_id !== null ? $offers->get((int) $row->offer_row_id) : null;
            $this->data[(int) $row->id] = [
                'sell' => $crm, 'offer' => $offer,
                'catalogue' => $crm !== null ? ($atCatalogue[(int) $crm->id] ?? []) : [],
                'master' => $atMaster[(int) $row->id] ?? [],
                'aboutRows' => $offer !== null ? ($atAbout[(int) $offer->id] ?? []) : [],
            ];
        }
    }

    /** Drops what load() read, so that the next load() reads it again (inside a transaction, on the locked rows). */
    private function forget(): void
    {
        $this->data = [];
        $this->icps = null;
    }

    /** @return array{sell: ?object, offer: ?ProductOffer, catalogue: list<array<string, mixed>>, master: list<array<string, mixed>>, aboutRows: list<array<string, mixed>>} */
    private function dataOf(ProductMaster $row): array
    {
        if (! isset($this->data[(int) $row->id])) {
            $this->load(collect([$row]));
        }

        return $this->data[(int) $row->id];
    }

    /** @return list<string> */
    private function catalogueColumns(): array
    {
        return $this->catalogueColumns ??= ColumnExists::all('crm_products');
    }

    // ================================================================== plumbing

    /** @return Collection<int, ProductMaster> the Sub-products and items under a Product, every status, by name */
    private function children(ProductMaster $product): Collection
    {
        return ProductMaster::visibleTo((int) $this->identity['sub_institute_id'])->where('parent_id', $product->id)->where('kind', '<>', ProductMaster::KIND_PRODUCT)
            ->orderBy('name')->orderBy('id')->get();
    }

    /** @param list<int> $ignoreIcpIds @return array{id: int, name: string, kind: string, status: string, canDelete: bool, blockedBy: list<array{code: string, count: int, message: string}>} */
    private function subProductRow(ProductMaster $child, array $ignoreIcpIds): array
    {
        $blocked = $this->recordBlockers($child, $ignoreIcpIds);

        return ['id' => (int) $child->id, 'name' => (string) $child->name, 'kind' => (string) $child->kind, 'status' => (string) $child->status, 'canDelete' => $blocked === [], 'blockedBy' => $blocked];
    }

    /**
     * The Products a Sub-product may be moved to: the organisation's other ACTIVE Products, never the record itself and, for a Sub-product, not the Product it is under already.
     *
     * @return list<array{id: int, name: string}>
     */
    private function targets(ProductMaster $row): array
    {
        $query = ProductMaster::visibleTo((int) $this->identity['sub_institute_id'])->active()->where('kind', ProductMaster::KIND_PRODUCT)->where('id', '<>', $row->id);
        if ($row->kind !== ProductMaster::KIND_PRODUCT && $row->parent_id !== null) {
            $query->where('id', '<>', $row->parent_id);
        }

        return $query->orderBy('name')->orderBy('id')->get(['id', 'name'])->map(fn (ProductMaster $p) => ['id' => (int) $p->id, 'name' => (string) $p->name])->values()->all();
    }

    /**
     * A record of another organisation is a 404, the same as one that is not there. The controller looks every id up in the caller's own organisation already; this is the second
     * lock on the same door, so a caller that hands this class a row it did not look up itself (a console command, a later route) still cannot reach another organisation's record.
     *
     * @throws ProductRemovalRefused 403 not allowed, 404 not this organisation's
     */
    private function assertMayRemove(ProductMaster $row): void
    {
        if ((int) $row->sub_institute_id !== (int) $this->identity['sub_institute_id']) {
            throw ProductRemovalRefused::notFound();
        }
        $type = $row->kind === ProductMaster::KIND_PRODUCT ? null : ($this->rights->sellTypes(collect([$row]))[(int) $row->id] ?? null);
        if (($refusal = $this->rights->removeRefusal($row, $type)) !== null) {
            throw ProductRemovalRefused::forbidden($refusal);
        }
    }
}
