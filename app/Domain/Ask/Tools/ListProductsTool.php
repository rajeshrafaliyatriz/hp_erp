<?php

namespace App\Domain\Ask\Tools;

use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRights;
use App\Domain\Ask\AskRow;
use App\Domain\Ask\AskText;
use App\Domain\Ask\ToolResult;
use App\Domain\ProductMaster\ProductReadRights;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * list_products {}: the organisation's Products and their Sub-products (a service is a Sub-product of type Service), plus the built-in ones every organisation shares: the
 * records the Product Portfolio shows. At most 40 rows. Names and where each belongs, for everybody who may open the Products row OR the Portfolio (slice 15 WPB, D-E2:
 * HR, who holds no row on the Products screen, was told "not permitted" for names the Portfolio shows).
 *
 * The Sell-side facts (whether the record is sold in the CRM, and its type) are part of the answer only for a caller who may view the Sell section of that record's type
 * (ProductReadRights, the rule of every other product read): the Products row for a product, the Services row for a service. No price, SKU, cost or stock is ever in the
 * answer, and no ICP and no signal rows. A caller without the right gets the name, the kind and the Product a Sub-product belongs to.
 *
 * Each row's link is the Product Portfolio's own address: `/portfolio/{key}` for a Product and `/portfolio/products/{id}` for a Sub-product.
 *
 * Two keys of the answer keep the names they always had because the answer's shape is kept as it was: `product_line` (the name of the Product a Sub-product belongs to) and
 * `sold_in_the_crm_catalogue` (whether a CRM catalogue row stands behind the record). Nothing reads them but the model; AskVocabularyScanTest lists them as the allowed exceptions.
 */
final class ListProductsTool extends AskTool
{
    public function name(): string
    {
        return 'list_products';
    }

    public function describe(): string
    {
        return 'list_products {}. Lists the Products and their Sub-products of the organisation (a service is a Sub-product of type Service). Takes no arguments.';
    }

    protected function allowed(): array
    {
        return [];
    }

    protected function normalise(AskCaller $caller, array $args): array
    {
        return [];
    }

    public function run(AskCaller $caller, array $args): ToolResult
    {
        if (! AskRights::canListProducts($caller)) {
            return new ToolResult($this->name(), $args, ToolResult::NOT_PERMITTED, [], [AskRights::plural('product')]);
        }
        if (! Schema::hasTable('g2g_product_master')) {
            return new ToolResult($this->name(), $args, ToolResult::OK, [], [], null, 'in products');
        }

        // The organisation's own rows only: nothing is shared between organisations (S15 follow-up, D-1).
        $base = DB::table('g2g_product_master as m')->where('m.sub_institute_id', $caller->tenantId)->where('m.status', 'active');
        $total = (int) (clone $base)->count();
        $records = $base->orderByRaw("CASE m.kind WHEN 'product' THEN 0 WHEN 'sub_product' THEN 1 ELSE 2 END")->orderBy('m.name')->orderBy('m.id')
            ->limit((int) config('ask.max_product_rows', 40))->get(['m.id', 'm.kind', 'm.parent_id', 'm.name', 'm.product_key', 'm.signal_key', 'm.crm_product_id', 'm.sub_institute_id']);

        $rights = new ProductReadRights($caller->identity());
        $parents = $records->pluck('parent_id')->filter()->unique()->values()->all();
        $parentNames = $parents === [] ? [] : DB::table('g2g_product_master')->where('sub_institute_id', $caller->tenantId)->whereIn('id', $parents)->pluck('name', 'id')->all();
        // The type of each catalogue row stands behind a record; what is SAID about it is decided per row below, by the right of that type.
        $crmIds = $records->pluck('crm_product_id')->filter()->unique()->values()->all();
        $itemTypes = $crmIds === [] || ! Schema::hasTable('crm_products')
            ? [] : DB::table('crm_products')->where('sub_institute_id', $caller->tenantId)->whereIn('id', $crmIds)->whereNull('deleted_at')->pluck('item_type', 'id')->all();

        $rows = [];
        foreach ($records as $r) {
            $crm = $r->crm_product_id !== null ? (int) $r->crm_product_id : null;
            $type = $crm !== null ? ($itemTypes[$crm] ?? null) : null;
            $sold = $type !== null;
            $url = $r->kind === 'product'
                ? '/portfolio' . ($r->product_key !== null && $r->product_key !== '' ? '/' . rawurlencode((string) $r->product_key) : '')
                : "/portfolio/products/{$r->id}";
            $data = [
                'kind' => $r->kind === 'product' ? 'Product' : 'Sub-product',
                'product_line' => $r->parent_id !== null ? AskText::clip($parentNames[(int) $r->parent_id] ?? null, 120) : null,
                'signals_key' => $r->signal_key,
            ];
            // The Sell-side facts: a sold record only to a caller who may view its type's Sell section; an unsold one to any caller who may view some type's.
            if ($sold ? $rights->canViewSell($type) : $rights->canViewAnySell()) {
                $data['sold_in_the_crm_catalogue'] = $sold;
                if ($sold) {
                    $data['type'] = $type === 'service' ? 'Service' : 'Product';
                }
            }
            $rows[] = new AskRow('product', (string) $r->id, (string) (AskText::clip($r->name, 120) ?? "Product #{$r->id}"), $url, $data);
        }

        return new ToolResult($this->name(), $args, ToolResult::OK, $rows, [], $total, 'in products');
    }
}
