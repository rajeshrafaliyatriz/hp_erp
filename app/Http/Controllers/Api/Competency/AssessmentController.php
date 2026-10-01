<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD for competency assessments (s_competency_assessments). Backs the
 * "Launch Assessment" quick action and the Assessment Workspace screen.
 */
class AssessmentController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesJobRoleId;

    use ResolvesCompetencyContext;

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $query = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('status', $status);
        }
        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'status'     => 1,
            'message'    => 'Assessments fetched successfully',
            'data'       => $rows,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    /**
     * $id when that user belongs to the caller's organisation, else null.
     *
     * For OPTIONAL people-columns, where a foreign or unknown id should not
     * abort the write but must not be stored either - storing it would attach
     * this tenant's row to somebody it cannot see.
     */
    private function tenantUserOrNull(array $context, $id): ?int
    {
        $id = (int) $id;

        if ($id <= 0) {
            return null;
        }

        $exists = DB::table('tbluser')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->exists();

        return $exists ? $id : null;
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'title'         => 'required|string|max:191',
            'framework_id'  => 'nullable|integer',
            'cycle_id'      => 'nullable|integer',
            'user_id'       => 'nullable|integer',
            'assessor_id'   => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'jobrole'       => 'nullable|string|max:191',
            'status'        => 'nullable|in:open,in_progress,completed,overdue',
            'due_date'      => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }


        /*
         * THE SUBJECT MUST BE SOMEBODY IN THIS ORGANISATION.
         *
         * `user_id` (and `assessor_id`) was validated only as `integer`, so a foreign-tenant id
         * could be written into the owner column while the row carried THIS
         * tenant's sub_institute_id - the same defect as
         * LearningAssignmentController's unchecked user_ids[].
         *
         * competencySubject() answers both halves: 404 for an id outside the
         * caller's tenant, 403 for a subject a non-elevated caller may not
         * touch. The route gate limits this to managers and HR, so in practice this is
         * the tenant half - which is exactly the half a role gate cannot do.
         */
        $subjectId = (int) ($request->input('user_id'));

        if ($subjectId > 0) {
            $subject = $this->competencyPeopleSubject($context, $subjectId);

            if (!is_int($subject)) {
                return $subject;
            }
        }
        $id = DB::table('s_competency_assessments')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'title'            => $request->input('title'),
            'framework_id'     => $request->input('framework_id'),
            'cycle_id'         => $request->input('cycle_id'),
            'user_id'          => $request->input('user_id'),
            /*
             * The assessor is dropped unless they are in this tenant.
             *
             * Same unchecked-integer defect as user_id, and the audit named
             * only user_id. Dropped rather than refused: an unresolvable
             * assessor is a data-entry slip on an optional field, and a 422
             * would lose the whole assessment over it. Null means "not yet
             * assigned", which the column already allows.
             */
            'assessor_id'      => $this->tenantUserOrNull($context, $request->input('assessor_id')),
            'department_id'    => $request->input('department_id'),
            // The id is what the merge, renames and every id-based reader use;
            // the name stays because ~20 screens still read it. NULL when the
            // name is ambiguous - ResolvesJobRoleId refuses to guess.
            'jobrole'          => $request->input('jobrole'),
            'jobrole_id' => $this->resolveJobRoleIdFromRequest($request, (int) $context['sub_institute_id']),
            'status'           => $request->input('status', 'open'),
            'due_date'         => $request->input('due_date'),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'launched_assessment',
            'Launched assessment "' . $request->input('title') . '"',
            'assessment',
            $id,
            $request->input('title')
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Assessment created successfully',
            'data'    => ['id' => $id],
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $existing = DB::table('s_competency_assessments')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Assessment not found'], 404);
        }

        DB::table('s_competency_assessments')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'deleted_assessment',
            'Deleted assessment "' . $existing->title . '"',
            'assessment',
            (int) $id,
            $existing->title
        );

        return response()->json(['status' => 1, 'message' => 'Assessment deleted successfully']);
    }
}
