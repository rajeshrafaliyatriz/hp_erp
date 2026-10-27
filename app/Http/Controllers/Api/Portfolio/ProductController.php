<?php

namespace App\Http\Controllers\Api\Portfolio;

use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Products\ProductCatalog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\ColumnExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The products the portfolio and Signals work with, each with what is actually stored for it (offers, ICP, accounts, contacts) and
 * what is still missing. Read-only: products are edited where their records live (offers in the portfolio, the ICP under Signals).
 * The tenant always comes from the token.
 */
class ProductController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $catalog = new ProductCatalog();

        return response()->json(['status' => 1, 'data' => [
            'products' => array_map(fn (array $p) => $this->withCounts($tenantId, $p), $catalog->listFor($tenantId)),
            'company_profile' => $this->companyProfile($tenantId),
        ]]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $product = ProductCatalog::normalise($key, $tenantId);
        if ($product === null) {
            return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Unknown product.'], 404);
        }

        return response()->json(['status' => 1, 'data' => [
            'product' => $this->withCounts($tenantId, (new ProductCatalog())->describe($tenantId, $product)),
            'research_context' => (new ProductCatalog())->researchContext($tenantId, $product),
            'company_profile' => $this->companyProfile($tenantId),
        ]]);
    }

    /** What has been found for the product so far, from the records themselves (never a hard-coded number). @param array<string, mixed> $p @return array<string, mixed> */
    private function withCounts(int $tenantId, array $p): array
    {
        $opps = Schema::hasTable('g2g_company_opportunities')
            ? DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenantId)->where('business_fit', $p['business_fit'])->count()
            : 0;
        $runs = Schema::hasTable('g2g_research_runs') && ColumnExists::has('g2g_research_runs', 'product_key')
            ? DB::table('g2g_research_runs')->where('sub_institute_id', $tenantId)->where('product_key', $p['key'])->count()
            : 0;

        return $p + ['opportunities_count' => $opps, 'research_runs_count' => $runs];
    }

    /**
     * The organisation's own profile, kept apart from any product: it describes the company that sells the products.
     *
     * @return array<string, mixed>|null
     */
    private function companyProfile(int $tenantId): ?array
    {
        if (! Schema::hasTable('g2g_product_profiles')) {
            return null;
        }
        $p = ProductProfile::where('sub_institute_id', $tenantId)->first();

        return $p === null ? null : [
            'name' => $p->product_name, 'description' => $p->description, 'target_industries' => $p->target_industries,
            'target_markets' => $p->target_markets,
        ];
    }
}
