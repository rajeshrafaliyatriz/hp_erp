<?php

namespace App\Http\Controllers\ai_generated_assessment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\lms\questionpaperModel;
use App\Models\lms\lmsQuestionMappingModel;
use App\Models\ai_generated_assessment\QuestionMaster;
use App\Models\ai_generated_assessment\AnswerMaster;

class generateAssessmentController extends Controller
{
    public function index(Request $request)
    {
        $query = questionpaperModel::query();

        // Optional filters
        if ($request->filled('standard_id')) {
            $query->where('standard_id', $request->standard_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('sub_institute_id')) {
            $query->where('sub_institute_id', $request->sub_institute_id);
        }

        $papers = $query->orderBy('created_on', 'desc')->get();

        // Collect all question IDs
        $questionIds = collect($papers)->pluck('question_ids')->map(function($ids) {
            return $ids ? explode(',', $ids) : [];
        })->flatten()->unique()->filter()->values()->toArray();

        // Fetch questions with answers
        $questions = QuestionMaster::whereIn('id', $questionIds)->with('answers')->get()->keyBy('id');

        // Fetch mappings for the questions
        $mappings = lmsQuestionMappingModel::whereIn('questionmaster_id', $questionIds)->where('mapping_type_id', 7)->get()->groupBy('questionmaster_id');

        // Build data with nested questions and mappings
        $data = $papers->map(function($paper) use ($questions, $mappings) {
            $paperArray = $paper->toArray();
            $ids = $paper->question_ids ? explode(',', $paper->question_ids) : [];
            $paperArray['questions'] = collect($ids)->map(function($id) use ($questions, $mappings) {
                $q = $questions->get((int)$id);
                if ($q) {
                    $qArray = $q->toArray();
                    $qArray['mappings'] = $mappings->get($id, collect())->toArray();
                    return $qArray;
                }
                return null;
            })->filter()->values()->toArray();
            return $paperArray;
        });

        return response()->json([
            'status' => true,
            'data' => $data->toArray()
        ], 200);
    }
    public function store(Request $request)
    {
        $request->validate([
            'standard_id'        => 'required|integer',
            'paper_name'         => 'required|string',
            'paper_desc'         => 'nullable|string',
            'open_date'          => 'required|date',
            'close_date'         => 'required|date|after_or_equal:open_date',
            'timelimit_enable'   => 'required|integer|in:0,1',
            'time_allowed'       => 'nullable|integer',
            'total_marks'        => 'required|integer',
            'total_ques'         => 'required|integer',
            'question_ids'       => 'required|string', // comma separated IDs
            'shuffle_question'   => 'required|integer|in:0,1',
            'attempt_allowed'    => 'required|integer',
            'show_feedback'      => 'required|integer|in:0,1',
            'show_hide'          => 'required|integer|in:0,1',
            'result_show_ans'    => 'required|integer|in:0,1',
            'created_by'         => 'required|integer',
            'sub_institute_id'   => 'required|integer',
            'exam_type'          => 'required|string'
        ]);

        try {
            Log::info('mapping_type_id from request: ' . $request->mapping_type_id);
            Log::info('mapping_value_id from request: ' . $request->mapping_value_id);

            $paper = questionpaperModel::create($request->all());

            $questionIds = explode(',', $request->question_ids);
            foreach ($questionIds as $qid) {
                if (!empty(trim($qid))) {
                    Log::info('Creating mapping for qid ' . trim($qid) . ' with type: ' . $request->mapping_type_id . ' value: ' . $request->mapping_value_id);
                    $mapping = lmsQuestionMappingModel::create([
                        'questionmaster_id' => trim($qid),
                        'mapping_type_id' => $request->mapping_type_id,
                        'mapping_value_id' => $request->mapping_value_id,
                        'reasons' => $request->reasons,
                        'created_by' => $request->created_by,
                        'sub_institute_id' => $request->sub_institute_id,
                    ]);
                    Log::info('Created mapping id: ' . $mapping->id . ' stored type: ' . $mapping->mapping_type_id . ' value: ' . $mapping->mapping_value_id);
                    $check = lmsQuestionMappingModel::find($mapping->id);
                    Log::info('DB values after creation: type ' . $check->mapping_type_id . ' value ' . $check->mapping_value_id);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Assesment stored successfully',
                'paper_id' => $paper->id
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
}
}