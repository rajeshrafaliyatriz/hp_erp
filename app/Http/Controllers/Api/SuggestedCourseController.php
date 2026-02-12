<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuggestedCourse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SuggestedCourseController extends Controller
{
    /**
     * Store a newly created course suggestion.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => 'required|integer',
            'task_id' => 'required|integer',
            'course_id' => 'required|integer',
            'course_name' => 'required|string',
            'sub_institute_id' => 'nullable|integer',
            'created_by' => 'nullable|integer',
        ]);

        $courseSuggestion = SuggestedCourse::create([
            'employee_id' => $request->employee_id,
            'task_id' => $request->task_id,
            'course_id' => $request->course_id,
            'course_name' => $request->course_name,
            'sub_institute_id' => $request->sub_institute_id,
            'created_by' => $request->created_by,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Course suggestion saved successfully',
            'data' => $courseSuggestion,
        ], 200);
    }
}
