<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\ProductMaster\ProductLines;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductMasterSync;
use App\Domain\Signals\Products\CustomProductInvalid;
use App\Domain\Signals\Products\ProductCatalog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
        $meta = [
            'description' => $request->input('description'),
            'category' => $request->input('category'),
            'logo' => $request->input('logo'),
            'status' => $request->input('status', 'active'),
        ];
        $key = ProductCatalog::keyFrom((string) $request->input('key'));
        $label = (string) $request->input('label');
        try {
            $product = $this->makeProduct($identity, $key, $label, $meta);
        } catch (ProductMasterInvalid $e) {
            // Only a refusal the old path never gave can arrive here: the route group lets administrators in, and so does ProductLines::create (403).
            return response()->json(['status' => 0, 'message' => $e->getMessage(), 'errors' => $e->errors], $e->status);
        } catch (CustomProductInvalid $e) {
            return $this->invalid($e);
        }

        return response()->json(['status' => 1, 'message' => 'Product created.', 'data' => ['product' => $product]], 201);
    }

    /**
     * Writes the Product through the ONE door, ProductLines::create (the custom row and the master row, in one transaction; slice 15, D-5), and answers as ProductCatalog::describe() does.
     *
     * The old guarded path (ProductCatalog::createCustom, then the write-through of ProductMasterSync, which logs and swallows a failure) is used ONLY where the one door cannot work:
     * the master or the custom table does not exist on this host, or the master write fails with a database error ("a failing master write never changes the answer"; the door's
     * transaction has rolled back, so nothing is left half-written). A key the door finds taken (409) goes the same way: the old path then says what it always said (a duplicate in
     * the custom table is the same 422 `product_invalid`; a clash only in the master was and is swallowed by the write-through).
     *
     * @param  array{user:object, user_id:int, sub_institute_id:int}  $identity
     * @param  array<string, mixed>  $meta  description, category, logo, status
     * @return array<string, mixed>
     * @throws CustomProductInvalid  the rules refuse the key or the name (the shape and words of the old path)
     * @throws ProductMasterInvalid  the caller may not create a Product (403)
     */
    private function makeProduct(array $identity, string $key, string $label, array $meta): array
    {
        $tenant = $identity['sub_institute_id'];
        if (Schema::hasTable('g2g_product_master') && Schema::hasTable('g2g_signal_products')) {
            $written = false;
            try {
                ProductLines::create($identity, $key, $label, $meta);
                $written = true;
            } catch (ProductMasterInvalid $e) {
                if ($e->status === 422 && $e->problems !== []) {
                    throw new CustomProductInvalid($e->problems);
                }
                if ($e->status !== 409) {
                    throw $e;
                }
            } catch (QueryException) {
                // The master write failed: fall through to the old path, which logs it and still answers.
            }
            if ($written) {
                return (new ProductCatalog())->describe($tenant, strtolower(trim($key)));
            }
        }

        $product = (new ProductCatalog())->createCustom($identity['sub_institute_id'], $identity['user_id'], $key, $label, $meta);
        ProductMasterSync::throughCustomProduct($identity['sub_institute_id'], $key, ProductMasterSync::CREATED, $identity['user_id']);

        return $product;
    }

    /** The refusal of the rules, in the shape this endpoint has always answered with: `problems` as the rules worded them and `errors` by field (`key`, `label`, `general`). */
    private function invalid(CustomProductInvalid $e): JsonResponse
    {
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

        // S15 follow-up, 24 Nov 2026 (D-9, which replaces the owner's refusal of 23 Nov): this route no longer refuses a Product that has Sub-products (409 `product_has_sub_products`).
        // What becomes of the Sub-products is asked each time, by the dialog of the Product Portfolio, which calls POST /api/product-master/{id}/remove (move them to another Product,
        // or delete them together with it: ProductRemoval). This older door keeps working the way it did before 23 Nov: the Product goes and the offers filed under it are marked
        // Archived (ProductCatalog::deleteProduct), nothing else is touched.

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
