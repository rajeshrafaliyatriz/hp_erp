<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\ProductMaster\ProductLineChoices;
use App\Domain\ProductMaster\ProductPicker;
use App\Domain\ProductMaster\ProductReadRights;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The two reads the pickers of every form use (S15 follow-up, 24 Nov 2026, D-7; contract section 4.4): `GET /api/product-master/picker` and `GET /api/product-master/lines`.
 *
 * They moved here from ProductMasterController so that the new parameters could be built without editing a file another track owns; the routes (routes/product_master.php) point
 * at this class, and the answers a caller sent no new parameter to are exactly what they were:
 *
 *   picker   `?type=` (product, subproduct or service), `?q=`, `?limit=` as before. `?scope=sold` (the default) offers the Sub-products a document may SELL, and 403s a caller who may
 *            view no prices. `?scope=all` offers every active Sub-product and item, sold or not (ProductPicker::searchAll), with `sold` and `state` on each row and a null
 *            `crmProductId` where there is no catalogue row; it asks for no Sell right at all, and a caller who may not view prices gets the same rows with the Sell fields null.
 *   lines    every active Product as `{id, key, name}`; with `?withSubProducts=1` each Product is followed by its active Sub-products and items (ProductLineChoices).
 *
 * The organisation always comes from the caller's token.
 */
class ProductPickerController extends Controller
{
    use ResolvesApiIdentity;

    /** `?scope=`: the rows a document can sell (the default), or every active Sub-product. */
    public const SCOPES = ['sold', 'all'];

    public function picker(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $asked = strtolower(trim((string) $request->query('type', '')));
        if (! in_array($asked, ['', 'product', 'subproduct', 'service'], true)) {
            return response()->json(['status' => 0, 'message' => 'type must be product, subproduct or service.', 'errors' => ['type' => ['type must be product, subproduct or service.']]], 422);
        }
        // An empty `scope` is no scope: the default, which is what every caller that does not know the parameter sends.
        $scope = strtolower(trim((string) $request->query('scope', '')));
        $scope = $scope === '' ? 'sold' : $scope;
        if (! in_array($scope, self::SCOPES, true)) {
            return response()->json(['status' => 0, 'message' => 'scope must be sold or all.', 'errors' => ['scope' => ['scope must be sold or all.']]], 422);
        }
        $rights = new ProductReadRights($identity);

        if ($scope === 'all') {
            if (! Schema::hasTable('g2g_product_master') || ! Schema::hasTable('crm_products')) {
                return response()->json(['status' => 1, 'data' => []]);
            }
            $rows = (new ProductPicker($identity['sub_institute_id']))->searchAll($rights->viewableTypes(), $asked === 'service' ? 'service' : ($asked === '' ? '' : 'product'), (string) $request->query('q', ''), ProductPicker::limit($request->query('limit')));

            return response()->json(['status' => 1, 'data' => $rows]);
        }

        $types = $asked === '' ? $rights->viewableTypes() : [$asked === 'service' ? 'service' : 'product'];
        if ($types === [] || ($asked !== '' && ! $rights->canViewSell($types[0]))) {
            return response()->json(['status' => 0, 'message' => 'You do not have the right to view prices of this kind of product.'], 403);
        }
        if (! Schema::hasTable('g2g_product_master') || ! Schema::hasTable('crm_products')) {
            return response()->json(['status' => 1, 'data' => []]);
        }

        return response()->json(['status' => 1, 'data' => (new ProductPicker($identity['sub_institute_id']))->search($types, (string) $request->query('q', ''), ProductPicker::limit($request->query('limit')))]);
    }

    public function lines(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! Schema::hasTable('g2g_product_master')) {
            return response()->json(['status' => 1, 'data' => []]);
        }
        $choices = new ProductLineChoices($identity['sub_institute_id']);
        $with = filter_var($request->query('withSubProducts', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json(['status' => 1, 'data' => $with ? $choices->withSubProducts() : $choices->lines()]);
    }
}
