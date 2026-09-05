<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use App\Models\build_with_AI\AiCourseOutline;
use App\Models\school_setup\sub_std_mapModel;
use App\Services\DeepSeekService;
use App\Services\GammaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
    use ResolvesLmsIdentity;

    /** Profiles allowed to generate and publish courses. */
    private const AUTHORING_PROFILES = ['admin', 'hr'];

    public function __construct(
        private readonly DeepSeekService $deepSeek,
        private readonly GammaService $gamma,
    ) {
    }

    private function guardApiToken(Request $request)
    {
        // Was: `if ($request->input('type') !== 'API') return null;` followed by
        // a token check that discarded the token's owner. Omitting `type`
        // skipped authentication entirely. Identity now always comes from the
        // token - see ResolvesLmsIdentity.
        return $this->guardLmsToken($request);
    }

    /**
     * Course authoring is an admin/HR action - the previous frontend showed the
     * Create / Build with AI / External buttons only to those profiles.
     */
    private function guardAuthoring(Request $request)
    {
        // The profile now comes from the caller's tbluser row, not from
        // a `user_profile_name` they supplied themselves.
        return $this->guardLmsProfile($request, self::AUTHORING_PROFILES, 'Your profile is not permitted to author courses.');
    }

    private function tenantId(Request $request)
    {
        // The caller's own organisation, from their token - not from whatever
        // sub_institute_id the request asked for.
        return $this->lmsTenantId($request);
    }

    /**
     * GET /api/lms/ai/status
     *
     * Lets the UI disable the feature with a clear reason instead of failing
     * only once the user has filled in the whole form.
     */
    /**
     * GET /api/lms/ai/scope-options
     *
     * Everything the Build-with-AI form needs to be made of DROPDOWNS instead
     * of free text.
     *
     * ── WHY THIS ENDPOINT EXISTS ────────────────────────────────────────────
     *
     * Every field on that form was a plain text input: industry, department,
     * job role, proficiency, and a comma-separated "target skills" box. So the
     * generator was fed whatever somebody typed - "Healthcare", "healthcare",
     * "Health care" - and the outline it produced could not be traced back to
     * anything in the system. Worse, "target skills" had no relationship to the
     * job role above it, which is the whole basis on which a course is supposed
     * to be scoped.
     *
     * All of it already exists as real, related data:
     *
     *   s_industries            43 industries (global taxonomy)
     *   hrms_departments        the tenant's departments
     *   s_user_jobrole          266 roles for tenant 6, every one carrying a
     *                           department_id and an industry
     *   jobrole_competency_map  job role -> competency
     *   competency_kasba_item   competency -> knowledge / skill / ability /
     *                           attitude / behaviour
     *
     * ── COMPETENCIES OR KASBA, NOT BOTH BY ACCIDENT ─────────────────────────
     *
     * Only 9 of tenant 6's 266 job roles have competencies mapped. So a form
     * that offered competencies alone would be empty for 96% of roles. The
     * response therefore carries BOTH: the competencies a role maps to, and the
     * individual KASBA items underneath them, so the author can scope by
     * whichever their organisation has actually filled in.
     */
    public function scopeOptions(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAuthoring($request)) {
            return $roleError;
        }

        $tenant = $this->tenantId($request);

        try {
            /*
             * Industries come from the shared taxonomy, which has no tenant
             * column - it is a reference list, not tenant data. Narrowed to
             * what this tenant's own job roles actually use when there is such
             * a set, because offering all 43 industries to an organisation
             * working in one of them is a worse list, not a richer one.
             */
            $usedIndustries = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->whereNotNull('industries')
                ->where('industries', '!=', '')
                ->distinct()
                ->orderBy('industries')
                ->pluck('industries');

            $industries = $usedIndustries->isNotEmpty()
                ? $usedIndustries
                : DB::table('s_industries')->distinct()->orderBy('industries')->pluck('industries');

            $departments = DB::table('hrms_departments')
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->orderBy('department')
                ->get(['id', 'department']);

            /*
             * Job roles carry their own department, so the form can narrow the
             * role list to the departments chosen above rather than showing all
             * 266 at once - which is a search problem, not a choice.
             */
            $jobroles = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->orderBy('jobrole')
                ->get(['id', 'jobrole', 'department_id', 'department', 'industries']);

            $jobroleIds = collect((array) $request->input('jobrole_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values();

            $competencies = collect();
            $kasba = collect();

            if ($jobroleIds->isNotEmpty()) {
                $competencies = DB::table('jobrole_competency_map as m')
                    ->join('competency as c', 'c.id', '=', 'm.competency_id')
                    ->where('m.sub_institute_id', $tenant)
                    ->whereIn('m.jobrole_id', $jobroleIds)
                    ->whereNull('c.deleted_at')
                    ->distinct()
                    ->orderBy('c.name')
                    ->get([
                        'c.id', 'c.name', 'c.code',
                        'm.required_proficiency', 'm.is_mandatory', 'm.jobrole_id',
                    ]);

                /*
                 * The KASBA items underneath those competencies.
                 *
                 * This is the fallback the customer asked for: when a role has
                 * no competencies mapped - true of 257 of 266 roles - the
                 * author picks knowledge / skill / behaviour / attitude /
                 * ability items directly instead.
                 */
                if ($competencies->isNotEmpty()) {
                    $kasba = DB::table('competency_kasba_item')
                        ->where('sub_institute_id', $tenant)
                        ->whereIn('competency_id', $competencies->pluck('id'))
                        ->orderBy('kasba_type')
                        ->orderBy('item_label')
                        ->get(['id', 'competency_id', 'kasba_type', 'item_label', 'weight']);
                }
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'industries' => $industries->values(),
                    'departments' => $departments,
                    'jobroles' => $jobroles,
                    'competencies' => $competencies->values(),
                    'kasba_items' => $kasba->values(),
                    // Named here rather than hardcoded in the component, so the
                    // five types come from the enum that defines them.
                    'kasba_types' => ['knowledge', 'skill', 'behaviour', 'attitude', 'ability'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load the course scope options',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

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

        /*
         * ── THE SCOPE ARRIVES AS IDS NOW, NOT AS TYPED WORDS ────────────────
         *
         * Every one of these was a free-text string: industry, department,
         * job_role, proficiency, and a `skills` array of whatever somebody had
         * typed into a comma-separated box. So two authors describing the same
         * course produced two different sets of inputs, and the published
         * course could not be related to a department, a role, or a competency
         * afterwards.
         *
         * `department` / `job_role` / `skills` are still ACCEPTED so an older
         * client keeps working, but the id forms take precedence and are what
         * the form now sends.
         *
         * `modality` is gone. Two checkboxes whose only observable effect was a
         * slide headed "Modality Instructions" and a one-word change of tone.
         */
        $validator = Validator::make($request->all(), [
            'industry'                 => 'nullable|string|max:191',
            'department_ids'           => 'nullable|array',
            'department_ids.*'         => 'integer',
            'jobrole_ids'              => 'nullable|array',
            'jobrole_ids.*'            => 'integer',
            'scope_mode'               => 'nullable|in:competency,kasba',
            'competency_ids'           => 'nullable|array',
            'competency_ids.*'         => 'integer',
            'kasba_item_ids'           => 'nullable|array',
            'kasba_item_ids.*'         => 'integer',
            'critical_work_function'   => 'nullable|string|max:500',
            'tasks'                    => 'nullable|array',
            'tasks.*'                  => 'string|max:500',
            'proficiency'              => 'nullable|string|max:100',
            'course_title'             => 'nullable|string|max:191',
            'slide_count'              => 'nullable|integer|min:3|max:40',
            'model'                    => 'nullable|string|max:100',
            // Accepted for backwards compatibility; superseded by the id forms.
            'department'               => 'nullable|string|max:191',
            'job_role'                 => 'nullable|string|max:191',
            'skills'                   => 'nullable|array',
            'skills.*'                 => 'string|max:191',
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
    /**
     * Turn the ids the form sends into the NAMES the model needs.
     *
     * The model cannot do anything with `department_ids: [3, 7]`. Resolving
     * here rather than sending names from the browser keeps one source of
     * truth: the client says WHICH records, the server says what they are
     * called, and a renamed department cannot leave a stale label baked into a
     * generated course.
     *
     * @return array{departments:array,jobroles:array,skills:array}
     */
    private function resolveScope(Request $request): array
    {
        $tenant = $this->tenantId($request);

        $departmentIds = array_filter((array) $request->input('department_ids', []));
        $jobroleIds = array_filter((array) $request->input('jobrole_ids', []));

        $departments = $departmentIds
            ? DB::table('hrms_departments')->whereIn('id', $departmentIds)
                ->where('sub_institute_id', $tenant)->whereNull('deleted_at')
                ->pluck('department')->all()
            : array_filter([$request->input('department')]);

        $jobroles = $jobroleIds
            ? DB::table('s_user_jobrole')->whereIn('id', $jobroleIds)
                ->where('sub_institute_id', $tenant)->whereNull('deleted_at')
                ->pluck('jobrole')->all()
            : array_filter([$request->input('job_role')]);

        /*
         * What the course should develop, from whichever scope the author used.
         *
         * A competency and a KASBA item are different grains of the same thing,
         * so both arrive here as plain capability statements - which is all the
         * model needs, and keeps the prompt one shape rather than two.
         */
        $mode = $request->input('scope_mode', 'competency');

        if ($mode === 'kasba') {
            $skills = DB::table('competency_kasba_item')
                ->whereIn('id', array_filter((array) $request->input('kasba_item_ids', [])))
                ->where('sub_institute_id', $tenant)
                ->get(['kasba_type', 'item_label'])
                // The type matters to the model: "knowledge of X" and "ability
                // to do X" are different learning outcomes.
                ->map(fn ($i) => ucfirst($i->kasba_type) . ': ' . $i->item_label)
                ->all();
        } else {
            $skills = DB::table('competency')
                ->whereIn('id', array_filter((array) $request->input('competency_ids', [])))
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->all();
        }

        if (!$skills) {
            $skills = array_filter((array) $request->input('skills', []));
        }

        return [
            'departments' => array_values($departments),
            'jobroles' => array_values($jobroles),
            'skills' => array_values($skills),
        ];
    }

    /**
     * Turn a generated outline into modules and lessons a learner can open.
     *
     * ── WHAT THE OUTLINE ACTUALLY IS ────────────────────────────────────────
     *
     * `ai_course_outlines.outline` holds the model's JSON: a title, a summary,
     * learning objectives, and a `slides` array of
     * {slide_number, title, bullets, speaker_notes}. Older rows hold a single
     * plain-text blob of "Slide 1: ...\nSlide 2: ..." instead, so both shapes
     * are read - a course built from a January outline should not fail because
     * the storage format changed.
     *
     * ── ONE MODULE, NOT ONE PER SLIDE ───────────────────────────────────────
     *
     * A slide is not a module. Ten slides become ONE module with ten lessons,
     * which is what the player's chapter/lesson tree expects and what a learner
     * would recognise as a course. The Gamma deck, when there is one, is added
     * as a further lesson so the generated presentation is the thing they
     * actually read.
     */
    private function buildCourseFromOutline($outline, int $courseId, $subInstituteId, ?int $actor): void
    {
        $slides = $this->outlineSlides($outline);
        $now = now();

        $chapterId = DB::table('chapter_master')->insertGetId([
            'subject_id' => $courseId,
            'chapter_name' => 'Course content',
            'chapter_desc' => 'Generated from the AI course outline.',
            'sort_order' => 1,
            'sub_institute_id' => $subInstituteId,
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sort = 0;

        /*
         * THE DECK FIRST.
         *
         * If Gamma rendered a presentation it is the primary material, and a
         * learner opening the course should meet it before the slide notes.
         * `pptx` is the file_type the player maps to the Office viewer, so this
         * renders in place rather than downloading.
         */
        $deckUrl = $outline->export_url ?: $outline->gamma_url;

        if ($deckUrl) {
            DB::table('content_master')->insert([
                'subject_id' => $courseId,
                'chapter_id' => $chapterId,
                'title' => 'Course presentation',
                'description' => 'The generated slide deck.',
                'file_type' => 'pptx',
                'url' => $deckUrl,
                'content_category' => 'Presentation',
                'sort_order' => ++$sort,
                'sub_institute_id' => $subInstituteId,
                'created_by' => $actor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($slides as $slide) {
            $bullets = array_filter((array) ($slide['bullets'] ?? []));

            DB::table('content_master')->insert([
                'subject_id' => $courseId,
                'chapter_id' => $chapterId,
                'title' => $slide['title'] ?? ('Slide ' . ($sort + 1)),
                // The bullets ARE the lesson when there is no deck, so they are
                // kept rather than discarded as prompt residue.
                'description' => implode("\n", $bullets)
                    . (!empty($slide['speaker_notes']) ? "\n\n" . $slide['speaker_notes'] : ''),
                'file_type' => 'link',
                'url' => $deckUrl,
                'content_category' => 'Reading',
                'sort_order' => ++$sort,
                'sub_institute_id' => $subInstituteId,
                'created_by' => $actor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * The outline's slides, whichever shape it was stored in.
     *
     * @return array<int,array<string,mixed>>
     */
    private function outlineSlides($outline): array
    {
        $decoded = json_decode((string) $outline->outline, true);

        if (is_array($decoded) && isset($decoded['slides']) && is_array($decoded['slides'])) {
            return $decoded['slides'];
        }

        /*
         * Older rows are a single plain-text blob, not JSON objects - 61 of
         * them on live. Parsed rather than skipped so an outline generated
         * before the format settled can still become a course.
         */
        $text = is_array($decoded) ? implode("\n", array_map('strval', $decoded)) : (string) $outline->outline;

        $slides = [];

        foreach (preg_split('/\n(?=Slide\s*\d+\s*:)/i', $text) as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = preg_split('/\r?\n/', $block);
            $title = preg_replace('/^Slide\s*\d+\s*:\s*/i', '', trim(array_shift($lines) ?? ''));

            $slides[] = [
                'title' => $title !== '' ? $title : 'Slide ' . (count($slides) + 1),
                'bullets' => array_values(array_filter(array_map(
                    fn ($line) => ltrim(trim($line), "-\u{2022} \t"),
                    $lines
                ))),
            ];
        }

        return $slides;
    }

    /**
     * Record what the course was generated FOR.
     *
     * The AI form now collects departments, job roles and competencies, and
     * sub_std_map can hold exactly one department and one free-text job role.
     * The rest would be thrown away at the moment of publication - so the
     * multi-value scope goes where multi-value scope already lives:
     * lms_course_settings for the audience, course_competency_map for what the
     * course develops.
     *
     * That last one matters beyond record-keeping: it is the table the quiz
     * reads to decide which competency a passing learner's rating moves.
     */
    private function saveGeneratedScope(Request $request, int $courseId, $subInstituteId, $outline): void
    {
        $departmentIds = array_values(array_filter((array) $request->input('department_ids', [])));
        $competencyIds = array_values(array_filter((array) $request->input('competency_ids', [])));

        $decoded = json_decode((string) $outline->outline, true);
        $summary = is_array($decoded) ? ($decoded['summary'] ?? null) : null;

        DB::table('lms_course_settings')->insert([
            'course_id' => $courseId,
            'sub_institute_id' => $subInstituteId,
            'sequential_unlock' => 0,
            'description' => $summary,
            'language' => 'English',
            'is_mandatory' => 0,
            'discussion_enabled' => 0,
            // Restricted only when the author actually named departments;
            // otherwise the course is open, which is the sane default.
            'visibility' => $departmentIds ? 'restricted' : 'all',
            'restrict_departments' => $departmentIds ? json_encode($departmentIds) : null,
            'issue_certificate' => 1,
            'recert_alerts' => 0,
            'enrollment_rule' => 'open',
            'created_by' => $this->contextUserId($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($competencyIds as $competencyId) {
            DB::table('course_competency_map')->insert([
                'sub_institute_id' => $subInstituteId,
                'course_id' => $courseId,
                'competency_id' => (int) $competencyId,
                // A target the author can revise; the quiz measures what
                // learners actually reach separately.
                'proficiency_level' => 3,
                'is_primary' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function buildOutlinePrompt(Request $request, int $slideCount): string
    {
        $scope = $this->resolveScope($request);

        $tasks = array_filter((array) $request->input('tasks', []));
        $skills = $scope['skills'];

        $prompt = [
            'instruction' => "You are an expert instructional designer and L&D specialist. "
                . "Create a structured {$slideCount}-slide course based on the provided context.",
            'input_variables' => [
                'industry' => $request->input('industry') ?: '-',
                // Plural now: a course serves several departments and roles,
                // and naming one while dropping the rest produced a course that
                // read as though it were for somebody else.
                'departments' => $scope['departments'] ?: ['-'],
                'job_roles' => $scope['jobroles'] ?: ['-'],
                'critical_work_function' => $request->input('critical_work_function') ?: '-',
                'key_tasks' => !empty($tasks) ? array_values($tasks) : ['-'],
                'capabilities_to_develop' => !empty($skills) ? array_values($skills) : ['-'],
                'proficiency' => $request->input('proficiency') ?: '-',
                'preferred_title' => $request->input('course_title') ?: null,
            ],
            'output_format' => [
                'total_slides' => $slideCount,
                'bullet_points_per_slide' => '3-5 (under 40 words each)',
                'style' => 'Formal, structured, competency-based',
                // Was branched on the modality checkboxes. These courses are
                // taken by an employee at their desk, so the learner-led tone
                // is simply correct rather than a setting.
                'tone' => 'Direct, learner-led tone',
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
                'created_by' => $this->contextUserId($request),
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
                    'updated_by' => $this->contextUserId($request),
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
                'department_ids'   => 'nullable|array',
                'department_ids.*' => 'integer',
                'jobrole_ids'      => 'nullable|array',
                'jobrole_ids.*'    => 'integer',
                'competency_ids'   => 'nullable|array',
                'competency_ids.*' => 'integer',
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

        /*
         * ── PUBLISHING BUILDS A COURSE, NOT AN EMPTY SHELL ──────────────────
         *
         * This used to insert the sub_std_map row and stop. No chapter_master,
         * no content_master, and the Gamma deck - the entire point of the
         * feature - was returned in the response and attached to nothing.
         *
         * Measured on live before this change:
         *
         *   ai_course_outlines            61
         *   published (course_id set)      0
         *   chapters created by AI         0
         *   lessons created by AI          0
         *
         * Not one of 61 generations ever became a course a learner could open.
         * Even the ones that "succeeded" would have produced a catalogue entry
         * with nothing inside it.
         *
         * Now, in one transaction: the course, a module per outline section, a
         * lesson per slide, and - when Gamma rendered one - the deck itself as
         * a pptx lesson the player already knows how to display through the
         * Office viewer.
         */
        try {
            $actor = $this->contextUserId($request);

            $courseId = DB::transaction(function () use ($request, $outline, $subInstituteId, $actor) {
                $courseId = sub_std_mapModel::insertGetId([
                    'display_name' => $request->display_name,
                    'standard_id' => $request->standard_id,
                    'subject_category' => $request->subject_category,
                    /*
                     * 'E-learning Module' was the hardcoded default here, and
                     * it is one of the two invalid course types the catalogue
                     * ended up offering. The client now sends a real type; this
                     * fallback is a generic one that fits any industry.
                     */
                    'subject_type' => $request->input('subject_type', 'Self-paced course'),
                    'jobrole' => $request->jobrole,
                    'sort_order' => 1,
                    'status' => $request->input('status', 1),
                    'sub_institute_id' => $subInstituteId,
                    'allow_grades' => 'Yes',
                    'allow_content' => 'Yes',
                    'elective_subject' => 'No',
                    'add_content' => 'chapterwise',
                    'created_by' => $actor,
                    'created_at' => now(),
                ]);

                $this->buildCourseFromOutline($outline, $courseId, $subInstituteId, $actor);
                $this->saveGeneratedScope($request, $courseId, $subInstituteId, $outline);

                $outline->update([
                    'course_id' => $courseId,
                    'updated_by' => $actor,
                ]);

                return $courseId;
            });

            $chapters = DB::table('chapter_master')->where('subject_id', $courseId)->count();
            $lessons = DB::table('content_master')->where('subject_id', $courseId)->count();

            return response()->json([
                'status' => true,
                // Says what was built. "Published" on its own was true of a
                // course with nothing in it, which is how this went unnoticed.
                'message' => "Course published with {$chapters} module(s) and {$lessons} lesson(s)",
                'data' => [
                    'course_id' => $courseId,
                    'outline_id' => $outline->id,
                    'gamma_url' => $outline->gamma_url,
                    'export_url' => $outline->export_url,
                    'chapters' => $chapters,
                    'lessons' => $lessons,
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
