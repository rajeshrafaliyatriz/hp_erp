<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Products\CustomProductInvalid;
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

    public function show(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product, $identity['sub_institute_id'])) === null) {
            return $this->unknown();
        }

        return response()->json(['status' => 1, 'data' => $this->icps->editorState($identity['sub_institute_id'], $key)]);
    }

    /**
     * How well each chosen portfolio product fits this product's ICP: a deterministic, explained comparison (no AI, nothing saved, no provider).
     * The products are `offer_ids` (a list or comma separated) or, when none are given, the ones saved in the ICP. Only this organisation's products.
     */
    public function compare(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product, $identity['sub_institute_id'])) === null) {
            return $this->unknown();
        }
        $tenantId = $identity['sub_institute_id'];
        $state = $this->icps->editorState($tenantId, $key);
        $config = (array) $state['config'];

        $given = $request->query('offer_ids');
        $ids = $given === null ? (array) ($config['offer_ids'] ?? []) : (is_array($given) ? $given : explode(',', (string) $given));
        $ids = array_values(array_unique(array_filter(array_map(fn ($i) => trim((string) $i), $ids), fn ($i) => $i !== '' && preg_match('/^[A-Za-z0-9._\-]{1,50}$/', $i) === 1)));
        if (count($ids) > 30) {
            return response()->json(['status' => 0, 'code' => 'too_many', 'message' => 'Compare at most 30 products at a time.'], 422);
        }

        $offers = $ids === [] ? collect() : \App\Domain\Portfolio\ProductOffer::query()->forTenant($tenantId)->whereIn('offer_id', $ids)->get()->keyBy('offer_id');
        $comparator = new \App\Domain\Signals\Products\IcpOfferComparator();
        $results = [];
        $unknown = [];
        foreach ($ids as $id) {
            $offer = $offers[$id] ?? null;
            $offer === null ? $unknown[] = $id : $results[] = $comparator->compare($config, $offer->toArray());
        }

        return response()->json(['status' => 1, 'data' => [
            'product' => $key,
            'icp' => [
                'label' => $config['label'] ?? null, 'industry_label' => $config['industry_label'] ?? null, 'buyer_types' => $config['buyer_types'] ?? [],
                'regions' => $config['regions'] ?? [], 'parts' => count((array) ($config['parts'] ?? [])), 'triggers' => count((array) ($config['triggers'] ?? [])),
                'saved' => $state['profile'] !== null,
            ],
            'method' => \App\Domain\Signals\Products\IcpOfferComparator::METHOD,
            'results' => $results,
            'unknown_offer_ids' => $unknown,
        ]]);
    }

    /**
     * Remove one of the organisation's OWN products (never a built-in one). Its ICPs are archived, not deleted (every version is kept), and its past
     * opportunities and research runs are left as they are. Offers in the Product Portfolio are not touched.
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
            return response()->json(['status' => 0, 'code' => 'built_in_product', 'message' => 'Only products you created yourself can be removed. This one is built in.'], 422);
        }

        $archived = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $key, $custom, &$archived) {
            $archived = \App\Domain\Signals\Icp\IcpProfile::where('sub_institute_id', $tenantId)->where('product_line', $custom['icp_product_line'])
                ->where('status', '!=', 'archived')->update(['status' => 'archived']);
            \App\Domain\Signals\Products\CustomProduct::where('sub_institute_id', $tenantId)->where('key', $key)->delete();
        });
        ProductCatalog::forgetCustom();

        return response()->json(['status' => 1, 'message' => 'Product removed. Its ICP versions were archived, not deleted.', 'data' => ['removed' => $key, 'icp_archived' => $archived]]);
    }

    /** Check an ICP (nothing is saved). */
    public function validateConfig(Request $request, string $product): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        if (($key = ProductCatalog::normalise($product, $identity['sub_institute_id'])) === null) {
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
        if (($key = ProductCatalog::normalise($product, $identity['sub_institute_id'])) === null) {
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
        if (($key = ProductCatalog::normalise($product, $identity['sub_institute_id'])) === null) {
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
