<?php

namespace App\Http\Controllers\build_with_AI;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\build_with_AI\AiCourseOutline;




class buildwithAIController extends Controller
{
     public function index(Request $request)
     {
         $token = $request->input('token');
 
         if (!$token) {
             return response()->json(['message' => 'Token not provided'], 401);
         }
 
         $accessToken = PersonalAccessToken::findToken($token);
         if (!$accessToken) {
             return response()->json(['message' => 'Invalid token'], 401);
         }
 
         try {
             // Fetch all course outlines
             $outlines = AiCourseOutline::get();
 
             // Manually decode the input_fields for each outline
             $outlines->transform(function ($outline) {
                 $outline->input_fields = json_decode($outline->input_fields, true);
                 $outline->configure_fields = json_decode($outline->configure_fields, true);
                 $outline->outline = json_decode($outline->outline, true);
                 return $outline;
             });
 
 
             return response()->json([
                 'status' => true,
                 'message' => 'AI Course Outlines received successfully',
                 'data' => $outlines
             ]);
 
         } catch (\Exception $e) {
             return response()->json([
                 'status' => false,
                 'message' => $e->getMessage()
             ], 500);
         }
     }
    public function store(Request $request)
    {
       // dd($request->all());

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

        $sub_institute_id = $request->get('sub_institute_id');
        $user_id = $request->get('user_id');

        $validator = Validator::make($request->all(), [
            'course_type'       => 'required|string',
            'input_fields'      => 'required|array',
            'configure_fields'  => 'required|array',
            'outline'           => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            // -------------------------------
            // Save Course Outline
            // -------------------------------
            $outline = AiCourseOutline::create([
                'course_type'       => $request->course_type,
                'input_fields'      => json_encode($request->input_fields),
                'configure_fields'  => json_encode($request->configure_fields),
                'outline'           => json_encode($request->outline),
                'sub_institute_id'  => $request->sub_institute_id,
               'created_by' => $user_id,
            ]);
            return response()->json([
                'status' => true,
                'message' => 'outline saved successfully',
                'outline' => $outline
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
}