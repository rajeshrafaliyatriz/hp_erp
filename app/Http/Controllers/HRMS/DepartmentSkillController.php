<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class DepartmentSkillController extends Controller
{
    public function index(Request $request)
    {
        try {
            $type = $request->query('type');
            $token = $request->query('token');

            if ($type == "api") {
                if (!$token || $token == "") {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Token not provided'
                    ], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Invalid token'
                    ], 401);
                }
            }

            $sub_institute_id = $request->input('sub_institute_id');

            if (!$sub_institute_id) {
                return response()->json([
                    'status' => 0,
                    'message' => 'sub_institute_id is required'
                ], 400);
            }

            $departments = DB::table('hrms_departments')
                ->where('sub_institute_id', $sub_institute_id)
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->select('id', 'department', 'parent_id', 'status')
                ->orderBy('department')
                ->get();

            $departmentIds = $departments->pluck('id')->toArray();

            $skills = DB::table('s_users_skills')
                ->whereIn('department_id', $departmentIds)
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNull('deleted_at')
                ->select('id', 'department_id', 'title', 'description', 'category', 'sub_category', 'proficiency_level', 'status')
                ->orderBy('title')
                ->get();

            $skillsByDepartment = [];
            foreach ($skills as $skill) {
                $skillsByDepartment[$skill->department_id][] = (array) $skill;
            }

            $mainDepartments = [];
            $subDepartments = [];

            foreach ($departments as $dept) {
                $dept->skills = $skillsByDepartment[$dept->id] ?? [];
                $dept->skills_count = count($dept->skills);

                if ($dept->parent_id == 0) {
                    $mainDepartments[] = $dept;
                } else {
                    $subDepartments[$dept->parent_id][] = $dept;
                }
            }

            // Aggregate skills from sub-departments into main departments
            foreach ($mainDepartments as $main) {
                $allSkills = $main->skills;
                if (isset($subDepartments[$main->id])) {
                    foreach ($subDepartments[$main->id] as $sub) {
                        $allSkills = array_merge($allSkills, $sub->skills);
                    }
                }
                $main->skills = $allSkills;
                $main->skills_count = count($allSkills);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Departments with skills count fetched successfully',
                'data' => [
                    'main_departments' => $mainDepartments,
                    'sub_departments' => $subDepartments
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $departmentId)
    {
        try {
            $type = $request->query('type');
            $token = $request->query('token');

            if ($type == "api") {
                if (!$token || $token == "") {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Token not provided'
                    ], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Invalid token'
                    ], 401);
                }
            }

            $sub_institute_id = $request->input('sub_institute_id');

            if (!$sub_institute_id) {
                return response()->json([
                    'status' => 0,
                    'message' => 'sub_institute_id is required'
                ], 400);
            }

            $department = DB::table('hrms_departments')
                ->where('id', $departmentId)
                ->where('sub_institute_id', $sub_institute_id)
                ->where('status', 1)
                ->select('id', 'department', 'parent_id', 'status')
                ->first();

            if (!$department) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Department not found'
                ], 404);
            }

            $skills = DB::table('s_users_skills')
                ->where('department_id', $departmentId)
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNull('deleted_at')
                ->select('id', 'title', 'description', 'category', 'sub_category', 'proficiency_level', 'status')
                ->orderBy('title')
                ->get();

            return response()->json([
                'status' => 1,
                'message' => 'Department skills fetched successfully',
                'data' => [
                    'department' => $department,
                    'skills' => $skills
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $type = $request->query('type');
            $token = $request->query('token');

            if ($type == "api") {
                if (!$token || $token == "") {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Token not provided'
                    ], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Invalid token'
                    ], 401);
                }
            }

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'department_id' => 'required|numeric',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'category' => 'nullable|string',
                'sub_category' => 'nullable|string',
                'proficiency_level' => 'nullable|string',
                'user_id' => 'required|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 0,
                    'message' => $validator->messages()->first()
                ], 400);
            }

            $sub_institute_id = $request->sub_institute_id;
            $department_id = $request->department_id;

            $department = DB::table('hrms_departments')
                ->where('id', $department_id)
                ->where('sub_institute_id', $sub_institute_id)
                ->where('status', 1)
                ->first();

            if (!$department) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Department not found'
                ], 404);
            }

            $skillId = DB::table('s_users_skills')->insertGetId([
                'department_id' => $department_id,
                'title' => $request->title,
                'description' => $request->description,
                'category' => $request->category,
                'sub_category' => $request->sub_category,
                'proficiency_level' => $request->proficiency_level,
                'status' => 'Active',
                'sub_institute_id' => $sub_institute_id,
                'created_by' => $request->user_id,
                'created_at' => now(),
            ]);

            return response()->json([
                'status' => 1,
                'message' => 'Skill added to department successfully',
                'data' => ['id' => $skillId]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $type = $request->query('type');
            $token = $request->query('token');

            if ($type == "api") {
                if (!$token || $token == "") {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Token not provided'
                    ], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Invalid token'
                    ], 401);
                }
            }

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'user_id' => 'required|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 0,
                    'message' => $validator->messages()->first()
                ], 400);
            }

            $sub_institute_id = $request->sub_institute_id;

            $skill = DB::table('s_users_skills')
                ->where('id', $id)
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNull('deleted_at')
                ->first();

            if (!$skill) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Skill not found'
                ], 404);
            }

            $updateData = [];
            if ($request->has('title')) $updateData['title'] = $request->title;
            if ($request->has('description')) $updateData['description'] = $request->description;
            if ($request->has('category')) $updateData['category'] = $request->category;
            if ($request->has('sub_category')) $updateData['sub_category'] = $request->sub_category;
            if ($request->has('proficiency_level')) $updateData['proficiency_level'] = $request->proficiency_level;
            if ($request->has('status')) $updateData['status'] = $request->status;
            if ($request->has('department_id')) $updateData['department_id'] = $request->department_id;

            $updateData['updated_by'] = $request->user_id;
            $updateData['updated_at'] = now();

            $updated = DB::table('s_users_skills')
                ->where('id', $id)
                ->where('sub_institute_id', $sub_institute_id)
                ->update($updateData);

            if ($updated) {
                return response()->json([
                    'status' => 1,
                    'message' => 'Department skill updated successfully'
                ]);
            }

            return response()->json([
                'status' => 0,
                'message' => 'Department skill not found or update failed'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $type = $request->query('type');
            $token = $request->query('token');

            if ($type == "api") {
                if (!$token || $token == "") {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Token not provided'
                    ], 401);
                }

                $accessToken = PersonalAccessToken::findToken($token);
                if (!$accessToken) {
                    return response()->json([
                        'status' => 0,
                        'message' => 'Invalid token'
                    ], 401);
                }
            }

            $sub_institute_id = $request->input('sub_institute_id');

            if (!$sub_institute_id) {
                return response()->json([
                    'status' => 0,
                    'message' => 'sub_institute_id is required'
                ], 400);
            }

            $updated = DB::table('s_users_skills')
                ->where('id', $id)
                ->where('sub_institute_id', $sub_institute_id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            if ($updated) {
                return response()->json([
                    'status' => 1,
                    'message' => 'Skill deleted from department successfully'
                ]);
            }

            return response()->json([
                'status' => 0,
                'message' => 'Skill not found or already deleted'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
