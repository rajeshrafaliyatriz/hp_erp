<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Evaluation\EvaluationRunner;
use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Templates\TemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * AI Evaluation — test sets and scores for a template.
 *
 * ── THERE IS NO LMS EQUIVALENT OF THIS ─────────────────────────────────────
 *
 * Every other capability here was adapted from working LMS K-12 code. This one was
 * not, because LMS has not built it — its own registry entry reads "Not built. Prompt
 * and model changes ship without a measured before and after." So "make it work the
 * same way as LMS" cannot be satisfied, and the alternative to writing it is shipping
 * nothing. It follows the same architecture as the rest: token-scoped, resolved
 * through AiConfigurationResolver, audited.
 *
 * WHAT AN EVALUATION IS HERE
 *
 * A named set of cases against one template. Each case supplies the variables the
 * template needs and declares what a correct answer must contain and must not
 * contain. Running it renders the template per case, calls the configured provider,
 * and scores by assertion. See `EvaluationRunner` for why the scoring is assertions
 * rather than a second model grading the first.
 *
 * RUNNING IS SYNCHRONOUS AND BOUNDED
 *
 * One provider call per case, in sequence, holding the request open. `MAX_CASES`
 * bounds a run because there is no queue worker behind this — and a run that quietly
 * required one would appear to hang. The ceiling is reported in `options()` so a
 * screen can say so before somebody writes the twenty-sixth case.
 */
class EvaluationController extends AiController
{
    public function __construct(
        private readonly EvaluationRunner $runner,
        private readonly TemplateCatalog $templates,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /** What the screen needs to offer a new evaluation. */
    public function options(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            // Only published templates. Evaluating a draft measures something nobody
            // can run yet, and the point of a score is to gate what ships.
            $templates = array_values(array_filter(
                $this->templates->forModule(null, $institute),
                fn ($template) => $template['status'] === 'published'
            ));

            return $this->success('Evaluation options resolved.', [
                'templates' => array_map(fn ($template) => [
                    'template_key' => $template['template_key'],
                    'name' => $template['name'],
                    'version' => $template['version'],
                    'module_key' => $template['module_key'],
                    'module_label' => $template['module_label'],
                    // So a case author knows which placeholders they must supply.
                    'grounding_variables' => $template['grounding_variables'],
                ], $templates),
                'max_cases' => EvaluationRunner::MAX_CASES,
                'statuses' => ['draft', 'running', 'completed', 'failed'],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** This organisation's evaluations, newest first. */
    public function index(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $rows = DB::table('ai_evaluations')
                ->where('sub_institute_id', $institute)
                ->orderByDesc('id')
                ->limit($this->limit($request, 50, 200))
                ->get();

            return $this->success('Evaluations resolved.', [
                'sub_institute_id' => $institute,
                'evaluations' => $rows->map(fn ($row) => $this->present($row))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** One evaluation with every case and its result. */
    public function show(Request $request, int $evaluation)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;

            $row = $this->owned($evaluation, $institute);

            if ($row === null) {
                return $this->failure('That evaluation was not found.', 404);
            }

            $cases = DB::table('ai_evaluation_cases')
                ->where('evaluation_id', $evaluation)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return $this->success('Evaluation resolved.', [
                'evaluation' => $this->present($row),
                'cases' => $cases->map(fn ($case) => [
                    'id' => (int) $case->id,
                    'label' => (string) $case->label,
                    'variables' => $this->decode($case->variables),
                    'expect_contains' => $this->decode($case->expect_contains),
                    'expect_absent' => $this->decode($case->expect_absent),
                    'output' => $case->output === null ? null : (string) $case->output,
                    'score' => $case->score === null ? null : (float) $case->score,
                    'passed' => $case->passed === null ? null : (bool) $case->passed,
                    'verdict' => $case->verdict === null ? null : (string) $case->verdict,
                    'input_tokens' => $case->input_tokens === null ? null : (int) $case->input_tokens,
                    'output_tokens' => $case->output_tokens === null ? null : (int) $case->output_tokens,
                    'latency_ms' => $case->latency_ms === null ? null : (int) $case->latency_ms,
                    'error' => $case->error === null ? null : (string) $case->error,
                ])->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Create an evaluation and its cases in one call.
     *
     * One call rather than two because an evaluation with no cases cannot be run, so
     * creating one and then adding cases separately leaves a row that is valid in the
     * table and useless on the screen.
     */
    public function store(Request $request)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $validated = $request->validate([
                'name' => 'required|string|max:200',
                'description' => 'nullable|string|max:2000',
                'template_key' => 'required|string|max:120',
                'template_version' => 'nullable|integer|min:1',
                'cases' => 'required|array|min:1|max:' . EvaluationRunner::MAX_CASES,
                'cases.*.label' => 'required|string|max:200',
                'cases.*.variables' => 'nullable|array',
                'cases.*.expect_contains' => 'nullable|array',
                'cases.*.expect_contains.*' => 'string|max:500',
                'cases.*.expect_absent' => 'nullable|array',
                'cases.*.expect_absent.*' => 'string|max:500',
            ]);

            // The template must be one this organisation can actually see. Without
            // this check an evaluation could name another organisation's template and
            // the runner would resolve nothing at run time, reporting the failure as
            // the template's fault rather than the request's.
            $template = $this->visibleTemplate($validated['template_key'], $institute);

            if ($template === null) {
                return $this->failure('That template could not be found.', 404);
            }

            $id = DB::table('ai_evaluations')->insertGetId([
                'name' => trim($validated['name']),
                'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
                'template_key' => $validated['template_key'],
                'template_version' => $validated['template_version'] ?? null,
                'module_key' => $template['module_key'] === '__shared__' ? null : $template['module_key'],
                'status' => 'draft',
                'case_count' => count($validated['cases']),
                'created_by' => $scope->userId,
                'sub_institute_id' => $institute,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_values($validated['cases']) as $index => $case) {
                DB::table('ai_evaluation_cases')->insert([
                    'evaluation_id' => $id,
                    'label' => trim($case['label']),
                    'variables' => $this->encode($case['variables'] ?? []),
                    'expect_contains' => $this->encode($case['expect_contains'] ?? []),
                    'expect_absent' => $this->encode($case['expect_absent'] ?? []),
                    'sort_order' => $index,
                    'sub_institute_id' => $institute,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record('ai.evaluation.created', $scope, [
                'related_type' => 'ai_evaluations',
                'related_id' => $id,
                'message' => sprintf(
                    'Evaluation "%s" created with %d cases against %s.',
                    trim($validated['name']),
                    count($validated['cases']),
                    $validated['template_key']
                ),
            ]);

            return $this->success('Evaluation saved.', [
                'evaluation' => $this->present($this->owned((int) $id, $institute)),
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Run it.
     *
     * Every case is a provider call, so this is the one endpoint here that costs
     * money. It is a POST and it is not idempotent by design: re-running an
     * evaluation after changing a template is the entire workflow, and a guard
     * against a second run would block it.
     */
    public function run(Request $request, int $evaluation)
    {
        try {
            $scope = $this->scope($request);

            if ($this->owned($evaluation, $scope->selectedInstituteId) === null) {
                return $this->failure('That evaluation was not found.', 404);
            }

            $result = $this->runner->run($evaluation, $scope);

            return $this->success(
                ($result['status'] ?? '') === 'completed'
                    ? 'Evaluation complete.'
                    : 'Evaluation finished with failures.',
                ['evaluation' => $this->present((object) $result)]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function destroy(Request $request, int $evaluation)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $row = $this->owned($evaluation, $institute);

            if ($row === null) {
                return $this->failure('That evaluation was not found.', 404);
            }

            // A hard delete, unlike a template or a policy. An evaluation is a
            // measurement of something else, not a record anything else points at, so
            // there is nothing for a retired row to keep readable — and a list of
            // scores cluttered with runs somebody abandoned is worse than a short one.
            DB::table('ai_evaluation_cases')->where('evaluation_id', $evaluation)->delete();
            DB::table('ai_evaluations')->where('id', $evaluation)->delete();

            $this->audit->record('ai.evaluation.deleted', $scope, [
                'related_type' => 'ai_evaluations',
                'related_id' => $evaluation,
                'message' => sprintf('Evaluation "%s" deleted.', $row->name),
            ]);

            return $this->success('Evaluation deleted.', ['id' => $evaluation]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function owned(int $id, int|string|null $institute): ?object
    {
        return DB::table('ai_evaluations')
            ->where('id', $id)
            ->where('sub_institute_id', $institute)
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function visibleTemplate(string $key, int|string|null $institute): ?array
    {
        foreach ($this->templates->forModule(null, $institute) as $template) {
            if ($template['template_key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(?object $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'description' => $row->description === null ? null : (string) $row->description,
            'template_key' => $row->template_key === null ? null : (string) $row->template_key,
            'template_version' => $row->template_version === null ? null : (int) $row->template_version,
            'module_key' => $row->module_key === null ? null : (string) $row->module_key,
            'provider' => $row->provider === null ? null : (string) $row->provider,
            'model' => $row->model === null ? null : (string) $row->model,
            'status' => (string) $row->status,
            'case_count' => (int) $row->case_count,
            'passed_count' => (int) $row->passed_count,
            'failed_count' => (int) $row->failed_count,
            'score' => $row->score === null ? null : (float) $row->score,
            'total_input_tokens' => (int) $row->total_input_tokens,
            'total_output_tokens' => (int) $row->total_output_tokens,
            'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'error' => $row->error === null ? null : (string) $row->error,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'created_at' => $row->created_at === null ? null : (string) $row->created_at,
        ];
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
