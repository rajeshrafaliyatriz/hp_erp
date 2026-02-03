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
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:tbluser,id',
            'task_id' => 'required|integer',
            'course_id' => 'required|integer',
            'course_name' => 'required|string|max:255',
        ]);

        $suggestedCourse = SuggestedCourse::firstOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'task_id' => $validated['task_id'],
                'course_id' => $validated['course_id'],
            ],
            [
                'course_name' => $validated['course_name'],
            ]
        );

        return response()->json([
            'status' => true,
            'message' => $suggestedCourse->wasRecentlyCreated 
                ? 'Course suggestion saved successfully' 
                : 'Course suggestion already exists',
            'data' => $suggestedCourse,
        ], 201);
    }
}
