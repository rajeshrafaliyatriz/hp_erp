<?php

namespace App\Http\Controllers\lms;

use App\Http\Controllers\Controller;
use App\Models\lms\answermasterModel;
use App\Models\lms\chapterModel;
use App\Models\lms\contentModel;
use App\Models\lms\lmsQuestionMappingModel;
use App\Models\lms\questionmasterModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class blukCourseandQuetionGeneration extends Controller
{
    /**
     * Store API to save preview data for courses, questions, and answers
     *
     * Expected payload format:
     * {
     *   "rows": [
     *     {
     *       "contentType": "both",
     *       "template": "standard",
     *       "scheduleType": "immediate",
     *       "chapterName": "Chapter Name",
     *       "department": "Department Name",
     *       "departmentId": 35,
     *       "jobId": 3459,
     *       "jobRoleCategory": "Technical/Operational",
     *       "jobrole": "Job Role Name",
     *       "questionCount": 2,
     *       "slideCount": 1
     *     }
     *   ],
     *   "previewData": {
     *     "success": true,
     *     "summary": { "total": 2, "succeeded": 2, "failed": 0 },
     *     "results": [
     *       {
     *         "rowIndex": 1,
     *         "success": true,
     *         "topic": "Chapter Name",
     *         "department": "Department Name",
     *         "jobRole": "Job Role Name",
     *         "contentType": "both",
     *         "course": {
     *           "success": true,
     *           "generationId": "abc123",
     *           "gammaUrl": "https://gamma.app/...",
     *           "exportUrl": "https://assets.api.gamma.app/..."
     *         },
     *         "assessment": {
     *           "success": true,
     *           "questionCount": 2,
     *           "questions": [
     *             {
     *               "id": 1,
     *               "question_title": "Question text",
     *               "marks": 1,
     *               "reason": "Explanation",
     *               "answers": [
     *                 { "answer": "Answer 1", "correct_answer": 1 },
     *                 { "answer": "Answer 2", "correct_answer": 0 }
     *               ]
     *             }
     *           ]
     *         }
     *       }
     *     ]
     *   }
     * }
     */
    public function store(Request $request)
    {
        try {
            // Validate request
            $request->validate([
                'rows' => 'required|array|min:1',
                'previewData' => 'required|array',
                'previewData.results' => 'required|array',
            ]);

            $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id') ?? 1;
            $createdBy = $request->user_id ?? $request->header('user_id') ?? 1;

            $results = [];
            $successCount = 0;
            $failedCount = 0;

            DB::beginTransaction();

            foreach ($request->previewData['results'] as $result) {
                try {
                    $rowIndex = $result['rowIndex'];
                    $rowData = $request->rows[$rowIndex - 1] ?? null;

                    if (! $rowData) {
                        throw new \Exception('Row data not found for index: '.$rowIndex);
                    }

                    // Store Course Data
                    $courseData = $this->storeCourseData($result, $rowData, $subInstituteId, $createdBy);

                    // Store Assessment/Question Data
                    $assessmentData = $this->storeAssessmentData(
                        $result,
                        $courseData['chapter_id'],
                        $rowData,
                        $subInstituteId,
                        $createdBy,
                        $courseData['subject_id']
                    );

                    $results[] = [
                        'rowIndex' => $rowIndex,
                        'success' => true,
                        'chapter_id' => $courseData['chapter_id'],
                        'content_id' => $courseData['content_id'],
                        'question_ids' => $assessmentData['question_ids'],
                    ];

                    $successCount++;
                } catch (\Exception $e) {
                    Log::error('Error processing row '.($result['rowIndex'] ?? 'unknown').': '.$e->getMessage());
                    $results[] = [
                        'rowIndex' => $result['rowIndex'] ?? 0,
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                    $failedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data stored successfully',
                'summary' => [
                    'total' => count($results),
                    'succeeded' => $successCount,
                    'failed' => $failedCount,
                ],
                'results' => $results,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Store API Error: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store course-related data (chapter_master, content_master, content_mapping_type)
     */
    private function storeCourseData($result, $rowData, $subInstituteId, $createdBy)
    {
        // 1. Create standard-subject mapping in sub_std_map first to get the id
        $subStdMapId = DB::table('sub_std_map')->insertGetId([
            'standard_id' => $rowData['departmentId'] ?? 1,
            'subject_id' => $rowData['jobId'] ?? 1,
            'display_name' => $result['topic'] ?? $rowData['chapterName'] ?? 'Untitled Chapter',
            'allow_grades' => '',
            'elective_subject' => 'No',
            'allow_content' => 'Yes',
            'subject_category' => $rowData['contentType'],
            'content_quantity' => $rowData['content_quantity'] ?? 'bulk',
            'display_image' => '',
            'sub_institute_id' => $subInstituteId,
            'sort_order' => $result['rowIndex'] ?? 1,
            'status' => '1',
            'load' => '',
            'optional_type' => null,
            'jobrole' => $rowData['jobrole'] ?? null,
            'created_at' => now(),
            'created_by' => $createdBy,
        ]);

        // 2. Create or update chapter in chapter_master
        $chapterId = DB::table('chapter_master')->insertGetId([
            'syear' => date('Y'),
            'sub_institute_id' => $subInstituteId,
            'grade_id' => 1,
            'standard_id' => $rowData['departmentId'],
            'subject_id' => $subStdMapId,
            'chapter_name' => $result['topic'] ?? $rowData['chapterName'] ?? 'Untitled Chapter',
            'chapter_desc' => $result['topic'] ?? $rowData['chapterName'] ?? '',
            'availability' => 1,
            'show_hide' => 1,
            'sort_order' => $result['rowIndex'] ?? 1,
            'created_at' => now(),
            'created_by' => $createdBy,
        ]);

        // 3. Create content in content_master
        $courseInfo = $result['course'] ?? [];
        $contentId = DB::table('content_master')->insertGetId([
            'grade_id' => 1,
            'standard_id' => $rowData['departmentId'],
            'subject_id' => $subStdMapId,
            'chapter_id' => $chapterId,
            'topic_id' => null,
            'sub_topic_id' => null,
            'title' => $result['topic'] ?? $rowData['chapterName'] ?? 'Untitled Course',
            'description' => 'Course for '.($result['jobRole'] ?? $rowData['jobrole'] ?? ''),
            'file_folder' => $courseInfo['generationId'] ?? null,
            'filename' => $courseInfo['exportUrl'] ?? $courseInfo['gammaUrl'] ?? null,
            'file_type' => 'link',
            'url' => $courseInfo['exportUrl'] ?? $courseInfo['gammaUrl'] ?? null,
            'sort_order' => $result['rowIndex'] ?? 1,
            'show_hide' => 1,
            'meta_tags' => json_encode([
                'department' => $result['department'] ?? $rowData['department'] ?? '',
                'jobRole' => $result['jobRole'] ?? $rowData['jobrole'] ?? '',
                'jobRoleCategory' => $rowData['jobRoleCategory'] ?? '',
                'contentType' => $rowData['contentType'] ?? 'jobrole',
                'template' => $rowData['template'] ?? 'standard',
                'scheduleType' => $rowData['scheduleType'] ?? 'immediate',
            ]),
            'content_category' => $rowData['content_quantity'] ?? 'bulk',
            'syear' => date('Y'),
            'sub_institute_id' => $subInstituteId,
            'created_at' => now(),
            'created_by' => $createdBy,
        ]);

        // 4. Create content mapping in lms_content_mapping_type
        DB::table('content_mapping_type')->insert([
            'content_id' => $contentId,
            'mapping_type_id' => $rowData['departmentId'] ?? 1,
            'mapping_value_id' => $subStdMapId,
            'created_at' => now(),
        ]);

        return [
            'chapter_id' => $chapterId,
            'content_id' => $contentId,
            'subject_id' => $subStdMapId,
        ];
    }

    /**
     * Store assessment/question data (question_master, answer_master, lms_question_mapping)
     */
    private function storeAssessmentData($result, $chapterId, $rowData, $subInstituteId, $createdBy, $subjectId)
    {
        $questionIds = [];
        $assessment = $result['assessment'] ?? null;

        if (! $assessment || ! isset($assessment['questions'])) {
            return ['question_ids' => []];
        }

        foreach ($assessment['questions'] as $q) {
            // 1. Create question in lms_question_master
            $questionId = DB::table('lms_question_master')->insertGetId([
                'question_type_id' => 1,
                'grade_id' => 1,
                'standard_id' => $rowData['departmentId'],
                'subject_id' => $subjectId,
                'chapter_id' => $chapterId,
                'question_title' => $q['question_title'] ?? 'Untitled Question',
                'description' => $q['reason'] ?? '',
                'points' => $q['marks'] ?? 1,
                'multiple_answer' => 0,
                'sub_institute_id' => $subInstituteId,
                'status' => 1,
                'created_by' => $createdBy,
                'created_on' => now(),
            ]);

            $questionIds[] = $questionId;

            // 2. Create answers in answer_master
            if (isset($q['answers']) && is_array($q['answers'])) {
                foreach ($q['answers'] as $answer) {
                    DB::table('answer_master')->insert([
                        'question_id' => $questionId,
                        'answer' => $answer['answer'] ?? '',
                        'feedback' => $q['reason'] ?? '',
                        'correct_answer' => $answer['correct_answer'] ?? 0,
                        'sub_institute_id' => $subInstituteId,
                        'created_by' => $createdBy,
                    ]);
                }
            }

            // 3. Create question mapping in lms_question_mapping
            DB::table('lms_question_mapping')->insert([
                'questionmaster_id' => $questionId,
                'mapping_type_id' => 1, // Default mapping type
                'mapping_value_id' => $chapterId,
                'reasons' => $q['reason'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
                'created_by' => $createdBy,
                'sub_institute_id' => $subInstituteId,
            ]);
        }

        return ['question_ids' => $questionIds];
    }

    /**
     * Extract filename from URL
     */
    private function extractFilename($url)
    {
        if (empty($url)) {
            return null;
        }
        $parts = explode('/', $url);

        return end($parts) ?? null;
    }

    /**
     * Get all stored data
     */
    public function index(Request $request)
    {
        try {
            $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id') ?? 1;

            $chapters = chapterModel::where('sub_institute_id', $subInstituteId)
                ->with(['contents', 'questions.answers'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Data fetched successfully',
                'data' => $chapters,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show single record
     */
    public function show(Request $request, $id)
    {
        try {
            $chapter = chapterModel::with(['contents', 'questions.answers'])
                ->findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Data fetched successfully',
                'data' => $chapter,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Record not found',
            ], 404);
        }
    }

    /**
     * Delete record
     */
    public function destroy(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $chapter = chapterModel::findOrFail($id);

            // Delete related content
            contentModel::where('chapter_id', $chapter->id)->delete();

            // Delete related questions and answers
            $questions = questionmasterModel::where('chapter_id', $chapter->id)->get();
            foreach ($questions as $question) {
                answermasterModel::where('question_id', $question->id)->delete();
                lmsQuestionMappingModel::where('questionmaster_id', $question->id)->delete();
            }
            questionmasterModel::where('chapter_id', $chapter->id)->delete();

            // Delete chapter
            $chapter->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Record deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
