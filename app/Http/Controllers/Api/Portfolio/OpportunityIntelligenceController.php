<?php

namespace App\Http\Controllers\Api\Portfolio;

use App\Domain\Portfolio\MatchingEngine;
use App\Domain\Portfolio\OpportunityMatch;
use App\Domain\Signals\Ingestion\IngestionFinding;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Signal;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OpportunityIntelligenceController extends Controller
{
    use ResolvesApiIdentity;

    protected MatchingEngine $engine;

    public function __construct(MatchingEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * GET /signals/{id}/opportunity-matches
     * Matches offers and partners for an internal Signal.
     */
    public function matchSignal(Request $request, $id): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);
        if (! $tenantId) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $signal = Signal::query()
            ->where('sub_institute_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $signal) {
            return response()->json(['status' => 0, 'message' => 'Signal not found'], 404);
        }

        $result = $this->engine->matchForSignal($signal, 'signal', $tenantId);

        // Attach existing match status/notes if recorded
        $saved = OpportunityMatch::query()
            ->where('sub_institute_id', $tenantId)
            ->where('signal_type', 'signal')
            ->where('signal_id', $id)
            ->get()
            ->keyBy('offer_id');

        foreach ($result['offer_matches'] as &$om) {
            $offerId = $om['offer']->offer_id;
            if (isset($saved[$offerId])) {
                $om['match_status'] = $saved[$offerId]->match_status;
                $om['review_notes'] = $saved[$offerId]->review_notes;
                $om['match_id']     = $saved[$offerId]->id;
            } else {
                $om['match_status'] = 'Matched';
                $om['review_notes'] = null;
                $om['match_id']     = null;
            }
        }

        return response()->json([
            'status' => 1,
            'data'   => $result,
        ]);
    }

    /**
     * GET /signals/opportunities/{id}/opportunity-matches
     * Matches offers and partners for a Company Opportunity.
     */
    public function matchOpportunity(Request $request, $id): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);
        if (! $tenantId) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $opportunity = CompanyOpportunity::query()
            ->where('sub_institute_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $opportunity) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found'], 404);
        }

        $result = $this->engine->matchForSignal($opportunity, 'opportunity', $tenantId);

        $saved = OpportunityMatch::query()
            ->where('sub_institute_id', $tenantId)
            ->where('signal_type', 'opportunity')
            ->where('signal_id', $id)
            ->get()
            ->keyBy('offer_id');

        foreach ($result['offer_matches'] as &$om) {
            $offerId = $om['offer']->offer_id;
            if (isset($saved[$offerId])) {
                $om['match_status'] = $saved[$offerId]->match_status;
                $om['review_notes'] = $saved[$offerId]->review_notes;
                $om['match_id']     = $saved[$offerId]->id;
            } else {
                $om['match_status'] = 'Matched';
                $om['review_notes'] = null;
                $om['match_id']     = null;
            }
        }

        return response()->json([
            'status' => 1,
            'data'   => $result,
        ]);
    }

    /**
     * GET /signals/ingestion/findings/{id}/opportunity-matches
     * Matches offers and partners for an Ingestion Finding.
     */
    public function matchFinding(Request $request, $id): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);
        if (! $tenantId) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $finding = IngestionFinding::query()
            ->where('sub_institute_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $finding) {
            return response()->json(['status' => 0, 'message' => 'Finding not found'], 404);
        }

        $result = $this->engine->matchForSignal($finding, 'ingestion_finding', $tenantId);

        $saved = OpportunityMatch::query()
            ->where('sub_institute_id', $tenantId)
            ->where('signal_type', 'ingestion_finding')
            ->where('signal_id', $id)
            ->get()
            ->keyBy('offer_id');

        foreach ($result['offer_matches'] as &$om) {
            $offerId = $om['offer']->offer_id;
            if (isset($saved[$offerId])) {
                $om['match_status'] = $saved[$offerId]->match_status;
                $om['review_notes'] = $saved[$offerId]->review_notes;
                $om['match_id']     = $saved[$offerId]->id;
            } else {
                $om['match_status'] = 'Matched';
                $om['review_notes'] = null;
                $om['match_id']     = null;
            }
        }

        return response()->json([
            'status' => 1,
            'data'   => $result,
        ]);
    }

    /**
     * POST /signals/matches/action
     * Records human review, follow-up, or dismissal on an opportunity match.
     */
    public function actOnMatch(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = $identity['user_id'];

        $v = Validator::make($request->all(), [
            'signal_type'  => 'required|string|in:signal,opportunity,ingestion_finding',
            'signal_id'    => 'required|integer|min:1',
            'offer_id'     => 'required|string|max:50',
            'partner_id'   => 'nullable|string|max:50',
            'action'       => 'required|string|in:review,follow-up,dismiss,matched',
            'review_notes' => 'nullable|string|max:2000',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $actionMap = [
            'review'    => 'Reviewed',
            'follow-up' => 'Follow-up',
            'dismiss'   => 'Dismissed',
            'matched'   => 'Matched',
        ];
        $status = $actionMap[$request->input('action')];

        $match = OpportunityMatch::updateOrCreate(
            [
                'sub_institute_id' => $tenantId,
                'signal_type'      => $request->input('signal_type'),
                'signal_id'        => $request->input('signal_id'),
                'offer_id'         => $request->input('offer_id'),
            ],
            [
                'partner_id'   => $request->input('partner_id'),
                'match_status' => $status,
                'review_notes' => $request->input('review_notes'),
                'reviewed_by'  => $userId,
                'reviewed_at'  => now(),
            ]
        );

        return response()->json([
            'status'  => 1,
            'message' => "Match marked as {$status}",
            'data'    => $match,
        ]);
    }

    /**
     * GET /signals/opportunity-matches
     * List all matches marked for review or follow-up.
     */
    public function listMatches(Request $request): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);
        if (! $tenantId) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $v = Validator::make($request->query(), [
            'status'      => 'nullable|string|in:Matched,Reviewed,Follow-up,Dismissed',
            'signal_type' => 'nullable|string|in:signal,opportunity,ingestion_finding',
            'per_page'    => 'nullable|integer|min:1|max:100',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $query = OpportunityMatch::query()
            ->where('sub_institute_id', $tenantId);

        if ($status = $request->query('status')) {
            $query->where('match_status', $status);
        }

        if ($type = $request->query('signal_type')) {
            $query->where('signal_type', $type);
        }

        $perPage = (int) $request->query('per_page', 20);
        $matches = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json([
            'status' => 1,
            'data'   => $matches,
        ]);
    }
}

