<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\SuggestedCourse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SuggestedCourseController extends Controller
{
    use ResolvesApiIdentity;

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => 'required|integer|exists:tbluser,id',
            'task_id' => 'required|integer',
            'course_id' => 'required|integer',
            'course_name' => 'required|string',
            'sub_institute_id' => 'nullable|integer',
            'created_by' => 'nullable|integer',
        ]);

        $courseSuggestion = SuggestedCourse::firstOrCreate(
            [
                'employee_id' => $request->employee_id,
                'task_id'     => $request->task_id,
                'course_id'   => $request->course_id,
            ],
            [
                'course_name'      => $request->course_name,
                'sub_institute_id' => $this->apiTenantId($request),
                // G-SEC-12: the acting user comes from the token, never the request.
                'created_by'       => $this->apiUserId($request),
            ]
        );

        return response()->json([
            'status' => true,
            'message' => $courseSuggestion->wasRecentlyCreated
                ? 'Course suggestion saved successfully'
                : 'Course suggestion Already exist',
            'data' => $courseSuggestion,
        ], 201);
    }
}
