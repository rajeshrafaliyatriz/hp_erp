<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\ProductMaster\ProductAccess;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductMatchSuggester;
use App\Domain\ProductMaster\ProductMerger;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The pair review's API (slice 15, track P2-D), for the "Possible matches" part of the Product Portfolio screen (P3): which catalogue items and Sub-products look like the same
 * product, and a person's answer for each pair.
 *
 *   GET  /api/product-master/matches          the suggestions of the caller's organisation, with the reasons, and the answers already given
 *   POST /api/product-master/matches/decide   {itemMasterId, survivorMasterId, decision: same|different, nameKept?: portfolio|catalogue}
 *                                             "same" makes the two ONE record (ProductMerger::merge); "different" writes only the decision row
 *
 * ADMINISTRATORS ONLY (ProductAccess::isAdministrator), on top of the Products screen's own right (the route's platformright): the screen shows prices, and a "same" answer
 * changes what a quote can sell. The organisation always comes from the caller's token, never from the request; another organisation's ids answer 404, the same as ids that
 * do not exist. Nothing here ever shows a cost price or a commission: only the name, SKU, type and price of a catalogue item, its usage counts and the reasons.
 */
class ProductMatchController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! ProductAccess::isAdministrator($identity)) {
            return $this->forbidden('Only an administrator can review possible product matches.');
        }

        $s = (new ProductMatchSuggester)->suggest($identity['sub_institute_id']);

        return response()->json(['status' => 1, 'data' => [
            'matches' => $s['items'], 'unmatchedSubProducts' => $s['unmatchedSubProducts'], 'binnedItems' => $s['binnedItems'], 'decisions' => $s['decisions'],
            'counts' => $s['counts'], 'thresholds' => $s['thresholds'], 'notes' => $s['notes'], 'serviceRule' => $s['serviceRule'],
        ]]);
    }

    public function decide(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! ProductAccess::isAdministrator($identity)) {
            return $this->forbidden('Only an administrator can confirm whether two products are the same.');
        }

        $v = Validator::make($request->all(), [
            'itemMasterId' => 'required|integer|min:1',
            'survivorMasterId' => 'required|integer|min:1|different:itemMasterId',
            'decision' => 'required|in:same,different',
            'nameKept' => 'nullable|in:portfolio,catalogue',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => (string) $v->errors()->first(), 'errors' => $v->errors()->toArray()], 422);
        }

        $item = (int) $request->input('itemMasterId');
        $survivor = (int) $request->input('survivorMasterId');
        $merger = new ProductMerger;
        try {
            if ($request->input('decision') === ProductMerger::SAME) {
                $r = $merger->merge($identity, $item, $survivor, (string) ($request->input('nameKept') ?: ProductMerger::NAME_PORTFOLIO));

                return response()->json(['status' => 1, 'data' => [
                    'decision' => ProductMerger::SAME, 'result' => $r['result'], 'merged' => $r['result'] === 'merged', 'itemMasterId' => $r['itemMasterId'], 'survivorMasterId' => $r['survivorMasterId'],
                    'crmProductId' => $r['crmProductId'], 'offerRowId' => $r['offerRowId'], 'productId' => $r['productId'], 'nameKept' => $r['nameKept'], 'name' => $r['name'],
                    'decisionId' => $r['decisionId'] ?? null, 'moved' => $r['moved'] ?? [], 'claims' => $r['claims'] ?? null, 'audit' => $r['audit'] ?? null,
                ]], 200);
            }
            $r = $merger->decideDifferent($identity, $item, $survivor);

            return response()->json(['status' => 1, 'data' => [
                'decision' => ProductMerger::DIFFERENT, 'result' => $r['result'], 'merged' => false, 'itemMasterId' => $item, 'survivorMasterId' => $survivor, 'decisionId' => $r['decisionId'], 'audit' => $r['audit'] ?? null,
            ]], 200);
        } catch (ProductMasterInvalid $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage(), 'errors' => $e->errors], $e->status);
        }
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], 403);
    }
}
