<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\ProductMaster\ProductMasterSync;
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
        $v = Validator::make($request->all(), [
            'key' => 'required|string|max:40',
            'label' => 'required|string|max:120',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'logo' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        try {
            $meta = [
                'description' => $request->input('description'),
                'category' => $request->input('category'),
                'logo' => $request->input('logo'),
                'status' => $request->input('status', 'active'),
            ];
            $product = (new ProductCatalog())->createCustom($identity['sub_institute_id'], $identity['user_id'], ProductCatalog::keyFrom((string) $request->input('key')), (string) $request->input('label'), $meta);
            ProductMasterSync::throughCustomProduct($identity['sub_institute_id'], ProductCatalog::keyFrom((string) $request->input('key')), ProductMasterSync::CREATED, $identity['user_id']);
        } catch (CustomProductInvalid $e) {
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
     * Updates an existing Main Product (custom or built-in override).
     */
    public function update(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $v = Validator::make($request->all(), [
            'label' => 'sometimes|required|string|max:120',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'logo' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        try {
            $updated = (new ProductCatalog())->updateProduct($identity['sub_institute_id'], $identity['user_id'], $product, $request->all());
        } catch (CustomProductInvalid $e) {
            $errors = [];
            foreach ($e->problems as $problem) {
                if (preg_match('/^(key|label):\s*(.+)$/', $problem, $m)) {
                    $errors[$m[1]][] = $m[2];
                } else {
                    $errors['general'][] = $problem;
                }
            }

            return response()->json(['status' => 0, 'code' => 'product_invalid', 'message' => $e->getMessage(), 'problems' => $e->problems, 'errors' => $errors], 422);
        } catch (\App\Domain\Signals\Products\ProductNotConfigured) {
            return $this->unknown();
        }

        return response()->json(['status' => 1, 'message' => 'Product updated.', 'data' => ['product' => $updated]]);
    }

    /**
     * Returns pre-deletion dependencies count for safe confirmation.
     */
    public function dependencies(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        try {
            $deps = (new ProductCatalog())->productDependencies($identity['sub_institute_id'], $product);
        } catch (\App\Domain\Signals\Products\ProductNotConfigured) {
            return $this->unknown();
        }

        return response()->json(['status' => 1, 'data' => $deps]);
    }

    /**
     * Safely deletes or archives a Main Product (custom or built-in), preserving historical signals/opportunities.
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

        if (ProductCatalog::isBuiltIn($key) && $key !== 'fiber_valley' && ! $request->boolean('allow_builtin')) {
            return response()->json(['status' => 0, 'code' => 'built_in_product', 'message' => 'Built-in products cannot be deleted.'], 422);
        }

        try {
            $res = (new ProductCatalog())->deleteProduct($tenantId, $identity['user_id'], $key);
        } catch (\App\Domain\Signals\Products\ProductNotConfigured) {
            return $this->unknown();
        }

        return response()->json(['status' => 1, 'message' => 'Product deleted successfully.', 'data' => $res]);
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Unknown product.'], 404);
    }
}
