<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Products\CustomProductInvalid;
use App\Domain\Signals\Products\ProductCatalog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Creating and deleting a PRODUCT (administrators only: the route group). A product has no ICP of its own: ICPs belong to its sub-products, so there is nothing
 * here to read, edit or activate. The tenant comes from the token. Nothing here starts research or calls a search or AI provider.
 */
class ProductIcpController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * Creates a NEW top-level product for the signed-in organisation (administrators only: the route group). It is not an offer and joins no
     * built-in group: it has its own key and its own ICP product line, so the ICP saved for it is its own. The organisation comes from the
     * token; nothing is researched and no provider is called.
     */
    public function create(Request $request): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $v = Validator::make($request->all(), ['key' => 'required|string|max:40', 'label' => 'required|string|max:120']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        try {
            $product = (new ProductCatalog())->createCustom($identity['sub_institute_id'], $identity['user_id'], ProductCatalog::keyFrom((string) $request->input('key')), (string) $request->input('label'));
        } catch (CustomProductInvalid $e) {
            // Per field (`key`, `label`) so a form can show each reason next to its field; anything else under `general`.
            $errors = [];
            foreach ($e->problems as $problem) {
                if (preg_match('/^(key|label):\s*(.+)$/', $problem, $m)) {
                    $errors[$m[1]][] = $m[2];
                } else {
                    $errors['general'][] = $problem;
                }
            }

            return response()->json(['status' => 0, 'code' => 'product_invalid', 'message' => $e->getMessage(), 'problems' => $e->problems, 'errors' => $errors], 422);
        }

        return response()->json(['status' => 1, 'message' => 'Product created.', 'data' => ['product' => $product]], 201);
    }

    /**
     * Delete one of the organisation's OWN products (never a built-in one) FROM THE DATABASE: the product row, its ICPs and every saved version of them.
     * Nothing is kept as "archived". Offers in the Product Portfolio are not touched, and past opportunities, research runs and target accounts are
     * history of the organisation's work: they stay (the link from a run or an account to the deleted ICP is cleared).
     */
    public function destroy(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        if (($key = ProductCatalog::normalise($product, $tenantId)) === null) {
            return $this->unknown();
        }
        $custom = ProductCatalog::customFor($tenantId)[$key] ?? null;
        if ($custom === null) {
            return response()->json(['status' => 0, 'code' => 'built_in_product', 'message' => 'Only products you created yourself can be deleted. This one is built in.'], 422);
        }

        $deleted = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $key, $custom, &$deleted) {
            $ids = \App\Domain\Signals\Icp\IcpProfile::where('sub_institute_id', $tenantId)->where('product_line', $custom['icp_product_line'])->pluck('id')->all();
            if ($ids !== []) {
                foreach (['g2g_target_accounts', 'g2g_research_runs'] as $table) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($table) && \App\Support\ColumnExists::has($table, 'icp_profile_id')) {
                        \Illuminate\Support\Facades\DB::table($table)->where('sub_institute_id', $tenantId)->whereIn('icp_profile_id', $ids)->update(['icp_profile_id' => null]);
                    }
                }
                \App\Domain\Signals\Icp\IcpProfileVersion::whereIn('icp_profile_id', $ids)->delete();
                $deleted = \App\Domain\Signals\Icp\IcpProfile::whereIn('id', $ids)->delete();
            }
            \App\Domain\Signals\Products\CustomProduct::where('sub_institute_id', $tenantId)->where('key', $key)->delete();
        });
        ProductCatalog::forgetCustom();

        return response()->json(['status' => 1, 'message' => 'Product deleted, with its ICP.', 'data' => ['removed' => $key, 'icp_deleted' => $deleted]]);
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Unknown product.'], 404);
    }
}
