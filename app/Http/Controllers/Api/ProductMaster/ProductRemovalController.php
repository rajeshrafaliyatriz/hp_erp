<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductMover;
use App\Domain\ProductMaster\ProductRemoval;
use App\Domain\ProductMaster\ProductRemovalRefused;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The three doors of deleting and moving Products and Sub-products (S15 follow-up, 24 Nov 2026; contract section 4 of Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md):
 *
 *   GET  /api/product-master/{id}/removal   what blocks deleting a record, which Sub-products hang under it and where they may go (ProductRemoval::preview)
 *   POST /api/product-master/move           one or several Sub-products under another Product (ProductMover)
 *   POST /api/product-master/{id}/remove    delete the record; a Product says what becomes of its Sub-products (ProductRemoval::remove)
 *
 * They sit behind the same middleware as archive (a signed-in token only); the RIGHTS are judged per record inside the domain classes, from the record, never from the body: an
 * administrator for a Product, the Portfolio's and the Sell right of its type for a Sub-product. Every id is looked up in the caller's own organisation, so another
 * organisation's id is a 404. Answers use the envelope `{status, message, data}`; the codes are 200, 403, 404, 409 and 422, and a refusal carries a `code` a client can branch on.
 */
class ProductRemovalController extends Controller
{
    use ResolvesApiIdentity;

    public function removal(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($id): array {
            $row = $this->rowOf($identity, $id);

            return (new ProductRemoval($identity))->preview($row);
        });
    }

    public function move(Request $request): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request): array {
            if (! Schema::hasTable('g2g_product_master')) {
                throw ProductRemovalRefused::notFound();
            }

            return (new ProductMover($identity))->move($request->input('subProductIds'), $request->input('toProductId'));
        });
    }

    public function remove(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request, $id): array {
            $row = $this->rowOf($identity, $id);

            return (new ProductRemoval($identity))->remove($row, $request->all());
        });
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private function rowOf(array $identity, int $id): ProductMaster
    {
        $row = Schema::hasTable('g2g_product_master') ? ProductMaster::visibleTo($identity['sub_institute_id'])->find($id) : null;
        if ($row === null) {
            throw ProductRemovalRefused::notFound();
        }

        return $row;
    }

    /** Runs one door for the signed-in caller and turns a refusal into the JSON the screen shows. Nothing has been written when it refuses. */
    private function run(Request $request, \Closure $door): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        try {
            return response()->json(['status' => 1, 'data' => $door($identity)]);
        } catch (ProductRemovalRefused $e) {
            return response()->json($e->body(), $e->status);
        } catch (ProductMasterInvalid $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage(), 'errors' => $e->errors], $e->status);
        }
    }
}
