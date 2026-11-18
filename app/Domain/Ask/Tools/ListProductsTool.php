<?php

namespace App\Domain\Ask\Tools;

use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRights;
use App\Domain\Ask\AskRow;
use App\Domain\Ask\AskText;
use App\Domain\Ask\ToolResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * list_products {}: the organisation's product lines and sub-products, and its catalogue-only items (the Product Master the Products screen shows), plus the
 * built-in ones every organisation shares. Names and where each belongs only: no price, no ICP, no signal rows. At most 40 rows.
 */
final class ListProductsTool extends AskTool
{
    public function name(): string
    {
        return 'list_products';
    }

    public function describe(): string
    {
        return 'list_products {}. Lists the product lines, their sub-products and the catalogue items of the organisation. Takes no arguments.';
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
        if (! AskRights::canRead($caller, 'product')) {
            return new ToolResult($this->name(), $args, ToolResult::NOT_PERMITTED, [], [AskRights::plural('product')]);
        }
        if (! Schema::hasTable('g2g_product_master')) {
            return new ToolResult($this->name(), $args, ToolResult::OK, [], [], null, 'in products');
        }

        // The organisation's own rows and the shared ones (organisation 0), never another organisation's.
        $base = DB::table('g2g_product_master as m')->whereIn('m.sub_institute_id', [$caller->tenantId, 0])->where('m.status', 'active');
        $total = (int) (clone $base)->count();
        $records = $base->orderByRaw("CASE m.kind WHEN 'product' THEN 0 WHEN 'sub_product' THEN 1 ELSE 2 END")->orderBy('m.name')->orderBy('m.id')
            ->limit((int) config('ask.max_product_rows', 40))->get(['m.id', 'm.kind', 'm.parent_id', 'm.name', 'm.signal_key', 'm.crm_product_id', 'm.sub_institute_id']);

        $parents = $records->pluck('parent_id')->filter()->unique()->values()->all();
        $parentNames = $parents === [] ? [] : DB::table('g2g_product_master')->whereIn('sub_institute_id', [$caller->tenantId, 0])->whereIn('id', $parents)->pluck('name', 'id')->all();
        $crmIds = $records->pluck('crm_product_id')->filter()->unique()->values()->all();
        $itemTypes = $crmIds === [] || ! Schema::hasTable('crm_products')
            ? [] : DB::table('crm_products')->where('sub_institute_id', $caller->tenantId)->whereIn('id', $crmIds)->whereNull('deleted_at')->pluck('item_type', 'id')->all();

        $rows = [];
        foreach ($records as $r) {
            $crm = $r->crm_product_id !== null ? (int) $r->crm_product_id : null;
            $sold = $crm !== null && isset($itemTypes[$crm]);
            $url = $sold
                ? ($itemTypes[$crm] === 'service' ? "/module/crm/sales/services/{$crm}" : "/module/crm/sales/products/{$crm}")
                : "/module/crm/sales/products/lines/{$r->id}";
            $rows[] = new AskRow('product', (string) $r->id, (string) (AskText::clip($r->name, 120) ?? "Product #{$r->id}"), $url, [
                'kind' => match ($r->kind) { 'product' => 'product line', 'sub_product' => 'sub-product', default => 'catalogue item' },
                'product_line' => $r->parent_id !== null ? AskText::clip($parentNames[(int) $r->parent_id] ?? null, 120) : null,
                'signals_key' => $r->signal_key, 'sold_in_the_crm_catalogue' => $sold,
            ]);
        }

        return new ToolResult($this->name(), $args, ToolResult::OK, $rows, [], $total, 'in products');
    }
}
