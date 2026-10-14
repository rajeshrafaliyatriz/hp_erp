<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * THE HR QUEUE FOR "A GAP EXISTS AND NO COURSE CLOSES IT" - the Capability Gap
 * Inbox's backend.
 *
 * `capability_course_requests` + `capability_course_request_subjects` are
 * written only by `CapabilityGapResponder` (raising, re-seeing, superseding on
 * a rise) and `CourseCompetencyMapController::store()` (resolving, once a
 * course gets mapped). This controller is READ-plus-TWO-ACTIONS: list, show,
 * dismiss (with a reason - no silent disappearance from the queue) and reopen
 * (an explicit HR override of a dismissal's cooldown). It never creates a
 * request and never assigns a course itself.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route       routes/api.php
 *   middleware  `profile:admin,hr` - these rows name every employee's measured
 *               shortfall against their own role, which is why this is gated
 *               unlike /competency/approvals (token-only)
 *   tenant      competencyContext() -> resolveApiIdentity(), never a request field
 *   actor       the same; reviewer_id is never taken from the body (G-SEC-12)
 */
class CapabilityCourseRequestController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * GET /competency/course-requests?status=open&competency_id=&jobrole_id=
     *
     * One row per competency gap, aggregated across affected employees - the
     * HR action (create or map one course) resolves everyone at once, so the
     * list is grained the same way the table is.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $query = DB::table('capability_course_requests as req')
            ->leftJoin('s_user_jobrole as jr', function ($join) use ($sid) {
                $join->on('jr.id', '=', 'req.jobrole_id')->where('jr.sub_institute_id', '=', $sid);
            })
            ->where('req.sub_institute_id', $sid)
            ->whereNull('req.deleted_at');

        $status = (string) $request->input('status', '');
        if ($status !== '' && $status !== 'all') {
            $query->where('req.status', $status);
        }

        if ($request->filled('competency_id')) {
            $query->where('req.competency_id', $request->integer('competency_id'));
        }

        if ($request->filled('jobrole_id')) {
            $query->where('req.jobrole_id', $request->integer('jobrole_id'));
        }

        if ($request->boolean('mandatory_only')) {
            $query->where('req.is_mandatory', 1);
        }

        $rows = $query->orderByDesc('req.affected_count')->orderByDesc('req.last_seen_at')
            ->get([
                'req.id', 'req.competency_id', 'req.competency_name', 'req.jobrole_id',
                'req.required_proficiency', 'req.is_mandatory', 'req.affected_count', 'req.status',
                'req.created_course_id', 'req.dismissed_reason', 'req.dismissed_at',
                'req.first_raised_at', 'req.last_seen_at', 'req.times_seen',
                // `jobrole_id` on the request names whichever role's rating
                // FIRST raised it (raiseOrUpdateRequest's origin role) -
                // honest about that, not "the" role when several could
                // require the same competency.
                'jr.jobrole as jobrole_name',
            ]);

        $departmentByRequest = $this->singleDepartmentPerRequest($sid, $rows->pluck('id')->all());

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($r) => [
                'id'                    => (int) $r->id,
                'competency_id'         => (int) $r->competency_id,
                'competency_name'       => $r->competency_name,
                'jobrole_id'            => $r->jobrole_id ? (int) $r->jobrole_id : null,
                'jobrole_name'          => $r->jobrole_name,
                'required_proficiency'  => $r->required_proficiency !== null ? (int) $r->required_proficiency : null,
                'is_mandatory'          => (bool) $r->is_mandatory,
                'affected_count'        => (int) $r->affected_count,
                'status'                => $r->status,
                'created_course_id'     => $r->created_course_id ? (int) $r->created_course_id : null,
                'dismissed_reason'      => $r->dismissed_reason,
                'dismissed_at'          => $r->dismissed_at,
                // Days the competency has carried an unresolved gap, used by
                // the inbox for its "days pending" column/filter.
                'days_pending'          => (int) now()->diffInDays($r->first_raised_at),
                'first_raised_at'       => $r->first_raised_at,
                'last_seen_at'          => $r->last_seen_at,
                'times_seen'            => (int) $r->times_seen,
                // Null when the affected employees span more than one
                // department - a competency-grained row has no single
                // owning department to report, and guessing one would be
                // worse than leaving the filter unable to bucket it.
                'department'            => $departmentByRequest[(int) $r->id] ?? null,
            ])->values(),
            'empty_is_expected' => $rows->isEmpty(),
        ]);
    }

    /**
     * One department name per request id, ONLY where every currently
     * affected employee shares the same one - computed from
     * `capability_course_request_subjects`, not from the request's own
     * `jobrole_id` (which names a role, not a department, and only the
     * role that happened to raise the request first).
     *
     * Batched in two queries for the whole page rather than one per row.
     *
     * @param  list<int>  $requestIds
     * @return array<int, string>
     */
    private function singleDepartmentPerRequest(int $sid, array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = DB::table('capability_course_request_subjects as s')
            ->join('tbluser as u', 'u.id', '=', 's.user_id')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->where('s.sub_institute_id', $sid)
            ->whereIn('s.request_id', $requestIds)
            ->whereNotNull('d.department')
            ->select(['s.request_id', 'd.department'])
            ->distinct()
            ->get();

        $byRequest = [];
        foreach ($rows as $row) {
            $byRequest[(int) $row->request_id][] = $row->department;
        }

        $result = [];
        foreach ($byRequest as $requestId => $departments) {
            if (count(array_unique($departments)) === 1) {
                $result[$requestId] = $departments[0];
            }
        }

        return $result;
    }

    /**
     * GET /competency/course-requests/summary - the inbox's KPI strip.
     */
    public function summary(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $counts = DB::table('capability_course_requests')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as c, SUM(affected_count) as people')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $avgDays = DB::table('capability_course_requests')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('status', 'open')
            ->avg(DB::raw('DATEDIFF(NOW(), first_raised_at)'));

        return response()->json([
            'status' => 1,
            'data'   => [
                'needs_action'   => (int) ($counts->get('open')->c ?? 0),
                'auto_assigned'  => (int) ($counts->get('course_created')->c ?? 0),
                'dismissed'      => (int) ($counts->get('dismissed')->c ?? 0),
                'superseded'     => (int) ($counts->get('superseded')->c ?? 0),
                'people_affected' => (int) ($counts->get('open')->people ?? 0),
                'avg_days_pending' => $avgDays !== null ? round((float) $avgDays, 1) : null,
            ],
        ]);
    }

    /**
     * GET /competency/course-requests/{id} - the drawer: the request plus
     * who is affected, named.
     */
    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $request_ = DB::table('capability_course_requests')
            ->where('id', (int) $id)->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$request_) {
            return response()->json(['status' => 0, 'message' => 'Request not found.'], 404);
        }

        $subjects = DB::table('capability_course_request_subjects as s')
            ->leftJoin('tbluser as u', 'u.id', '=', 's.user_id')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->where('s.request_id', $request_->id)
            ->orderByDesc('s.gap')
            ->get([
                's.user_id', 's.required_proficiency', 's.measured_level', 's.gap', 's.added_at',
                'u.first_name', 'u.last_name',
                'd.department',
            ]);

        return response()->json([
            'status' => 1,
            'data'   => [
                'id'                   => (int) $request_->id,
                'competency_id'        => (int) $request_->competency_id,
                'competency_name'      => $request_->competency_name,
                'jobrole_id'           => $request_->jobrole_id ? (int) $request_->jobrole_id : null,
                'required_proficiency' => $request_->required_proficiency !== null ? (int) $request_->required_proficiency : null,
                'is_mandatory'         => (bool) $request_->is_mandatory,
                'status'               => $request_->status,
                'created_course_id'    => $request_->created_course_id ? (int) $request_->created_course_id : null,
                'dismissed_reason'     => $request_->dismissed_reason,
                'dismissed_at'         => $request_->dismissed_at,
                'first_raised_at'      => $request_->first_raised_at,
                'last_seen_at'         => $request_->last_seen_at,
                'affected' => $subjects->map(fn ($s) => [
                    'user_id'              => (int) $s->user_id,
                    'name'                 => trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')),
                    'department'           => $s->department,
                    'required_proficiency' => $s->required_proficiency !== null ? (int) $s->required_proficiency : null,
                    'measured_level'       => $s->measured_level !== null ? (float) $s->measured_level : null,
                    'gap'                  => $s->gap !== null ? (float) $s->gap : null,
                    'added_at'             => $s->added_at,
                ])->values(),
            ],
        ]);
    }

    /**
     * POST /competency/course-requests/{id}/dismiss - with a reason, always.
     *
     * A dismissal without a reason is an audit hole on a module that takes
     * provenance seriously everywhere else; the row is never deleted, only
     * marked, and CapabilityGapResponder will reopen it on its own once the
     * tenant's reflag_cooldown_days has passed and the gap is still seen.
     */
    public function dismiss(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:191',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $actor = (int) $context['user_id'];

        $updated = DB::table('capability_course_requests')
            ->where('id', (int) $id)->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->update([
                'status' => 'dismissed',
                'dismissed_reason' => $request->input('reason'),
                'dismissed_at' => now(),
                'reviewer_id' => $actor,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => 1,
            'message' => $updated ? 'Request dismissed.' : 'Request not found.',
        ]);
    }

    /**
     * POST /competency/course-requests/{id}/reopen - an explicit HR override
     * of a dismissal's cooldown, or a manual re-check of a superseded one.
     */
    public function reopen(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $actor = (int) $context['user_id'];

        $updated = DB::table('capability_course_requests')
            ->where('id', (int) $id)->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('status', ['dismissed', 'superseded'])
            ->update([
                'status' => 'open',
                'dismissed_reason' => null,
                'dismissed_at' => null,
                'reviewer_id' => $actor,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => 1,
            'message' => $updated ? 'Request reopened.' : 'Request not found, or already open.',
        ]);
    }
}
