<?php

namespace App\Http\Controllers\ai_generated_assessment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\ai_generated_assessment\QuestionMaster;
use App\Models\ai_generated_assessment\AnswerMaster;

class generateQuestionController extends Controller
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
        $questions = QuestionMaster::with('answers')->where('sub_institute_id', $subInstituteId)->get();

        return response()->json([
            'status' => true,
            'message' => 'Questions-answers fetched successfully',
            'data' => $questions
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

        $request->validate([
            'question_type_id' => 'required|integer',
            'standard_id'      => 'required|integer',
            'question_title'   => 'required|string',
            'description'      => 'nullable|string',
            'points'           => 'required|integer',
            'paper_category'   => 'required|integer',
            'multiple_answers' => 'required|integer',
            'answers'          => 'required|array|min:1',
            'answers.*.answer'   => 'required|string',
            'answers.*.correct_answer' => 'required|integer|in:0,1'
        ]);

        try {
            // Store Question
            $question = QuestionMaster::create([
                'question_type_id' => $request->question_type_id,
                'standard_id'      => $request->standard_id,
                'question_title'   => $request->question_title,
                'description'      => $request->description,
                'points'           => $request->points,
                'paper_category'   => $request->paper_category,
                'multiple_answers' => $request->multiple_answers,
                'sub_institute_id' => $request->sub_institute_id,
            ]);

            // Store Answers
            foreach ($request->answers as $answer) {
                AnswerMaster::create([
                    'question_id' => $question->id,
                    'answer' => $answer['answer'],
                    'correct_answer'  => $answer['correct_answer'],
                    'sub_institute_id' => $request->sub_institute_id,
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Question and answers stored successfully',
                'question_id' => $question->id
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
