<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class DepartmentManagementController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('type');
        $token = $request->query('token');

        // Token validation for API type
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
            return response()->json(['error' => 'sub_institute_id is required'], 400);
        }

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $sub_institute_id)
            ->where('status', 1)
            ->get();

        $mainDepartments = [];
        $subDepartments = [];

        foreach ($departments as $department) {
            if ($department->parent_id == 0) {
                $mainDepartments[] = $department;
            } else {
                $subDepartments[$department->parent_id][] = $department;
            }
        }

        return response()->json([
            'main_departments' => $mainDepartments,
            'sub_departments' => $subDepartments
        ]);
    }

    public function store(Request $request)
    {
        $type = $request->query('type');
        $token = $request->query('token');

        // Token validation for API type
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
            'department' => 'required|string',
            'parent_id' => 'nullable|numeric',
            'user_id' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 400);
        }

        $sub_institute_id = $request->sub_institute_id;
        $department = $request->department;
        $parent_id = $request->parent_id ?? 0;
        $user_id = $request->user_id;

        $check = DB::table('hrms_departments')
            ->where('department', $department)
            ->where('sub_institute_id', $sub_institute_id)
            ->where('parent_id', $parent_id)
            ->first();

        if ($check) {
            return response()->json([
                'status' => 0,
                'message' => 'Department already exists'
            ], 400);
        }

        $departmentId = DB::table('hrms_departments')->insertGetId([
            'department' => $department,
            'parent_id' => $parent_id,
            'tasks' => null,
            'roles_responsibility' => $department,
            'status' => 1,
            'is_calculated' => 0,
            'sub_institute_id' => $sub_institute_id,
            'created_by' => $user_id,
            'created_at' => now(),
        ]);

        // Insert into s_user_jobrole
        $jobRoleData = [
            'sub_institute_id' => $sub_institute_id,
            'department' => $parent_id == 0 ? $department : DB::table('hrms_departments')->where('id', $parent_id)->value('department'),
            'department_id' => $parent_id == 0 ? $departmentId : $parent_id,
            'created_by' => $user_id,
            'created_at' => now(),
        ];

        if ($parent_id != 0) {
            $jobRoleData['sub_department'] = $department;
        }

        DB::table('s_user_jobrole')->insert($jobRoleData);

        return response()->json([
            'status' => 1,
            'message' => 'Department added successfully',
            'data' => ['id' => $departmentId]
        ]);
    }

    public function update(Request $request, $id)
    {
        $type = $request->query('type');
        $token = $request->query('token');

        // Token validation for API type
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
            'department' => 'required|string',
            'user_id' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->messages()->first()
            ], 400);
        }

        $sub_institute_id = $request->sub_institute_id;
        $department = $request->department;
        $user_id = $request->user_id;

        $update = DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->update([
                'department' => $department,
                'updated_by' => $user_id,
                'updated_at' => now(),
            ]);

        if ($update) {
            // Update s_user_jobrole as well
            $dept = DB::table('hrms_departments')->where('id', $id)->first();
            if ($dept->parent_id == 0) {
                DB::table('s_user_jobrole')
                    ->where('department_id', $id)
                    ->update(['department' => $department, 'updated_at' => now(), 'updated_by' => $user_id]);
            } else {
                DB::table('s_user_jobrole')
                    ->where('department_id', $dept->parent_id)
                    ->where('sub_department', $dept->department)
                    ->update(['sub_department' => $department, 'updated_at' => now(), 'updated_by' => $user_id]);
            }

            return response()->json([
                'status' => 1,
                'message' => 'Department updated successfully'
            ]);
        } else {
            return response()->json([
                'status' => 0,
                'message' => 'Department not found or update failed'
            ], 404);
        }
    }

    public function destroy(Request $request, $id)
    {
        $type = $request->query('type');
        $token = $request->query('token');

        // Token validation for API type
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
            return response()->json(['error' => 'sub_institute_id is required'], 400);
        }

        $update = DB::table('hrms_departments')
            ->where('id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->update(['status' => 0, 'deleted_at' => now()]);

        // Soft delete subdepartments
        DB::table('hrms_departments')
            ->where('parent_id', $id)
            ->where('sub_institute_id', $sub_institute_id)
            ->update(['status' => 0, 'deleted_at' => now()]);

        if ($update) {
            return response()->json([
                'status' => 1,
                'message' => 'Department and its subdepartments deleted successfully'
            ]);
        } else {
            return response()->json([
                'status' => 0,
                'message' => 'Department not found or soft delete failed'
            ], 404);
        }
    }
}