<?php

namespace App\Http\Controllers\lms_course_enroll;

use App\Http\Controllers\Controller;
use App\Models\lms_course_enroll\LmsCourseEnroll;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class LmsCourseEnrollController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->type;

        if ($type == "API") {

            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }
        $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
        $userId = $request->user_id ?? $request->header('user_id');
        $course = LmsCourseEnroll::where('user_id', $userId)->get();

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'user_id is required'
            ], 422);
        }
        return response()->json([
            'status' => true,
            'data' => $course
        ]);
    }

     public function store(Request $request)
    {
    $type = $request->type;

    if ($type == "API") {

        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
    }
 $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
    // ✅ VALIDATION
    $validator = \Validator::make($request->all(), [
        'user_id' => 'required|integer',
        'course_id' => 'required|integer|exists:sub_std_map,id',
        'status' => 'required|in:completed,in-progress',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date',
        'sub_institute_id' => 'nullable|integer'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 0,
            'message' => $validator->messages()->first()
        ], 422);
    }

    try {
        $objcourse = new LmsCourseEnroll();
        $objcourse->user_id = $request->user_id;
        $objcourse->course_id = $request->course_id;
        $objcourse->status = $request->status;
        $objcourse->start_date = $request->start_date;
        $objcourse->end_date = $request->end_date;
        $objcourse->sub_institute_id = $request->sub_institute_id;

        if ($objcourse->save()) {
            return response()->json([
                'message' => 'Course Enroll added successfully!',
                'data' => $objcourse
            ], 200);
        }

        return response()->json(['message' => 'Something went wrong!'], 500);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}


    public function show()
    {
        //
    }

    public function update(Request $request, string $id)
{
        $type = $request->type;

        if ($type == "API") {

            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
        }
    // $sub_institute_id = $request->sub_institute_id;
    $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

    // Validate required fields
    $validator = \Validator::make($request->all(), [
        'user_id' => 'required|integer',
        'course_id' => 'required|integer|exists:sub_std_map,id',
        'status' => 'required|in:completed,in-progress',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date',
        'sub_institute_id' => 'required|integer'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    // Find existing course enrollment record
    $courseEnroll = LmsCourseEnroll::where([
        'id' => $id
    ])->first();

    if (!$courseEnroll) {
        return response()->json([
            'message' => 'Enrollment record not found',
            'data' => $id
        ], 404);
    }

    // Update fields
    $update = $courseEnroll->update([
        'user_id' => $request->user_id,
        'course_id' => $request->course_id,
        'status' => $request->status,
        'start_date' => $request->start_date,
        'end_date' => $request->end_date,
        'sub_institute_id' => $request->sub_institute_id,
        'updated_by' => $request->user_id,
        'updated_at' => now()
    ]);

    if ($update) {
        return response()->json([
            'message' => 'Course enrollment updated successfully',
            'data' => $courseEnroll
        ], 200);
    }

    return response()->json([
        'message' => 'Failed to update course enrollment',
        'data' => $id
    ], 400);
}


     public function destroy(Request $request, string $id)
{
    $type = $request->type;

    // --------------------------
    // 🔐 API Token Validation
    // --------------------------
    if ($type == "API") {

        $token = $request->input('token');
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
    }
    $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');
    // --------------------------
    // 🛂 Required Validation
    // --------------------------
    $validator = \Validator::make($request->all(), [
        'user_id'           => 'required|integer',
        'sub_institute_id'  => 'required|integer'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors'  => $validator->errors()
        ], 422);
    }

    $sub_institute_id = $request->sub_institute_id;

    // --------------------------
    // 🔎 Find Enrollment Record
    // --------------------------
    $courseEnroll = LmsCourseEnroll::where([
        'sub_institute_id' => $sub_institute_id,
        'course_id'        => $id
    ])->first();

    if (!$courseEnroll) {
        return response()->json([
            'message' => 'Enrollment record not found',
            'data' => $id
        ], 404);
    }

    // --------------------------
    // 🗑 Soft Delete Record
    // --------------------------
    $delete = $courseEnroll->update([
        'deleted_at' => now(),
        'deleted_by' => $request->user_id,
        'updated_at' => now()
    ]);

    if ($delete) {
        return response()->json([
            'message' => 'Course enrollment deleted successfully',
            'data'    => $courseEnroll
        ], 200);
    }

    return response()->json([
        'message' => 'Failed to delete course enrollment',
        'data'    => $id
    ], 400);
}
}
