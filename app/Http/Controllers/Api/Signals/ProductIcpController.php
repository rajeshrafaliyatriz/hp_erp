<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductIcpInvalid;
use App\Domain\Signals\Products\ProductIcpService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A product's own Ideal Customer Profile, addressed BY PRODUCT. Administrators only (the route group); the tenant comes from the token and
 * the product line is stamped by the server, so an ICP can never be read, edited or activated through another product's address.
 * Nothing here starts research or calls a search or AI provider.
 */
class ProductIcpController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly ProductIcpService $icps)
    {
    }

    public function show(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product)) === null) {
            return $this->unknown();
        }

        return response()->json(['status' => 1, 'data' => $this->icps->editorState($identity['sub_institute_id'], $key)]);
    }

    /** Check an ICP (nothing is saved). */
    public function validateConfig(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product)) === null) {
            return $this->unknown();
        }
        if ($bad = $this->badInput($request)) {
            return $bad;
        }
        $r = $this->icps->check($identity['sub_institute_id'], $key, (array) $request->input('config', []));

        return response()->json(['status' => 1, 'data' => ['valid' => $r['problems'] === [], 'problems' => $r['problems'], 'activation_blockers' => $r['missing']]]);
    }

    /** Save the product's ICP (a new version of the existing one, or the first). `activate: true` also makes it the product's active ICP. */
    public function save(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product)) === null) {
            return $this->unknown();
        }
        if ($bad = $this->badInput($request)) {
            return $bad;
        }
        try {
            $this->icps->save(
                $identity['sub_institute_id'], $identity['user_id'], $key, (array) $request->input('config', []),
                $request->filled('name') ? trim((string) $request->input('name')) : null,
                $request->filled('note') ? (string) $request->input('note') : null,
                $request->boolean('activate'),
            );
        } catch (ProductIcpInvalid $e) {
            return response()->json(['status' => 0, 'code' => 'icp_invalid', 'message' => $e->getMessage(), 'problems' => $e->problems], 422);
        }

        return response()->json(['status' => 1, 'message' => 'ICP saved.', 'data' => $this->icps->editorState($identity['sub_institute_id'], $key)]);
    }

    public function setStatus(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product)) === null) {
            return $this->unknown();
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:draft,active,archived']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        try {
            $this->icps->setStatus($identity['sub_institute_id'], $identity['user_id'], $key, (string) $request->input('status'));
        } catch (ProductIcpInvalid $e) {
            return response()->json(['status' => 0, 'code' => 'icp_invalid', 'message' => $e->getMessage(), 'problems' => $e->problems], 422);
        }

        return response()->json(['status' => 1, 'data' => $this->icps->editorState($identity['sub_institute_id'], $key)]);
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Unknown product.'], 404);
    }

    /** The shape of the request itself; the settings inside `config` are judged by the ICP validator. */
    private function badInput(Request $request): ?JsonResponse
    {
        $v = Validator::make($request->all(), [
            'config' => 'nullable|array', 'name' => 'nullable|string|max:120', 'note' => 'nullable|string|max:255', 'activate' => 'nullable|boolean',
        ]);

        return $v->fails() ? response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422) : null;
    }
}
