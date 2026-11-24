<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\ProductMaster\CatalogueFields;
use App\Domain\ProductMaster\ProductAccess;
use App\Domain\ProductMaster\ProductAlias;
use App\Domain\ProductMaster\ProductHistory;
use App\Domain\ProductMaster\ProductLinks;
use App\Domain\ProductMaster\ProductListQuery;
use App\Domain\ProductMaster\ProductPicker;
use App\Domain\ProductMaster\ProductReadRights;
use App\Domain\ProductMaster\ProductReadSupport;
use App\Domain\ProductMaster\ProductUsage;
use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductWriter;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Product Master screen's read API (slice 1, Docs/agentic-crm/slices/S01-product-master.md).
 *
 * The organisation always comes from the caller's token, never from the request. An organisation reads its own rows and nothing else (S15 follow-up, D-1: no row is
 * shared between organisations); a record of another organisation is answered with 404, the same as one that does not exist.
 * A sub-product's content stays in the Product Portfolio and its price and SKU in the CRM catalogue; this API only reads them side by side.
 */
class ProductMasterController extends Controller
{
    use ResolvesApiIdentity;

    /** The list: products with their sub-products nested, then the catalogue-only items. ?status=active|archived|all (default active), ?q=text. */
    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        // A host that has not had the Product Master database yet answers an empty, honest list instead of an error (the Products screen reads this).
        if (! Schema::hasTable('g2g_product_master')) {
            return response()->json(['status' => 1, 'data' => ['available' => false, 'products' => [], 'items' => [], 'totals' => ['products' => 0, 'subProducts' => 0, 'items' => 0]]]);
        }

        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? (string) $request->query('status') : 'active';
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        // Every row the organisation can see; the status filter is applied per row below, so a product that is itself active still shows
        // the archived sub-products under it when ?status=archived, and an archived product still shows its active ones.
        $rows = ProductMaster::visibleTo($t)->orderBy('id')->get();
        $wanted = fn (ProductMaster $r) => $status === 'all' || $r->status === $status;
        $offers = $this->offersFor($rows);
        $signals = $this->signalCountsByProduct($t);
        // The Products screen's extra columns: the ICP state of each product and sub-product, and the Signals found per sub-product. A catalogue item that
        // sits in the Recycle Bin is not "sold in CRM" any more.
        $icpProducts = ProductLinks::icpByProduct($t, $rows->where('kind', ProductMaster::KIND_PRODUCT));
        $offerIds = $offers->pluck('offer_id')->map(fn ($o) => (string) $o)->all();
        // The ids of the Sell side (the catalogue row's id and type) go only to a caller who may view the Sell section of that type (slice 15, P2-E1): the names and ids of the
        // records themselves are open to every signed-in user, so HR can open the Portfolio. `types` is every catalogue row's type, also one in the Recycle Bin.
        $extras = ['signals' => ProductLinks::signalsByOffer($t, $offerIds), 'icp' => ProductLinks::icpByOffer($t, $offerIds), 'liveCrm' => $this->liveCatalogueIds($rows, $t), 'rights' => new ProductReadRights($identity), 'types' => $this->catalogueTypes($rows, $t)];

        $subsByParent = $rows->where('kind', ProductMaster::KIND_SUB_PRODUCT)->filter($wanted)->groupBy('parent_id');
        $products = [];
        foreach ($rows->where('kind', ProductMaster::KIND_PRODUCT) as $p) {
            $subs = ($subsByParent[$p->id] ?? collect())->map(fn ($s) => $this->subProductRow($s, $offers, $extras))->values();
            if (! $wanted($p) && $subs->isEmpty()) {
                continue;
            }
            if ($q !== '') {
                $own = $this->matches($q, [$p->name, $p->product_key, $p->portfolio_name]);
                $subs = $own ? $subs : $subs->filter(fn ($s) => $this->matches($q, [$s['name'], $s['key'], $s['offerId']]))->values();
                if (! $own && $subs->isEmpty()) {
                    continue;
                }
            }
            $products[] = [
                'id' => (int) $p->id, 'key' => $p->product_key, 'name' => $p->name, 'kind' => $p->kind, 'status' => $p->status, 'origin' => $p->origin,
                'shared' => false, 'signalKey' => $p->signal_key, 'portfolioName' => $p->portfolio_name,
                'subProductCount' => $subs->count(),
                'soldInCrmCount' => $subs->where('soldInCrm', true)->count(),
                'signalsFound' => $p->signal_key !== null ? (int) ($signals[$p->signal_key] ?? 0) : null,
                'icp' => $icpProducts[(int) $p->id] ?? null,
                'subProducts' => $subs->all(),
            ];
        }

        $items = $rows->where('kind', ProductMaster::KIND_ITEM)->filter($wanted)
            ->filter(fn ($i) => $q === '' || $this->matches($q, [$i->name, $i->product_key]))
            ->map(fn ($i) => ['id' => (int) $i->id, 'key' => $i->product_key, 'name' => $i->name, 'status' => $i->status, 'crmProductId' => $extras['rights']->canViewSell($extras['types'][(int) $i->crm_product_id] ?? null) ? $i->crm_product_id : null])->values()->all();

        return response()->json(['status' => 1, 'data' => [
            'available' => true, 'products' => $products, 'items' => $items,
            'totals' => ['products' => count($products), 'subProducts' => array_sum(array_column($products, 'subProductCount')), 'items' => count($items)],
        ]]);
    }

    /** A flat list for pickers: every active product, sub-product and item the organisation can use. */
    public function options(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! Schema::hasTable('g2g_product_master')) {
            return response()->json(['status' => 1, 'data' => []]);
        }

        $rows = ProductMaster::visibleTo($identity['sub_institute_id'])->active()->orderBy('id')->get();
        $names = $rows->pluck('name', 'id');
        $data = $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'key' => $r->product_key, 'name' => $r->name, 'kind' => $r->kind, 'parentId' => $r->parent_id,
            'label' => $r->parent_id !== null && isset($names[$r->parent_id]) ? $names[$r->parent_id] . ' › ' . $r->name : $r->name,
        ])->values();

        return response()->json(['status' => 1, 'data' => $data]);
    }

    /**
     * What the screen's forms may offer and what the caller may do. The rules are the ones ProductWriter enforces (it calls the same ProductAccess
     * methods), so the screen can hide what would be refused; the server still refuses it. Tax rates are listed only to someone who can write the
     * CRM catalogue, so the list does not leak to a profile that could never use it.
     */
    public function meta(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $catalogue = fn (string $type, string $action) => ProductAccess::canWriteCatalogue($identity, $type, $action);
        $screen = fn (string $action) => ProductAccess::canUseScreen($identity, $action);
        $add = ['product' => $screen('add') && $catalogue('product', 'add'), 'service' => $screen('add') && $catalogue('service', 'add')];
        $edit = ['product' => $screen('edit') && $catalogue('product', 'edit'), 'service' => $screen('edit') && $catalogue('service', 'edit')];
        $sell = ['product' => $catalogue('product', 'add'), 'service' => $catalogue('service', 'add')];
        $writeRights = (new ProductReadRights($identity))->can();
        $mayChange = fn (string $action) => ProductAccess::isAdministrator($identity) || ProductAccess::canWriteOffers($identity, $action)
            || $catalogue('product', $action) || $catalogue('service', $action);

        $taxRates = [];
        if (in_array(true, [...$sell, $catalogue('product', 'edit'), $catalogue('service', 'edit')], true) && Schema::hasTable('crm_tax_rates')) {
            $taxRates = DB::table('crm_tax_rates')->where('sub_institute_id', $identity['sub_institute_id'])->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'percentage'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'percentage' => (float) $r->percentage])->all();
        }

        return response()->json(['status' => 1, 'data' => [
            'readinessStatuses' => ProductWriter::readinessStatuses(),
            'itemTypes' => ['product', 'service'],
            'taxRates' => $taxRates,
            'can' => [
                // The three flags that say who may write follow the WRITER's own rules (ProductWriter judges by section, and the create and update routes ask only for a token):
                // a Product is an administrator's; a Sub-product needs the right to add (or edit) its About or its Sell. They used to say "the Products screen's right AND the
                // Portfolio's", which hid the buttons from HR, who write the Portfolio and hold no row on Products (slice 15, P2-E1).
                'createProduct' => $writeRights['createProduct'],
                'createSubProduct' => $writeRights['createSubProduct'],
                'createItem' => $add,
                'sellInCrm' => $sell,
                'editSubProduct' => $writeRights['editSubProduct'],
                'editItem' => $edit,
                // Archive and restore are judged by SECTION (slice 15, WPA1, D-E1; ProductWriter::setStatus): `archive` / `restore` say the caller may archive / restore SOME kind of
                // record (a Product, a Sub-product with an About, an item of a type it holds the right for); the server decides for each record, and archiving takes the delete
                // action and restoring the edit action. They used to follow the Products row, which hid the buttons from HR.
                'archive' => $mayChange('delete'),
                'archiveProduct' => ProductAccess::isAdministrator($identity),
                'restore' => $mayChange('edit'),
                'restoreProduct' => ProductAccess::isAdministrator($identity),
            ],
        ]]);
    }

    /** One record with the Portfolio offer and the CRM catalogue item it points at (each only when it belongs to the caller's organisation). */
    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $row = ProductMaster::visibleTo($t)->find($id);
        if (! $row) {
            return $this->notFound();
        }

        // Names, the Portfolio's About and the ICP state are open to every signed-in user of the organisation (HR opens the Portfolio). `sell` and the drawer's old
        // `crmProduct` carry prices, cost and commission, so they are filled only for a caller who may view the Sell section of the record's type (null otherwise, the
        // keys are always there). Slice 15, P2-E1.
        $record = $this->record($row, $t, $identity);
        $record['about'] = $record['offer']['about'] ?? null;
        $record['icp'] = ProductReadSupport::icpState($row, $t, $record['offer']['offerId'] ?? null);
        // A husk (the catalogue item a pair merge retired) names the record it was merged into; null for every other record (slice 15, WPB, G-E4).
        $record['mergedInto'] = ProductAlias::mergedInto($row, $t);

        return response()->json(['status' => 1, 'data' => $record]);
    }

    /**
     * The paged, flat list the unified Product Portfolio reads (see ProductListQuery for the row, the filters and the sorts). `?page`, `?per` (5 to 100, default 20),
     * `?q`, `?productId`, `?type`, `?sold`, `?readiness`, `?status`, `?sort`, `?dir`. The organisation always comes from the token.
     */
    public function list(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $result = (new ProductListQuery($identity, new ProductReadRights($identity)))->run($request->query());

        return response()->json(['status' => 1, 'data' => $result['rows'], 'meta' => $result['meta']]);
    }

    /** The Sell section of a record, for a caller who may view it (403 otherwise): the whole catalogue row, also one in the Recycle Bin, through CatalogueFields::toSell. */
    public function sell(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $row = ProductMaster::visibleTo($identity['sub_institute_id'])->find($id);
        if (! $row) {
            return $this->notFound();
        }
        // A caller who may view no Sell section of this record's type is refused without being told whether there is one.
        if (! (new ProductReadRights($identity))->canViewSellOf($row)) {
            return $this->forbidden('You do not have the right to view the Sell section of this product.');
        }

        return response()->json(['status' => 1, 'data' => $this->sellFor($row, $identity['sub_institute_id'], $identity)]);
    }

    /** One list of the changes of the record in its three stores, newest first (see ProductHistory). `?page`, `?per`. */
    public function history(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $row = ProductMaster::visibleTo($identity['sub_institute_id'])->find($id);
        if (! $row) {
            return $this->notFound();
        }
        $result = (new ProductHistory($identity['sub_institute_id'], new ProductReadRights($identity)))
            ->page($row, ProductReadSupport::page($request->query('page')), ProductReadSupport::perPage($request->query('per')));

        return response()->json(['status' => 1, 'data' => $result['rows'], 'meta' => $result['meta']]);
    }

    /**
     * The record that holds a catalogue id, also one in the Recycle Bin: `{id, kind}` of the master row. It serves the permanent redirects of the old product addresses
     * (`/products/{crmId}`, saved Ask answers). The organisation's own rows only: another organisation's catalogue id is a 404, the same as an id nobody has.
     */
    public function byCrm(Request $request, int $crmId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! Schema::hasTable('g2g_product_master')) {
            return $this->notFound();
        }
        // The master table's unique key (organisation, crm_product_id) means at most one row holds a catalogue id; a merged-away husk gives its pointer up.
        $row = ProductMaster::where('sub_institute_id', $identity['sub_institute_id'])->where('crm_product_id', $crmId)->first();
        if (! $row) {
            return $this->notFound();
        }

        return response()->json(['status' => 1, 'data' => ['id' => (int) $row->id, 'kind' => $row->kind, 'mergedInto' => ProductAlias::mergedInto($row, (int) $identity['sub_institute_id'])]]);
    }

    /** Creates a product, a sub-product or a catalogue-only item. The body's "kind" says which (see ProductWriter). */
    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return $this->write(fn () => $this->record((new ProductWriter)->create($identity, $request->all()), $identity['sub_institute_id'], $identity), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $row = ProductMaster::visibleTo($identity['sub_institute_id'])->find($id);
        if (! $row) {
            return $this->notFound();
        }

        return $this->write(fn () => $this->record((new ProductWriter)->update($identity, $row, $request->all()), $identity['sub_institute_id'], $identity), 200);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->changeStatus($request, $id, ProductMaster::STATUS_ARCHIVED);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->changeStatus($request, $id, ProductMaster::STATUS_ACTIVE);
    }

    private function changeStatus(Request $request, int $id, string $status): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $row = ProductMaster::visibleTo($identity['sub_institute_id'])->find($id);
        if (! $row) {
            return $this->notFound();
        }

        return $this->write(fn () => $this->record((new ProductWriter)->setStatus($identity, $row, $status), $identity['sub_institute_id']), 200);
    }

    /** Runs a write and turns a refusal into the JSON the screen shows next to the fields. Nothing has been written when it refuses. */
    private function write(\Closure $do, int $okStatus): JsonResponse
    {
        try {
            return response()->json(['status' => 1, 'data' => $do()], $okStatus);
        } catch (ProductMasterInvalid $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage(), 'errors' => $e->errors], $e->status);
        }
    }

    /**
     * What the record is used by: the signals and ICP of it, and, for a record with a catalogue row, the price books, quotes, orders, invoices, purchase orders, opportunities,
     * tickets, assets, FAQs, bundles and campaigns that hold it, with the newest five quotes, orders and invoices (see ProductUsage). Sell side only: 403 for anybody else.
     */
    public function usage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $row = ProductMaster::visibleTo($t)->find($id);
        if (! $row) {
            return $this->notFound();
        }

        // Only a person who may view the Sell side of this record's type gets any of it (slice 15, P2-E1): what documents use an item is part of its Sell side.
        if (! (new ProductReadRights($identity))->canViewSellOf($row)) {
            return $this->forbidden('You do not have the right to view how this product is used.');
        }

        return response()->json(['status' => 1, 'data' => (new ProductUsage($t))->forRecord($row)]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], 403);
    }

    /**
     * One record as the API shows it: the row, its product and sub-products, its Portfolio offer and its CRM catalogue item.
     *
     * `$viewer` is the caller's identity, passed by the writes (slice 15, P2-C, rule 5): such an answer carries `sell` (every Sell field, with the state sold, paused or
     * binned, and the CRM id the image, custom field and bundle calls are keyed by) and the drawer's old `crmProduct` ONLY when the caller may view the Sell section of that
     * item's type, so a person who writes just the Portfolio (HR) is never shown a price, a cost or a commission. A read passes no viewer and is answered as it always was
     * (its route asks for the Products row's view right; the reads are re-gated in P2.5).
     *
     * @param  ?array{user: object, user_id: int, sub_institute_id: int}  $viewer
     */
    private function record(ProductMaster $row, int $t, ?array $viewer = null): array
    {
        $parent = $row->parent_id !== null ? ProductMaster::visibleTo($t)->find($row->parent_id) : null;
        $children = $row->kind === ProductMaster::KIND_PRODUCT
            ? ProductMaster::visibleTo($t)->where('parent_id', $row->id)->orderBy('id')->get(['id', 'product_key', 'name', 'status'])
            : collect();

        $record = [
            'id' => (int) $row->id, 'key' => $row->product_key, 'name' => $row->name, 'kind' => $row->kind, 'status' => $row->status, 'origin' => $row->origin,
            'shared' => false, 'signalKey' => $row->signal_key, 'portfolioName' => $row->portfolio_name,
            'parent' => $parent ? ['id' => (int) $parent->id, 'key' => $parent->product_key, 'name' => $parent->name] : null,
            'subProducts' => $children->map(fn ($c) => ['id' => (int) $c->id, 'key' => $c->product_key, 'name' => $c->name, 'status' => $c->status])->values(),
            'offer' => $this->offerFor($row, $t),
            'crmProduct' => $this->crmProductFor($row, $t),
            'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at,
        ];
        if ($viewer !== null) {
            $record['sell'] = $this->sellFor($row, $t, $viewer);
            if ($record['sell'] === null) {
                $record['crmProduct'] = null;
            }
        }

        return $record;
    }

    /**
     * The Sell section of a record: its catalogue row with every field, also when the row is in the Recycle Bin (its state is part of the answer, so the screen can offer
     * "bring it back"). Null when the record has no catalogue row, or the caller may not view that type's row.
     *
     * @param  array{user: object, user_id: int, sub_institute_id: int}  $viewer
     * @return ?array<string, mixed>
     */
    private function sellFor(ProductMaster $row, int $tenant, array $viewer): ?array
    {
        if ($row->crm_product_id === null || ! Schema::hasTable('crm_products')) {
            return null;
        }
        $crm = DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->first();

        return $crm !== null && ProductAccess::canViewCatalogue($viewer, (string) $crm->item_type) ? CatalogueFields::toSell($crm) : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Product not found'], 404);
    }

    /** @param Collection<int, ProductMaster> $rows @return Collection<int, object> the Portfolio offers behind the rows, by offer row id */
    private function offersFor(Collection $rows): Collection
    {
        $ids = $rows->pluck('offer_row_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! Schema::hasTable('g2g_product_offers')) {
            return collect();
        }

        return DB::table('g2g_product_offers')->whereIn('id', $ids)->get(['id', 'offer_id', 'name', 'parent_product', 'readiness_status'])->keyBy('id');
    }

    /** @param array{signals: array<string,int>, icp: array<string,string>, liveCrm: array<int,string>} $extras */
    private function subProductRow(ProductMaster $s, Collection $offers, array $extras): array
    {
        $o = $s->offer_row_id !== null ? $offers->get($s->offer_row_id) : null;
        $sold = $s->crm_product_id !== null && isset($extras['liveCrm'][(int) $s->crm_product_id]);
        $offerId = $o !== null ? (string) $o->offer_id : null;
        $mayView = $sold && $extras['rights']->canViewSell($extras['liveCrm'][(int) $s->crm_product_id]);

        return [
            'id' => (int) $s->id, 'key' => $s->product_key, 'name' => $s->name, 'status' => $s->status, 'origin' => $s->origin,
            'offerId' => $o?->offer_id, 'readiness' => $o?->readiness_status,
            // `crmItemType` (product or service) says which CRM screen the catalogue item opens in.
            'soldInCrm' => $sold, 'crmProductId' => $mayView ? $s->crm_product_id : null, 'crmItemType' => $mayView ? $extras['liveCrm'][(int) $s->crm_product_id] : null,
            'signalsFound' => $offerId !== null ? (int) ($extras['signals'][$offerId] ?? 0) : null,
            'icp' => $offerId !== null ? ($extras['icp'][$offerId] ?? ProductLinks::ICP_NONE) : null,
        ];
    }

    /** @param Collection<int, ProductMaster> $rows @return array<int, string> the catalogue items behind the rows that are not in the Recycle Bin: id => item type */
    private function liveCatalogueIds(Collection $rows, int $tenant): array
    {
        $ids = $rows->pluck('crm_product_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! Schema::hasTable('crm_products')) {
            return [];
        }

        return DB::table('crm_products')->whereIn('id', $ids)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->pluck('item_type', 'id')
            ->mapWithKeys(fn ($type, $id) => [(int) $id => (string) $type])->all();
    }

    /** @param Collection<int, ProductMaster> $rows @return array<int, string> the type (product or service) of every catalogue row behind the rows, also one in the Recycle Bin: id => item type */
    private function catalogueTypes(Collection $rows, int $tenant): array
    {
        $ids = $rows->pluck('crm_product_id')->filter()->unique()->values()->all();
        if ($ids === [] || ! Schema::hasTable('crm_products')) {
            return [];
        }

        return DB::table('crm_products')->whereIn('id', $ids)->where('sub_institute_id', $tenant)->pluck('item_type', 'id')
            ->mapWithKeys(fn ($type, $id) => [(int) $id => (string) $type])->all();
    }

    /** @return array<string, int> business_fit value => signals of this organisation */
    private function signalCountsByProduct(int $tenant): array
    {
        return ProductReadSupport::signalsByProductKey($tenant);
    }

    /**
     * The Portfolio offer behind a row, with the About text the form edits. An organisation reads its own offers only.
     *
     * @return array{id:int, offerId:string, name:string, product:?string, readiness:?string, readinessConfirmed:bool, about:array<string, mixed>}|null
     */
    private function offerFor(ProductMaster $row, int $tenant): ?array
    {
        if ($row->offer_row_id === null || ! Schema::hasTable('g2g_product_offers')) {
            return null;
        }
        $o = ProductOffer::query()->where('id', $row->offer_row_id)->where('sub_institute_id', $tenant)->first();

        return $o ? [
            'id' => (int) $o->id, 'offerId' => (string) $o->offer_id, 'name' => (string) $o->name, 'product' => $o->parent_product, 'readiness' => $o->readiness_status,
            'readinessConfirmed' => (bool) $o->readiness_confirmed,
            'about' => [
                'whatItDoes' => $o->what_it_does, 'modulesComponents' => $o->modules_components, 'deploymentModel' => $o->deployment_model,
                'proofPoints' => $o->proof_points, 'notes' => $o->notes,
                'needsSolved' => array_values((array) $o->needs_solved), 'primarySegments' => array_values((array) $o->primary_segments),
                'triggerSignals' => array_values((array) $o->trigger_signals),
                // The other five fields of the About section (slice 15, P2-C); oftenSoldTogether is the offer's bundles_with.
                'pricingModel' => $o->pricing_model, 'typicalDealBand' => $o->typical_deal_band, 'implementationEffort' => $o->implementation_effort,
                'deliveryOwner' => $o->delivery_owner, 'oftenSoldTogether' => array_values((array) $o->bundles_with),
            ],
        ] : null;
    }

    /** @return array{id:int, productNo:?string, name:string, sku:?string, type:?string, glAccount:?string, unitPrice:?float, currency:?string, taxRateId:?int, active:bool}|null */
    private function crmProductFor(ProductMaster $row, int $tenant): ?array
    {
        if ($row->crm_product_id === null || ! Schema::hasTable('crm_products')) {
            return null;
        }
        $p = DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->whereNull('deleted_at')
            ->first(['id', 'product_no', 'name', 'sku', 'item_type', 'gl_account', 'unit_price', 'currency', 'tax_rate_id', 'is_active']);

        return $p ? [
            'id' => (int) $p->id, 'productNo' => $p->product_no, 'name' => (string) $p->name, 'sku' => $p->sku, 'type' => $p->item_type, 'glAccount' => $p->gl_account,
            'unitPrice' => $p->unit_price !== null ? (float) $p->unit_price : null, 'currency' => $p->currency,
            'taxRateId' => $p->tax_rate_id !== null ? (int) $p->tax_rate_id : null, 'active' => (bool) $p->is_active,
        ] : null;
    }

    /** @param list<?string> $haystack */
    private function matches(string $needle, array $haystack): bool
    {
        foreach ($haystack as $h) {
            if ($h !== null && str_contains(mb_strtolower($h), $needle)) {
                return true;
            }
        }

        return false;
    }
}
