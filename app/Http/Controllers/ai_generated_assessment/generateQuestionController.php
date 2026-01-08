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

    public function bulkQuestion($questions, $subInstituteId)
    {
        try {
            $createdQuestions = [];
            foreach ($questions as $q) {
                $question = QuestionMaster::create([
                    'question_type_id' => $q['question_type_id'],
                    'standard_id'      => $q['standard_id'],
                    'question_title'   => $q['question_title'],
                    'description'      => $q['description'],
                    'points'           => $q['points'],
                    'paper_category'   => $q['paper_category'],
                    'multiple_answer' => $q['multiple_answer'],
                    'sub_institute_id' => $subInstituteId,
                    'domain_category' => $q['domainCategory'] ?? null,
                    'source_dataset'  => $q['sourceItem']['dataset'] ?? null,
                    'source_title'    => $q['sourceItem']['title'] ?? null,
                ]);

                $createdQuestions[] = $question;

                foreach ($q['answers'] as $answer) {
                    AnswerMaster::create([
                        'question_id' => $question->id,
                        'answer' => $answer['answer'],
                        'correct_answer'  => $answer['correct_answer'],
                        'sub_institute_id' => $subInstituteId,
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Questions and answers stored successfully',
                'question_ids' => collect($createdQuestions)->pluck('id')->toArray()
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
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

        // Check if it's bulk or single
        if ($request->has('questions') && is_array($request->questions)) {
            // Bulk insertion
            $request->validate([
                'questions' => 'required|array|min:1',
                'questions.*.question_type_id' => 'required|integer',
                'questions.*.standard_id'      => 'required|integer',
                'questions.*.question_title'   => 'required|string',
                'questions.*.description'      => 'nullable|string',
                'questions.*.points'           => 'required|integer',
                'questions.*.paper_category'   => 'required|string',
                'questions.*.multiple_answer' => 'required|integer',
                'questions.*.answers'          => 'required|array|min:1',
                'questions.*.answers.*.answer'   => 'required|string',
                'questions.*.answers.*.correct_answer' => 'required|integer|in:0,1',
                'questions.*.domainCategory'   => 'nullable|string',
                'questions.*.sourceItem'       => 'nullable|array',
                'questions.*.sourceItem.dataset' => 'nullable|string',
                'questions.*.sourceItem.title'   => 'nullable|string'
            ]);

            return $this->bulkQuestion($request->questions, $subInstituteId);
        } else {
            // Single question insertion
            $request->validate([
                'question_type_id' => 'required|integer',
                'standard_id'      => 'required|integer',
                'question_title'   => 'required|string',
                'description'      => 'nullable|string',
                'points'           => 'required|integer',
                'paper_category'   => 'required|string',
                'multiple_answer' => 'required|integer',
                'answers'          => 'required|array|min:1',
                'answers.*.answer'   => 'required|string',
                'answers.*.correct_answer' => 'required|integer|in:0,1',
                'domainCategory'   => 'nullable|string',
                'sourceItem'       => 'nullable|array',
                'sourceItem.dataset' => 'nullable|string',
                'sourceItem.title'   => 'nullable|string'
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
                    'multiple_answer' => $request->multiple_answer,
                    'sub_institute_id' => $subInstituteId,
                    'domain_category' => $request->domainCategory,
                    'source_dataset'  => $request->input('sourceItem.dataset'),
                    'source_title'    => $request->input('sourceItem.title'),
                ]);

                // Store Answers
                foreach ($request->answers as $answer) {
                    AnswerMaster::create([
                        'question_id' => $question->id,
                        'answer' => $answer['answer'],
                        'correct_answer'  => $answer['correct_answer'],
                        'sub_institute_id' => $subInstituteId,
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
}
