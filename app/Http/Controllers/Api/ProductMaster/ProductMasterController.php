<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\ProductMaster\ProductAccess;
use App\Domain\ProductMaster\ProductLinks;
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
 * The organisation always comes from the caller's token, never from the request. An organisation reads its own rows and the shared ones
 * (tenant 0, the built-in products); a record of another organisation is answered with 404, the same as one that does not exist.
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
        $extras = ['signals' => ProductLinks::signalsByOffer($t, $offerIds), 'icp' => ProductLinks::icpByOffer($t, $offerIds), 'liveCrm' => $this->liveCatalogueIds($rows, $t)];

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
                'shared' => (int) $p->sub_institute_id === ProductMaster::SHARED, 'signalKey' => $p->signal_key, 'portfolioName' => $p->portfolio_name,
                'subProductCount' => $subs->count(),
                'soldInCrmCount' => $subs->where('soldInCrm', true)->count(),
                'signalsFound' => $p->signal_key !== null ? (int) ($signals[$p->signal_key] ?? 0) : null,
                'icp' => $icpProducts[(int) $p->id] ?? null,
                'subProducts' => $subs->all(),
            ];
        }

        $items = $rows->where('kind', ProductMaster::KIND_ITEM)->filter($wanted)
            ->filter(fn ($i) => $q === '' || $this->matches($q, [$i->name, $i->product_key]))
            ->map(fn ($i) => ['id' => (int) $i->id, 'key' => $i->product_key, 'name' => $i->name, 'status' => $i->status, 'crmProductId' => $i->crm_product_id])->values()->all();

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

    /** Active product lines for pickers (kind = 'product'). */
    public function lines(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! Schema::hasTable('g2g_product_master')) {
            return response()->json(['status' => 1, 'data' => []]);
        }

        $rows = ProductMaster::visibleTo($identity['sub_institute_id'])
            ->where('kind', ProductMaster::KIND_PRODUCT)
            ->active()
            ->orderBy('name')
            ->get();

        $data = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'key' => $r->product_key,
            'name' => $r->name,
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
                'createProduct' => $screen('add') && ProductAccess::isAdministrator($identity),
                'createSubProduct' => $screen('add') && ProductAccess::canWriteOffers($identity, 'add'),
                'createItem' => $add,
                'sellInCrm' => $sell,
                'editSubProduct' => $screen('edit') && ProductAccess::canWriteOffers($identity, 'edit'),
                'editItem' => $edit,
                'archive' => $screen('delete'),
                'archiveProduct' => $screen('delete') && ProductAccess::isAdministrator($identity),
                // Restoring is behind the screen's edit right, not its delete right (see routes/product_master.php).
                'restore' => $screen('edit'),
                'restoreProduct' => $screen('edit') && ProductAccess::isAdministrator($identity),
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

        return response()->json(['status' => 1, 'data' => $this->record($row, $t)]);
    }

    /** Creates a product, a sub-product or a catalogue-only item. The body's "kind" says which (see ProductWriter). */
    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return $this->write(fn () => $this->record((new ProductWriter)->create($identity, $request->all()), $identity['sub_institute_id']), 201);
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

        return $this->write(fn () => $this->record((new ProductWriter)->update($identity, $row, $request->all()), $identity['sub_institute_id']), 200);
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

    /** What the record is used by: signals recorded for it, the price books that list it, and the quotes that include it. */
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

        $signals = null;
        if (Schema::hasTable('g2g_company_opportunities')) {
            $base = DB::table('g2g_company_opportunities')->where('sub_institute_id', $t)->where('is_sample', 0);
            if ($row->kind === ProductMaster::KIND_PRODUCT && $row->signal_key !== null) {
                $signals = (int) (clone $base)->where('business_fit', $row->signal_key)->count();
            } elseif ($row->kind === ProductMaster::KIND_SUB_PRODUCT && ($offer = $this->offerFor($row, $t)) !== null) {
                $signals = (int) (clone $base)->where('relevant_offer_id', $offer['offerId'])->count();
            }
        }

        $crm = $this->crmProductFor($row, $t);
        $priceBooks = [];
        $quotes = 0;
        if ($crm !== null) {
            if (Schema::hasTable('crm_price_book_items') && Schema::hasTable('crm_price_books')) {
                $priceBooks = DB::table('crm_price_book_items as i')->join('crm_price_books as b', 'b.id', '=', 'i.price_book_id')
                    ->where('i.product_id', $crm['id'])->where('b.sub_institute_id', $t)->whereNull('b.deleted_at')->orderBy('b.name')
                    ->get(['b.id', 'b.price_book_no', 'b.name', 'b.currency', 'b.is_active', 'i.list_price'])
                    ->map(fn ($p) => ['priceBookId' => (int) $p->id, 'priceBookNo' => $p->price_book_no, 'name' => $p->name, 'currency' => $p->currency, 'active' => (bool) $p->is_active, 'listPrice' => (float) $p->list_price])
                    ->all();
            }
            if (Schema::hasTable('crm_quote_line_items') && Schema::hasTable('crm_quotes')) {
                $quotes = (int) DB::table('crm_quote_line_items as l')->join('crm_quotes as q', 'q.id', '=', 'l.quote_id')
                    ->where('l.product_id', $crm['id'])->where('q.sub_institute_id', $t)->whereNull('q.deleted_at')->distinct()->count('q.id');
            }
        }

        // The ICP's state only (never its content): the same word the list shows.
        $icp = match ($row->kind) {
            ProductMaster::KIND_PRODUCT => ProductLinks::icpByProduct($t, collect([$row]))[(int) $row->id] ?? null,
            ProductMaster::KIND_SUB_PRODUCT => ($offer = $this->offerFor($row, $t)) !== null ? (ProductLinks::icpByOffer($t, [$offer['offerId']])[$offer['offerId']] ?? ProductLinks::ICP_NONE) : null,
            default => null,
        };

        return response()->json(['status' => 1, 'data' => [
            'signals' => $signals, 'icp' => $icp, 'priceBooks' => $priceBooks, 'quotes' => $quotes, 'basePrice' => $crm['unitPrice'] ?? null, 'currency' => $crm['currency'] ?? null,
        ]]);
    }

    /** One record as the API shows it: the row, its product and sub-products, its Portfolio offer and its CRM catalogue item. */
    private function record(ProductMaster $row, int $t): array
    {
        $parent = $row->parent_id !== null ? ProductMaster::visibleTo($t)->find($row->parent_id) : null;
        $children = $row->kind === ProductMaster::KIND_PRODUCT
            ? ProductMaster::visibleTo($t)->where('parent_id', $row->id)->orderBy('id')->get(['id', 'product_key', 'name', 'status'])
            : collect();

        return [
            'id' => (int) $row->id, 'key' => $row->product_key, 'name' => $row->name, 'kind' => $row->kind, 'status' => $row->status, 'origin' => $row->origin,
            'shared' => (int) $row->sub_institute_id === ProductMaster::SHARED, 'signalKey' => $row->signal_key, 'portfolioName' => $row->portfolio_name,
            'parent' => $parent ? ['id' => (int) $parent->id, 'key' => $parent->product_key, 'name' => $parent->name] : null,
            'subProducts' => $children->map(fn ($c) => ['id' => (int) $c->id, 'key' => $c->product_key, 'name' => $c->name, 'status' => $c->status])->values(),
            'offer' => $this->offerFor($row, $t),
            'crmProduct' => $this->crmProductFor($row, $t),
            'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at,
        ];
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

        return [
            'id' => (int) $s->id, 'key' => $s->product_key, 'name' => $s->name, 'status' => $s->status, 'origin' => $s->origin,
            'offerId' => $o?->offer_id, 'readiness' => $o?->readiness_status,
            // `crmItemType` (product or service) says which CRM screen the catalogue item opens in.
            'soldInCrm' => $sold, 'crmProductId' => $sold ? $s->crm_product_id : null, 'crmItemType' => $sold ? $extras['liveCrm'][(int) $s->crm_product_id] : null,
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

    /** @return array<string, int> business_fit value => signals of this organisation */
    private function signalCountsByProduct(int $tenant): array
    {
        if (! Schema::hasTable('g2g_company_opportunities')) {
            return [];
        }

        return DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('is_sample', 0)->whereNotNull('business_fit')
            ->selectRaw('business_fit as b, COUNT(*) as n')->groupBy('business_fit')->pluck('n', 'b')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * The Portfolio offer behind a row, with the About text the form edits. An organisation reads its own offers and the shared ones.
     *
     * @return array{id:int, offerId:string, name:string, product:?string, readiness:?string, readinessConfirmed:bool, about:array<string, mixed>}|null
     */
    private function offerFor(ProductMaster $row, int $tenant): ?array
    {
        if ($row->offer_row_id === null || ! Schema::hasTable('g2g_product_offers')) {
            return null;
        }
        $o = ProductOffer::query()->where('id', $row->offer_row_id)
            ->where(fn ($q) => $q->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id'))->first();

        return $o ? [
            'id' => (int) $o->id, 'offerId' => (string) $o->offer_id, 'name' => (string) $o->name, 'product' => $o->parent_product, 'readiness' => $o->readiness_status,
            'readinessConfirmed' => (bool) $o->readiness_confirmed,
            'about' => [
                'whatItDoes' => $o->what_it_does, 'modulesComponents' => $o->modules_components, 'deploymentModel' => $o->deployment_model,
                'proofPoints' => $o->proof_points, 'notes' => $o->notes,
                'needsSolved' => array_values((array) $o->needs_solved), 'primarySegments' => array_values((array) $o->primary_segments),
                'triggerSignals' => array_values((array) $o->trigger_signals),
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
