<?php

namespace App\Http\Controllers\libraries;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\libraries\Jobroleskill;
use Laravel\Sanctum\PersonalAccessToken;


class jobroleskillcontroller extends Controller
{
    public function storeskill(Request $request)
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
         if (!$accessToken) {
                 return response()->json(['message' => 'Invalid token'], 401);
        }

        $sub_institute_id = $request->get('sub_institute_id');


          $validator = Validator::make($request->all(), [
            // 'task_category' => ['required','string','max:255',
            //     \Illuminate\Validation\Rule::unique('s_user_jobrole_task', 'task_category')
            //         ->where(function ($query) use ($sub_institute_id) {
            //             return $query->where('sub_institute_id', $sub_institute_id)
            //                          ->whereNull('deleted_at'); // if using soft deletes
            //         }),
            'department' => 'required|string',
             'status' => 'required|in:Active,Inactive',
             'sub_institute_id' => 'required|numeric',
            
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->messages()->first()
            ], 422);
        }

        try {
             $objjobrole = new Jobroleskill();

            //$objjobrole = jobrole::find($request->jobrole_category) ?? Skill::firstOrNew(['sub_institute_id' => $sub_institute_id]);
            $objjobrole->industries = $request->industries;
            $objjobrole->department = $request->department;
            $objjobrole->sub_department = $request->sub_department;
            $objjobrole->jobrole = $request->jobrole;
            $objjobrole->description = $request->description;
            $objjobrole->job_level = $request->job_level;
            $objjobrole->has_vertical_progression = $request->has_vertical_progression;
            $objjobrole->has_lateral_movement = $request->has_lateral_movement;
            $objjobrole->progression_type = $request->progression_type;
            $objjobrole->jobrole_category = $request->jobrole_category;
            $objjobrole->performance_expectation = $request->performance_expectation;
            $objjobrole->status = $request->status;
            $objjobrole->related_jobrole = $request->related_jobrole;
            $objjobrole->required_skill_experience = $request->required_skill_experience;
            $objjobrole->location = $request->location;
            $objjobrole->salary_range = $request->salary_range;
            $objjobrole->company_information = $request->company_information;
            $objjobrole->benefits = $request->benefits;
            $objjobrole->keyword_tags = $request->keyword_tags;
            $objjobrole->job_posting_date = $request->job_posting_date;
            $objjobrole->application_deadline = $request->application_deadline;
            $objjobrole->contact_information = $request->contact_information;
            $objjobrole->internal_tracking = $request->internal_tracking;
            $objjobrole->education = $request->education;
            $objjobrole->experience = $request->experience;
            $objjobrole->training = $request->training;
            $objjobrole->sub_institute_id = $sub_institute_id;
            $objjobrole->created_at = now(); 
            $objjobrole->created_by = $request->user_id; 
           // $objjobrole = Jobroleskill::whereDate('created_at', now()->toDateString())->get();

            


            
            if ($objjobrole->save()) {
                return response()->json(['message' => 'category added successfully !!','data' => $objjobrole], 200);
            }

            return response()->json(['message' => 'Something went wrong !!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}
}
