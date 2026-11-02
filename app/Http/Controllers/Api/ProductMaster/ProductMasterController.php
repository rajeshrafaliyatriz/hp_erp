<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\ProductMaster\ProductMaster;
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

        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? (string) $request->query('status') : 'active';
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        // Every row the organisation can see; the status filter is applied per row below, so a product that is itself active still shows
        // the archived sub-products under it when ?status=archived, and an archived product still shows its active ones.
        $rows = ProductMaster::visibleTo($t)->orderBy('id')->get();
        $wanted = fn (ProductMaster $r) => $status === 'all' || $r->status === $status;
        $offers = $this->offersFor($rows);
        $signals = $this->signalCountsByProduct($t);

        $subsByParent = $rows->where('kind', ProductMaster::KIND_SUB_PRODUCT)->filter($wanted)->groupBy('parent_id');
        $products = [];
        foreach ($rows->where('kind', ProductMaster::KIND_PRODUCT) as $p) {
            $subs = ($subsByParent[$p->id] ?? collect())->map(fn ($s) => $this->subProductRow($s, $offers))->values();
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
                'subProducts' => $subs->all(),
            ];
        }

        $items = $rows->where('kind', ProductMaster::KIND_ITEM)->filter($wanted)
            ->filter(fn ($i) => $q === '' || $this->matches($q, [$i->name, $i->product_key]))
            ->map(fn ($i) => ['id' => (int) $i->id, 'key' => $i->product_key, 'name' => $i->name, 'status' => $i->status, 'crmProductId' => $i->crm_product_id])->values()->all();

        return response()->json(['status' => 1, 'data' => [
            'products' => $products, 'items' => $items,
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

        $rows = ProductMaster::visibleTo($identity['sub_institute_id'])->active()->orderBy('id')->get();
        $names = $rows->pluck('name', 'id');
        $data = $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'key' => $r->product_key, 'name' => $r->name, 'kind' => $r->kind, 'parentId' => $r->parent_id,
            'label' => $r->parent_id !== null && isset($names[$r->parent_id]) ? $names[$r->parent_id] . ' › ' . $r->name : $r->name,
        ])->values();

        return response()->json(['status' => 1, 'data' => $data]);
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

        $parent = $row->parent_id !== null ? ProductMaster::visibleTo($t)->find($row->parent_id) : null;
        $children = $row->kind === ProductMaster::KIND_PRODUCT
            ? ProductMaster::visibleTo($t)->where('parent_id', $row->id)->orderBy('id')->get(['id', 'product_key', 'name', 'status'])
            : collect();

        return response()->json(['status' => 1, 'data' => [
            'id' => (int) $row->id, 'key' => $row->product_key, 'name' => $row->name, 'kind' => $row->kind, 'status' => $row->status, 'origin' => $row->origin,
            'shared' => (int) $row->sub_institute_id === ProductMaster::SHARED, 'signalKey' => $row->signal_key, 'portfolioName' => $row->portfolio_name,
            'parent' => $parent ? ['id' => (int) $parent->id, 'key' => $parent->product_key, 'name' => $parent->name] : null,
            'subProducts' => $children->map(fn ($c) => ['id' => (int) $c->id, 'key' => $c->product_key, 'name' => $c->name, 'status' => $c->status])->values(),
            'offer' => $this->offerFor($row, $t),
            'crmProduct' => $this->crmProductFor($row, $t),
            'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at,
        ]]);
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

        return response()->json(['status' => 1, 'data' => [
            'signals' => $signals, 'priceBooks' => $priceBooks, 'quotes' => $quotes, 'basePrice' => $crm['unitPrice'] ?? null, 'currency' => $crm['currency'] ?? null,
        ]]);
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

    private function subProductRow(ProductMaster $s, Collection $offers): array
    {
        $o = $s->offer_row_id !== null ? $offers->get($s->offer_row_id) : null;

        return [
            'id' => (int) $s->id, 'key' => $s->product_key, 'name' => $s->name, 'status' => $s->status, 'origin' => $s->origin,
            'offerId' => $o?->offer_id, 'readiness' => $o?->readiness_status,
            'soldInCrm' => $s->crm_product_id !== null, 'crmProductId' => $s->crm_product_id,
        ];
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

    /** @return array{id:int, offerId:string, name:string, product:?string, readiness:?string}|null */
    private function offerFor(ProductMaster $row, int $tenant): ?array
    {
        if ($row->offer_row_id === null || ! Schema::hasTable('g2g_product_offers')) {
            return null;
        }
        $o = DB::table('g2g_product_offers')->where('id', $row->offer_row_id)
            ->where(fn ($q) => $q->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id'))->first(['id', 'offer_id', 'name', 'parent_product', 'readiness_status']);

        return $o ? ['id' => (int) $o->id, 'offerId' => (string) $o->offer_id, 'name' => (string) $o->name, 'product' => $o->parent_product, 'readiness' => $o->readiness_status] : null;
    }

    /** @return array{id:int, productNo:?string, name:string, sku:?string, type:?string, unitPrice:?float, currency:?string, taxRateId:?int, active:bool}|null */
    private function crmProductFor(ProductMaster $row, int $tenant): ?array
    {
        if ($row->crm_product_id === null || ! Schema::hasTable('crm_products')) {
            return null;
        }
        $p = DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $tenant)->whereNull('deleted_at')
            ->first(['id', 'product_no', 'name', 'sku', 'item_type', 'unit_price', 'currency', 'tax_rate_id', 'is_active']);

        return $p ? [
            'id' => (int) $p->id, 'productNo' => $p->product_no, 'name' => (string) $p->name, 'sku' => $p->sku, 'type' => $p->item_type,
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
