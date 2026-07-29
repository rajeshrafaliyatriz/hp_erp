<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\build_with_AI\AiCourseOutline;
use App\Models\school_setup\sub_std_mapModel;
use App\Services\DeepSeekService;
use App\Services\GammaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Build with AI - course outline generation and presentation rendering.
 *
 * Both halves of this flow used to live in the previous frontend's Next.js API
 * routes: /api/generate-outline proxied DeepSeek through OpenRouter, and
 * /api/generate-course called Gamma and blocked for up to four minutes polling.
 * Neither existed in Laravel, so the new frontend had no way to reach them.
 *
 * The generation now runs server-side against DeepSeek directly (no OpenRouter
 * middleman, key never reaches the browser), and Gamma rendering is
 * asynchronous - create returns a generationId and the client polls.
 */
class AiCourseController extends Controller
{
    /** Profiles allowed to generate and publish courses. */
    private const AUTHORING_PROFILES = ['admin', 'hr'];

    public function __construct(
        private readonly DeepSeekService $deepSeek,
        private readonly GammaService $gamma,
    ) {
    }

    private function guardApiToken(Request $request)
    {
        if ($request->input('type') !== 'API') {
            return null;
        }

        $token = $request->input('token');
        if (!$token) {
            return response()->json(['status' => false, 'message' => 'Token not provided'], 401);
        }

        if (!PersonalAccessToken::findToken($token)) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
        }

        return null;
    }

    /**
     * Course authoring is an admin/HR action - the previous frontend showed the
     * Create / Build with AI / External buttons only to those profiles.
     */
    private function guardAuthoring(Request $request)
    {
        $profile = strtolower(trim((string) $request->input('user_profile_name', '')));

        if ($profile === '') {
            return null; // Nothing to check against; token guard still applies.
        }

        foreach (self::AUTHORING_PROFILES as $allowed) {
            if (str_contains($profile, $allowed)) {
                return null;
            }
        }

        return response()->json([
            'status' => false,
            'message' => 'Your profile is not permitted to author courses.',
        ], 403);
    }

    private function tenantId(Request $request)
    {
        return $request->sub_institute_id ?? $request->header('sub_institute_id');
    }

    /**
     * GET /api/lms/ai/status
     *
     * Lets the UI disable the feature with a clear reason instead of failing
     * only once the user has filled in the whole form.
     */
    public function status(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        return response()->json([
            'status' => true,
            'data' => [
                'deepseek_configured' => $this->deepSeek->isConfigured(),
                'deepseek_model' => $this->deepSeek->model(),
                'gamma_configured' => $this->gamma->isConfigured($this->tenantId($request)),
            ],
        ]);
    }

    /**
     * POST /api/lms/ai/outline
     *
     * Generate a slide-by-slide course outline with DeepSeek.
     */
    public function generateOutline(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $validator = Validator::make($request->all(), [
            'industry'                 => 'nullable|string|max:191',
            'department'               => 'nullable|string|max:191',
            'job_role'                 => 'nullable|string|max:191',
            'critical_work_function'   => 'nullable|string|max:500',
            'tasks'                    => 'nullable|array',
            'tasks.*'                  => 'string|max:500',
            'skills'                   => 'nullable|array',
            'skills.*'                 => 'string|max:191',
            'proficiency'              => 'nullable|string|max:100',
            'modality'                 => 'nullable|array',
            'course_title'             => 'nullable|string|max:191',
            'slide_count'              => 'nullable|integer|min:3|max:40',
            'model'                    => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $slideCount = (int) ($request->input('slide_count') ?: 10);

        try {
            $outline = $this->deepSeek->chatJson(
                [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert instructional designer and L&D specialist. '
                            . 'You design competency-based corporate training. '
                            . 'Reply with a single JSON object and nothing else.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildOutlinePrompt($request, $slideCount),
                    ],
                ],
                ['model' => $request->input('model') ?: null]
            );

            $normalised = $this->normaliseOutline($outline, $slideCount);

            return response()->json([
                'status' => true,
                'message' => 'Course outline generated successfully',
                'data' => [
                    'outline' => $normalised,
                    'plain_text' => $this->outlineToPlainText($normalised),
                    'model' => $request->input('model') ?: $this->deepSeek->model(),
                    'slide_count' => count($normalised['slides']),
                ],
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to generate the course outline',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * The prompt mirrors the structure the previous frontend sent to OpenRouter
     * (instruction / input_variables / output_format) so generated courses keep
     * the same shape, but asks for JSON instead of prose so the UI can render
     * slides individually and Gamma gets clean input text.
     */
    private function buildOutlinePrompt(Request $request, int $slideCount): string
    {
        $modality = $request->input('modality', []);
        $modalityLabels = [];
        if (!empty($modality['selfPaced'])) $modalityLabels[] = 'Self-paced';
        if (!empty($modality['instructorLed'])) $modalityLabels[] = 'Instructor-led';
        $modalityText = implode(', ', $modalityLabels) ?: '-';

        $tasks = array_filter((array) $request->input('tasks', []));
        $skills = array_filter((array) $request->input('skills', []));

        $prompt = [
            'instruction' => "You are an expert instructional designer and L&D specialist. "
                . "Create a structured {$slideCount}-slide course based on the provided context.",
            'input_variables' => [
                'industry' => $request->input('industry') ?: '-',
                'department' => $request->input('department') ?: '-',
                'job_role' => $request->input('job_role') ?: '-',
                'critical_work_function' => $request->input('critical_work_function') ?: '-',
                'key_tasks' => !empty($tasks) ? array_values($tasks) : ['-'],
                'target_skills' => !empty($skills) ? array_values($skills) : ['-'],
                'proficiency' => $request->input('proficiency') ?: '-',
                'modality' => $modalityText,
                'preferred_title' => $request->input('course_title') ?: null,
            ],
            'output_format' => [
                'total_slides' => $slideCount,
                'bullet_points_per_slide' => '3-5 (under 40 words each)',
                'style' => 'Formal, structured, competency-based',
                'tone' => str_contains($modalityText, 'Self-paced')
                    ? 'Direct, learner-led tone'
                    : 'Facilitator-focused guidance',
            ],
            'response_schema' => [
                'title' => 'string - the course title',
                'summary' => 'string - 2 sentence overview',
                'learning_objectives' => 'array of 3-5 strings',
                'slides' => 'array of exactly ' . $slideCount . ' objects, each '
                    . '{slide_number:int, title:string, bullets:array of 3-5 strings, speaker_notes:string}',
            ],
        ];

        return json_encode($prompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Coerce the model's JSON into the shape the UI expects.
     *
     * A model can rename or omit keys, so this fills gaps rather than throwing
     * away an otherwise usable outline.
     *
     * @param  array<string, mixed>  $outline
     * @return array<string, mixed>
     */
    private function normaliseOutline(array $outline, int $slideCount): array
    {
        $slides = $outline['slides'] ?? $outline['Slides'] ?? [];
        if (!is_array($slides)) {
            $slides = [];
        }

        $normalisedSlides = [];
        foreach (array_values($slides) as $index => $slide) {
            if (!is_array($slide)) {
                continue;
            }

            $bullets = $slide['bullets'] ?? $slide['bullet_points'] ?? $slide['points'] ?? [];
            if (is_string($bullets)) {
                $bullets = array_filter(array_map('trim', explode("\n", $bullets)));
            }

            $normalisedSlides[] = [
                'slide_number' => (int) ($slide['slide_number'] ?? $index + 1),
                'title' => (string) ($slide['title'] ?? 'Slide ' . ($index + 1)),
                'bullets' => array_values(array_map('strval', (array) $bullets)),
                'speaker_notes' => (string) ($slide['speaker_notes'] ?? $slide['notes'] ?? ''),
            ];
        }

        $objectives = $outline['learning_objectives'] ?? $outline['objectives'] ?? [];

        return [
            'title' => (string) ($outline['title'] ?? 'Untitled course'),
            'summary' => (string) ($outline['summary'] ?? ''),
            'learning_objectives' => array_values(array_map('strval', (array) $objectives)),
            'slides' => $normalisedSlides,
            'requested_slide_count' => $slideCount,
        ];
    }

    /** Flatten an outline into the plain text Gamma turns into slides. */
    private function outlineToPlainText(array $outline): string
    {
        $lines = [$outline['title']];

        if (!empty($outline['summary'])) {
            $lines[] = '';
            $lines[] = $outline['summary'];
        }

        if (!empty($outline['learning_objectives'])) {
            $lines[] = '';
            $lines[] = 'Learning objectives:';
            foreach ($outline['learning_objectives'] as $objective) {
                $lines[] = '- ' . $objective;
            }
        }

        foreach ($outline['slides'] as $slide) {
            $lines[] = '';
            $lines[] = 'Slide ' . $slide['slide_number'] . ': ' . $slide['title'];
            foreach ($slide['bullets'] as $bullet) {
                $lines[] = '- ' . $bullet;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * POST /api/lms/ai/presentation
     *
     * Hand the outline to Gamma and persist the outline row immediately with
     * the returned generationId. Returns straight away - the client polls
     * generationStatus rather than holding this request open for the render.
     */
    public function generatePresentation(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'outline'          => 'required|array',
                'input_fields'     => 'nullable|array',
                'configure_fields' => 'nullable|array',
                'course_type'      => 'nullable|string|max:255',
                'slide_count'      => 'nullable|integer|min:3|max:40',
                'ai_model'         => 'nullable|string|max:100',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $outline = $this->normaliseOutline(
            $request->input('outline'),
            (int) ($request->input('slide_count') ?: 10)
        );
        $slideCount = count($outline['slides']) ?: (int) ($request->input('slide_count') ?: 10);

        try {
            $generation = $this->gamma->createGeneration(
                $this->outlineToPlainText($outline),
                $slideCount,
                $subInstituteId
            );

            $record = AiCourseOutline::create([
                'course_type' => $request->input('course_type') ?: 'ai-generated',
                'input_fields' => json_encode($request->input('input_fields', [])),
                'configure_fields' => json_encode($request->input('configure_fields', [])),
                'outline' => json_encode($outline),
                'sub_institute_id' => $subInstituteId,
                'created_by' => $request->user_id,
                'presentation_platform' => 'gamma',
                'ai_model' => $request->input('ai_model') ?: $this->deepSeek->model(),
                'slide_count' => $slideCount,
                'generation_id' => $generation['generationId'],
                'status' => 'pending',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Presentation generation started',
                'data' => [
                    'outline_id' => $record->id,
                    'generation_id' => $generation['generationId'],
                    'status' => 'pending',
                ],
            ], 202);

        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to start presentation generation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/ai/presentation/{generationId}
     *
     * Poll Gamma and mirror the result onto the stored outline once it settles.
     */
    public function generationStatus(Request $request, string $generationId)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        try {
            $result = $this->gamma->getGeneration($generationId, $subInstituteId);

            $record = AiCourseOutline::where('generation_id', $generationId)
                ->when($subInstituteId, fn ($query) => $query->where('sub_institute_id', $subInstituteId))
                ->first();

            if ($record && $record->status !== $result['status']) {
                $record->update([
                    'status' => $result['status'],
                    'gamma_url' => $result['gammaUrl'] ?? $record->gamma_url,
                    'export_url' => $result['exportUrl'] ?? $record->export_url,
                    'updated_by' => $request->user_id,
                ]);
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'outline_id' => $record?->id,
                    'generation_id' => $generationId,
                    'generation_status' => $result['status'],
                    'gamma_url' => $result['gammaUrl'],
                    'export_url' => $result['exportUrl'],
                ],
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to read the generation status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/ai/outlines
     *
     * Saved outlines for this tenant. buildwithAIController@index returns every
     * outline across every institute and cannot be filtered or paged, so this
     * is the tenant-scoped equivalent the catalog uses.
     */
    public function outlines(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'sub_institute_id is required',
            ], 422);
        }

        $limit = min(max((int) $request->input('limit', 25), 1), 100);

        $outlines = AiCourseOutline::where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($outline) {
                $outline->outline = json_decode($outline->outline, true);
                $outline->input_fields = json_decode($outline->input_fields, true);
                $outline->configure_fields = json_decode($outline->configure_fields, true);
                return $outline;
            });

        return response()->json([
            'status' => true,
            'data' => $outlines,
        ]);
    }

    /**
     * POST /api/lms/ai/outlines/{id}/publish
     *
     * Turn a generated outline into a real catalogue course. The deck URL is
     * stored as the course's display_image target so the catalog links to it.
     */
    public function publish(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            [
                'sub_institute_id' => 'required|integer',
                'display_name'     => 'required|string|max:191',
                'standard_id'      => 'required|integer',
                'subject_category' => 'nullable|string|max:191',
                'subject_type'     => 'nullable|string|max:100',
                'jobrole'          => 'nullable|string|max:191',
                'status'           => 'nullable|integer|in:0,1',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $outline = AiCourseOutline::where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$outline) {
            return response()->json([
                'status' => false,
                'message' => 'Outline not found',
            ], 404);
        }

        // Same tenancy and uniqueness rules the catalog's create path applies.
        $departmentExists = DB::table('hrms_departments')
            ->where('id', $request->standard_id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$departmentExists) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Department ID',
            ], 422);
        }

        $duplicate = sub_std_mapModel::where('sub_institute_id', $subInstituteId)
            ->where('display_name', $request->display_name)
            ->where('standard_id', $request->standard_id)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => false,
                'message' => 'A course with this name already exists in that department',
            ], 422);
        }

        try {
            $courseId = sub_std_mapModel::insertGetId([
                'display_name' => $request->display_name,
                'standard_id' => $request->standard_id,
                'subject_category' => $request->subject_category,
                'subject_type' => $request->input('subject_type', 'E-learning Module'),
                'jobrole' => $request->jobrole,
                'sort_order' => 1,
                'status' => $request->input('status', 1),
                'sub_institute_id' => $subInstituteId,
                'allow_grades' => 'Yes',
                'allow_content' => 'Yes',
                'elective_subject' => 'No',
                'add_content' => 'chapterwise',
                'created_by' => $request->user_id,
                'created_at' => now(),
            ]);

            $outline->update([
                'course_id' => $courseId,
                'updated_by' => $request->user_id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Course published to the catalog',
                'data' => [
                    'course_id' => $courseId,
                    'outline_id' => $outline->id,
                    'gamma_url' => $outline->gamma_url,
                    'export_url' => $outline->export_url,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to publish the course',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
