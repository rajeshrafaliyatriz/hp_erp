<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Competencies CRUD.
 *
 * In this ERP a "competency" is an approved skill, so this operates on the
 * existing s_users_skills catalog (no separate competency table). Backs the
 * "Create Competency" quick action on the Command Center and the Competency
 * Library screen. Competency status maps onto the skill's approve_status
 * (published -> Approved, otherwise Pending).
 */
/**
 * ⚠ THIS CLASS MANAGES SKILL LIBRARY ENTRIES, NOT COMPETENCIES.
 *
 * store() inserts into `s_users_skills` - a FLAT SKILL ROW. It does not touch
 * `competency` or `competency_kasba_item`, so what the product calls "creating a
 * competency" here has never created a competency in Q-A2's sense (a named
 * bundle of KASBA items).
 *
 * A competency is created by {@see CompetencyDefinitionController} on
 * /api/competency/definitions.
 *
 * Left in place deliberately: the library screen reads what it writes, and
 * changing the target to save a rename would break a working screen. The routes
 * alias it as SkillLibraryCrudController so the route file says what it does.
 */
class CompetencyController extends Controller
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

        $query = DB::table('s_users_skills')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($status = $this->activeFilter($request->input('status'))) {
            // 'published' competencies == approved skills.
            $query->where('approve_status', $status === 'published' ? 'Approved' : 'Pending');
        }
        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get([
                'id',
                'title as name',
                'description',
                'category',
                'proficiency_level',
                'department_id',
                'approve_status',
                'status',
                'created_at',
            ]);

        return response()->json([
            'status'     => 1,
            'message'    => 'Competencies fetched successfully',
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
            'name'          => 'required|string|max:191',
            'description'   => 'nullable|string',
            'category'      => 'nullable|string|max:191',
            'status'        => 'nullable|in:draft,published,archived',
            'department_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $id = DB::table('s_users_skills')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'title'            => $request->input('name'),
            'description'      => $request->input('description'),
            'category'         => $request->input('category'),
            'department_id'    => $request->input('department_id'),
            'status'           => 'Active',
            // G-COMP-01: approval state is SERVER-OWNED. Sending status=published
            // used to mint an Approved competency with no review. Always Pending;
            // only ApprovalController may move it.
            'approve_status'   => 'Pending',
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'created_competency',
            'Created competency "' . $request->input('name') . '"',
            'competency',
            $id,
            $request->input('name')
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Competency created successfully',
            'data'    => ['id' => $id],
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $existing = DB::table('s_users_skills')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        DB::table('s_users_skills')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'deleted_competency',
            'Deleted competency "' . $existing->title . '"',
            'competency',
            (int) $id,
            $existing->title
        );

        return response()->json(['status' => 1, 'message' => 'Competency deleted successfully']);
    }
}
