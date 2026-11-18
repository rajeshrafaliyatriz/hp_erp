<?php

namespace App\Http\Controllers\Api\Outreach;

use App\Domain\OutreachGate\ClaimRejected;
use App\Domain\OutreachGate\ProductClaims;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The approved product claims (slice 10): the sentences an organisation is willing to put in an outreach draft as a numeric, percentage, guarantee or superlative
 * claim. Rights are the Products row's, per action (routes/outreach_gate.php): view lists, add proposes, edit approves or retires. Every read and write carries the
 * caller's organisation (from the token, never from the request): another organisation's claim is a 404, a product the organisation cannot see is a 422.
 *
 * Nothing here sends or schedules anything; a claim only widens what the outreach gate lets through.
 */
class OutreachClaimsController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/outreach/claims?product_master_id=&status=&limit=&offset= */
    public function index(Request $request, ProductClaims $claims): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->query(), [
            'product_master_id' => 'nullable|integer|min:1',
            'status' => 'nullable|in:' . implode(',', ProductClaims::STATUSES),
            'limit' => 'nullable|integer|min:1|max:200',
            'offset' => 'nullable|integer|min:0',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        $list = $claims->list(
            $identity['sub_institute_id'],
            $request->query('product_master_id') !== null ? (int) $request->query('product_master_id') : null,
            $request->query('status') !== null ? (string) $request->query('status') : null,
            (int) $request->query('limit', 100),
            (int) $request->query('offset', 0),
        );

        return response()->json(['status' => 1, 'data' => $list]);
    }

    /** POST /api/outreach/claims  {product_master_id, claim, source_url?} */
    public function store(Request $request, ProductClaims $claims): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'product_master_id' => 'required|integer|min:1',
            'claim' => 'required|string|max:500',
            'source_url' => 'nullable|url|max:1000',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        try {
            $claim = $claims->propose($identity['sub_institute_id'], $identity['user_id'], (int) $request->input('product_master_id'), (string) $request->input('claim'), $request->input('source_url'));
        } catch (ClaimRejected $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 1, 'data' => ['claim' => $claim]], 201);
    }

    /** PUT /api/outreach/claims/{id}  {status: approved|retired} */
    public function update(Request $request, int $id, ProductClaims $claims): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        // The claim is looked up with the caller's organisation BEFORE anything else is said about the request, so a claim of another organisation and one that never
        // existed answer the same 404 whatever the body holds.
        if ($claims->find($identity['sub_institute_id'], $id) === null) {
            return response()->json(['status' => 0, 'message' => 'Claim not found.'], 404);
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:' . implode(',', ProductClaims::DECISIONS)]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => $v->errors()->first()], 422);
        }

        try {
            $claim = $claims->decide($identity['sub_institute_id'], $identity['user_id'], $id, (string) $request->input('status'));
        } catch (ClaimRejected $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
        }

        return $claim === null
            ? response()->json(['status' => 0, 'message' => 'Claim not found.'], 404)
            : response()->json(['status' => 1, 'data' => ['claim' => $claim]]);
    }
}
