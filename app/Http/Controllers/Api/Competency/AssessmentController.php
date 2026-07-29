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

        $id = DB::table('s_competency_assessments')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'title'            => $request->input('title'),
            'framework_id'     => $request->input('framework_id'),
            'cycle_id'         => $request->input('cycle_id'),
            'user_id'          => $request->input('user_id'),
            'assessor_id'      => $request->input('assessor_id'),
            'department_id'    => $request->input('department_id'),
            'jobrole'          => $request->input('jobrole'),
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
