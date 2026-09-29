<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Role-mapping change approvals (Workflow & Review tab). Backs the
 * pending/approved/rejected queue on s_competency_mapping_reviews — a genuinely
 * new concept (no backing table or API existed in the current backend or the
 * old frontend, where mapping edits saved directly with no approval gate).
 */
class MappingReviewController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesJobRoleId;

    use ResolvesCompetencyContext;

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);
        $page = max((int) $request->input('page', 1), 1);
        $status = $this->activeFilter($request->input('status')) ?? 'pending';

        $base = DB::table('s_competency_mapping_reviews')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');

        // Tab counts (independent of the current filter).
        $counts = [
            'pending'  => (clone $base)->where('status', 'pending')->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
        ];

        $query = (clone $base)->where('status', $status);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        $data = $rows->map(fn ($r) => [
            'id'                => (int) $r->id,
            'jobrole'           => $r->jobrole,
            'department'        => $r->department,
            'framework_id'      => $r->framework_id ? (int) $r->framework_id : null,
            'submitted_by_name' => $r->submitted_by_name,
            'status'            => $r->status,
            'changes_count'     => (int) $r->changes_count,
            'changes'           => $r->changes,
            'note'              => $r->note,
            'submitted_at'      => $r->created_at ? Carbon::parse($r->created_at)->format('M j, Y') : null,
            'reviewed_at'       => $r->reviewed_at ? Carbon::parse($r->reviewed_at)->format('M j, Y') : null,
            'approval'          => $this->approvalInfoFor((int) $r->id, (string) $r->status),
        ])->all();

        return response()->json([
            'status'     => 1,
            'message'    => 'Mapping reviews fetched successfully',
            'data'       => $data,
            'counts'     => $counts,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'jobrole'       => 'required|string|max:191',
            'department'    => 'nullable|string|max:191',
            'department_id' => 'nullable|integer',
            'framework_id'  => 'nullable|integer',
            'changes_count' => 'nullable|integer|min:0',
            'changes'       => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Resolve the submitter's display name for the queue card.
        $submitterName = null;
        if ($context['user_id']) {
            $user = DB::table('tbluser')->where('id', $context['user_id'])->first();
            if ($user) {
                $submitterName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                $submitterName = $submitterName !== '' ? $submitterName : ($user->user_name ?? null);
            }
        }

        $id = DB::table('s_competency_mapping_reviews')->insertGetId([
            'sub_institute_id'  => $sid,
            // The id is what the merge, renames and every id-based reader use;
            // the name stays because ~20 screens still read it. NULL when the
            // name is ambiguous - ResolvesJobRoleId refuses to guess.
            'jobrole'           => $request->input('jobrole'),
            'jobrole_id' => $this->resolveJobRoleIdFromRequest($request, (int) $sid),
            'department'        => $request->input('department'),
            'department_id'     => $request->input('department_id'),
            'framework_id'      => $request->input('framework_id'),
            'submitted_by'      => $context['user_id'],
            'submitted_by_name' => $submitterName,
            'status'            => 'pending',
            'changes_count'     => (int) $request->input('changes_count', 0),
            'changes'           => $request->input('changes'),
            'created_by'        => $context['user_id'],
            'updated_by'        => $context['user_id'],
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Freeze a competency.assessment.review chain if the tenant has one
        // configured. Opens nothing — and changes nothing about what happens
        // next — for every tenant without an active chain here.
        app(\App\Services\Competency\MappingReviewApprovalWorkflow::class)->openFor((int) $id, (int) $sid);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'submitted_mapping_review',
            'Submitted role mapping for "' . $request->input('jobrole') . '" for review',
            'mapping_review',
            $id,
            $request->input('jobrole')
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Mapping submitted for review',
            'data'    => ['id' => $id],
        ], 201);
    }

    /** Approve or reject one review. */
    public function update(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
            'note'   => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $review = DB::table('s_competency_mapping_reviews')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();
        if (!$review) {
            return response()->json(['status' => 0, 'message' => 'Review not found'], 404);
        }

        $status = $request->input('action') === 'approve' ? 'approved' : 'rejected';

        /*
         * ROUND 4. CHAIN-ENFORCED WHEN A REAL OPEN STEP EXISTS.
         *
         * `currentStep()`, not `stepsFor()` — this endpoint has no guard
         * against a redundant re-decision either, so "steps exist but nothing
         * is pending" is reachable (already fully decided) and must fall
         * through to the unenforced path, not error. No role/manager check
         * existed here before this, so an enforced tenant gets its first real
         * gate; every other tenant is unchanged.
         */
        $workflow = app(\App\Services\Competency\MappingReviewApprovalWorkflow::class);
        $current = $workflow->currentStep((int) $id);

        if ($current !== null) {
            $actorRoleKey = \App\Support\RoleKey::forUserId((int) $context['user_id']);

            if (! $workflow->roleMayDecide($current, $actorRoleKey, (int) $context['user_id'])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'You are not the approver for this step.',
                ], 403);
            }

            $progress = $workflow->recordDecision(
                (int) $id,
                $current,
                $status,
                ['user_id' => (int) $context['user_id']],
                $request->input('note')
            );

            if (! empty($progress['conflict'])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Somebody else just decided this step. Refresh and try again.',
                ], 409);
            }

            if (! $progress['final']) {
                return response()->json([
                    'status'  => 1,
                    'message' => "Step {$progress['step']} of {$progress['of']} decided. Now awaiting {$progress['next']}.",
                ]);
            }

            // Chain finished on this decision — fall through to the same
            // row update every decision applies, enforced or not.
        }

        DB::table('s_competency_mapping_reviews')->where('id', $id)->update([
            'status'      => $status,
            'note'        => $request->input('note'),
            'reviewer_id' => $context['user_id'],
            'reviewed_at' => now(),
            'updated_by'  => $context['user_id'],
            'updated_at'  => now(),
        ]);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            $status . '_mapping_review',
            ucfirst($status) . ' role mapping for "' . $review->jobrole . '"',
            'mapping_review',
            (int) $id,
            $review->jobrole,
            $this->diffChanges($review, ['status' => $status], ['status' => 'Review Status'])
        );

        return response()->json(['status' => 1, 'message' => 'Review ' . $status . ' successfully']);
    }

    /** Approve many at once (explicit ids, or all pending when none given). */
    public function bulkApprove(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $query = DB::table('s_competency_mapping_reviews')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('status', 'pending');

        $ids = $request->input('ids');
        if (is_array($ids) && !empty($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            $query->whereIn('id', empty($ids) ? [0] : $ids);
        }

        // Capture the roles before the update so the audit entry can name them.
        $roles = (clone $query)->limit(20)->pluck('jobrole')->all();

        $affected = $query->update([
            'status'      => 'approved',
            'reviewer_id' => $context['user_id'],
            'reviewed_at' => now(),
            'updated_by'  => $context['user_id'],
            'updated_at'  => now(),
        ]);

        if ($affected > 0) {
            $this->logCompetencyActivity(
                $sid,
                $context['user_id'],
                'bulk_approved_mapping_reviews',
                'Approved ' . $affected . ' role mapping review' . ($affected === 1 ? '' : 's')
                    . ($roles ? ' (' . implode(', ', array_slice($roles, 0, 3))
                        . (count($roles) > 3 ? ', ...' : '') . ')' : ''),
                'mapping_review',
                null,
                $affected === 1 && $roles ? $roles[0] : ($affected . ' reviews')
            );
        }

        return response()->json([
            'status'  => 1,
            'message' => $affected . ' review(s) approved',
            'data'    => ['approved' => $affected],
        ]);
    }

    /**
     * ROUND 5 FOLLOW-UP. Same read-side addition made for the other
     * enforced domains — surface the chain a pending mapping review is
     * actually waiting on. update() (the decision endpoint) is already
     * chain-aware (built last round); this only adds visibility for a
     * screen that today shows just one Approve/Reject action per step.
     * `null` for any review with no active chain.
     */
    private function approvalInfoFor(int $reviewId, string $status): ?array
    {
        if ($status !== 'pending') {
            return null;
        }

        $workflow = app(\App\Services\Competency\MappingReviewApprovalWorkflow::class);
        $steps = $workflow->stepsFor($reviewId);
        if ($steps === []) {
            return null;
        }

        $current = $workflow->currentStep($reviewId);

        return [
            'pending' => $current !== null,
            'step_name' => $current['step_name'] ?? null,
            'approver_role' => $current['approver_role'] ?? null,
            'step' => $current['step_order'] ?? null,
            'of' => count($steps),
        ];
    }
}
