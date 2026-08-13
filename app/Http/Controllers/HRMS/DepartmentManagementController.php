<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class DepartmentManagementController extends Controller
{
    /**
     * G-SEC-29. THE TENANT COMES FROM THE TOKEN.
     *
     * Every `$request->...sub_institute_id` in this controller was replaced with
     * `$this->apiTenantId($request)`. Confirmed by execution before the change: a
     * tenant-7 caller asking for `sub_institute_id=3` received tenant 3's rows.
     *
     * THIS CONTROLLER HAD NO SESSION FALLBACK. Nine of the eighteen leaking
     * controllers read `session() ?? $request`, which leaks only when the session
     * is absent. This one read the request and nothing else, so it leaked on
     * EVERY call - the worst of the three shapes, which is why it went first.
     *
     * ONE IMPLEMENTATION, NOT THIRTEEN. `apiTenantId()` lives in
     * ResolvesApiIdentity and is called directly. A private wrapper per
     * controller would put the resolution in thirteen places, which is how four
     * identity resolvers happened.
     */
    use ResolvesApiIdentity;

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

        $sub_institute_id = $this->apiTenantId($request);

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

        $sub_institute_id = $this->apiTenantId($request);
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

        $sub_institute_id = $this->apiTenantId($request);
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

        $sub_institute_id = $this->apiTenantId($request);

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