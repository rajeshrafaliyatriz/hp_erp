<?php

namespace App\Domain\AI\Evaluation;

use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Templates\TemplateCatalog;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs an evaluation: a template, a set of cases, and a score.
 *
 * ── THIS ONE IS NOT A PORT ─────────────────────────────────────────────────
 *
 * The other capabilities were adapted from working LMS K-12 code. AI Evaluation is
 * not built in LMS — its own registry entry says so: "Not built. Prompt and model
 * changes ship without a measured before and after." So there is nothing to copy,
 * and "the same as LMS" would mean shipping nothing. This is new work, written to
 * the same architecture as the rest: resolves its provider through
 * `AiConfigurationResolver`, scopes on the caller's token, audits what it did.
 *
 * ── WHY SCORING IS ASSERTIONS AND NOT A MODEL JUDGING A MODEL ──────────────
 *
 * The obvious design is to ask a model whether the answer was good. It is also
 * circular: a regression in the model under test shows up in the judge as well, and
 * the score moves for reasons nobody can trace. Worse, it makes every run cost twice
 * and produces a number that cannot be reproduced.
 *
 * So a case declares what its answer must contain and must not contain, and scoring
 * is substring assertion — deterministic, free, reproducible, and explainable in one
 * sentence. It cannot judge elegance. It can catch the failures that actually matter
 * for a grounded template: a number that should have appeared and did not, a name or
 * a fabrication that should never appear and did.
 *
 * `expect_absent` is the more valuable half in practice. "Must not contain 'Priya'"
 * is how you catch a template leaking personal data; "must not contain '%'" is how
 * you catch one inventing statistics.
 *
 * ── RUNS SYNCHRONOUSLY, AND SAYS SO ────────────────────────────────────────
 *
 * Each case is one provider call, in sequence. A twenty-case run against a slow
 * provider will take a while and holds the request open. That is a deliberate limit,
 * not an oversight: G2G has no queue worker configured for this, and a run that
 * silently needed one would appear to hang forever. `MAX_CASES` bounds it, and the
 * controller documents the ceiling.
 */
final class EvaluationRunner
{
    /**
     * The AI module an evaluation bills to.
     *
     * Its own module, not the one under test. An evaluation of the assessment
     * template must not consume the assessment module's quota, or measuring a
     * template would degrade the thing it measures.
     */
    private const MODULE = 'analytics_ai';

    /** Cases per run. Bounded because a run is synchronous — see the class note. */
    public const MAX_CASES = 25;

    public function __construct(
        private readonly TemplateCatalog $templates,
        private readonly AiModelClient $models,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /**
     * Run every case in an evaluation and record the outcome.
     *
     * @return array<string, mixed> The finished evaluation.
     */
    public function run(int $evaluationId, AiRequestScope $scope): array
    {
        $institute = $scope->selectedInstituteId;

        $evaluation = DB::table('ai_evaluations')
            ->where('id', $evaluationId)
            ->where('sub_institute_id', $institute)
            ->first();

        if ($evaluation === null) {
            throw new RuntimeException('That evaluation was not found.');
        }

        if ($evaluation->status === 'running') {
            throw new RuntimeException('This evaluation is already running.');
        }

        $template = $this->resolveTemplate($evaluation, $institute);

        $cases = DB::table('ai_evaluation_cases')
            ->where('evaluation_id', $evaluationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::MAX_CASES)
            ->get();

        if ($cases->isEmpty()) {
            throw new RuntimeException('This evaluation has no cases. Add at least one before running it.');
        }

        $this->markRunning($evaluationId);
        $started = microtime(true);

        $passed = 0;
        $scores = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $provider = null;
        $model = null;
        $fatal = null;

        foreach ($cases as $case) {
            $outcome = $this->runCase($case, $template, $institute);

            DB::table('ai_evaluation_cases')->where('id', $case->id)->update([
                'output' => $outcome['output'],
                'score' => $outcome['score'],
                'passed' => $outcome['passed'],
                'verdict' => $outcome['verdict'],
                'input_tokens' => $outcome['input_tokens'],
                'output_tokens' => $outcome['output_tokens'],
                'latency_ms' => $outcome['latency_ms'],
                'error' => $outcome['error'],
                'updated_at' => now(),
            ]);

            if ($outcome['passed']) {
                $passed++;
            }

            if ($outcome['score'] !== null) {
                $scores[] = $outcome['score'];
            }

            $inputTokens += (int) $outcome['input_tokens'];
            $outputTokens += (int) $outcome['output_tokens'];
            $provider ??= $outcome['provider'];
            $model ??= $outcome['model'];

            // A missing credential fails every remaining case for the same reason, so
            // the run stops rather than making twenty-four more doomed calls and
            // reporting a score of zero as though the template were at fault.
            if ($outcome['not_configured']) {
                $fatal = $outcome['error'];
                break;
            }
        }

        $total = $cases->count();
        $finished = [
            'status' => $fatal === null ? 'completed' : 'failed',
            'case_count' => $total,
            'passed_count' => $passed,
            'failed_count' => $total - $passed,
            'score' => $scores === [] ? null : round(array_sum($scores) / count($scores), 4),
            'total_input_tokens' => $inputTokens,
            'total_output_tokens' => $outputTokens,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'provider' => $provider,
            'model' => $model,
            'error' => $fatal,
            'finished_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('ai_evaluations')->where('id', $evaluationId)->update($finished);

        $this->audit->record('ai.evaluation.run', $scope, [
            'related_type' => 'ai_evaluations',
            'related_id' => $evaluationId,
            'outcome' => $fatal === null ? 'success' : 'failure',
            'message' => sprintf(
                'Evaluation "%s": %d of %d cases passed%s.',
                $evaluation->name,
                $passed,
                $total,
                $finished['score'] === null ? '' : sprintf(', score %.2f', $finished['score'])
            ),
            'payload' => [
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'duration_ms' => $finished['duration_ms'],
            ],
        ]);

        // Re-read rather than returning `$finished`: the row is what the caller will
        // render, and returning the update payload would omit every column the run
        // did not touch.
        $row = DB::table('ai_evaluations')->where('id', $evaluationId)->first();

        return $row === null ? [] : (array) $row;
    }

    /**
     * One case: render the template, call the model, score what came back.
     *
     * @return array<string, mixed>
     */
    private function runCase(object $case, array $template, int|string $institute): array
    {
        $variables = $this->decode($case->variables);

        $rendered = $this->templates->preview(
            (string) ($template['system_prompt'] ?? ''),
            (string) ($template['user_prompt'] ?? ''),
            $variables
        );

        $messages = [];

        if ($rendered['system'] !== null && trim($rendered['system']) !== '') {
            $messages[] = ['role' => 'system', 'content' => $rendered['system']];
        }

        $messages[] = ['role' => 'user', 'content' => $rendered['user']];

        try {
            $completion = $this->models->complete(
                self::MODULE,
                $messages,
                // Zero temperature here, unlike the assistant. An evaluation that
                // returns a different score for the same input is not a measurement.
                ['temperature' => 0.0, 'max_tokens' => (int) ($template['max_tokens'] ?? 1024)],
                $institute
            );
        } catch (AiNotConfiguredException $exception) {
            return $this->caseFailure($exception->getMessage(), notConfigured: true);
        } catch (Throwable $exception) {
            return $this->caseFailure($exception->getMessage(), notConfigured: false);
        }

        $verdict = $this->score(
            $completion->text,
            $this->decode($case->expect_contains),
            $this->decode($case->expect_absent)
        );

        return [
            'output' => $completion->text,
            'score' => $verdict['score'],
            'passed' => $verdict['passed'],
            'verdict' => $verdict['verdict'],
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'latency_ms' => $completion->latencyMs,
            'error' => null,
            'provider' => $completion->provider,
            'model' => $completion->model,
            'not_configured' => false,
        ];
    }

    /**
     * Score one output against its expectations.
     *
     * Every assertion carries equal weight and the score is the fraction satisfied. A
     * case with no assertions scores 1.0 and says so — it ran, it produced output,
     * and nobody said what would make that output wrong. Scoring it 0 would punish an
     * author for not having written assertions yet; scoring it 1.0 silently would
     * hide that the case measures nothing, which is why the verdict names it.
     *
     * Matching is case-insensitive substring. A stricter rule (regex, whole word)
     * fails on correct output far more often than it catches a real fault.
     *
     * @param  array<int, mixed>  $mustContain
     * @param  array<int, mixed>  $mustNotContain
     * @return array{score: float, passed: bool, verdict: string}
     */
    private function score(string $output, array $mustContain, array $mustNotContain): array
    {
        $haystack = mb_strtolower($output);

        $checks = 0;
        $met = 0;
        $failures = [];

        foreach ($mustContain as $needle) {
            $needle = trim((string) $needle);

            if ($needle === '') {
                continue;
            }

            $checks++;

            if (str_contains($haystack, mb_strtolower($needle))) {
                $met++;
            } else {
                $failures[] = "missing \"{$needle}\"";
            }
        }

        foreach ($mustNotContain as $needle) {
            $needle = trim((string) $needle);

            if ($needle === '') {
                continue;
            }

            $checks++;

            if (str_contains($haystack, mb_strtolower($needle))) {
                $failures[] = "contains \"{$needle}\", which it must not";
            } else {
                $met++;
            }
        }

        if ($checks === 0) {
            return [
                'score' => 1.0,
                'passed' => true,
                'verdict' => 'No assertions on this case, so nothing was checked. '
                    . 'Add expected or forbidden phrases to make it measure something.',
            ];
        }

        $score = round($met / $checks, 4);

        return [
            'score' => $score,
            // All assertions, not most. A template that satisfies three of four
            // expectations has failed the fourth, and a passing grade for that is how
            // a regression ships.
            'passed' => $met === $checks,
            'verdict' => $failures === []
                ? sprintf('All %d assertions met.', $checks)
                : sprintf('%d of %d met. Failed: %s.', $met, $checks, implode('; ', $failures)),
        ];
    }

    /** @return array<string, mixed> */
    private function caseFailure(string $message, bool $notConfigured): array
    {
        return [
            'output' => null,
            // Null, not zero. Zero is a measured result; this case was never measured,
            // and averaging a zero in would report the template as worse than unknown.
            'score' => null,
            'passed' => false,
            'verdict' => 'Not scored — the call failed.',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => 0,
            'error' => $message,
            'provider' => null,
            'model' => null,
            'not_configured' => $notConfigured,
        ];
    }

    /**
     * The template under test, pinned to the version the evaluation names.
     *
     * @return array<string, mixed>
     */
    private function resolveTemplate(object $evaluation, int|string $institute): array
    {
        $key = trim((string) ($evaluation->template_key ?? ''));

        if ($key === '') {
            throw new RuntimeException('This evaluation names no template to test.');
        }

        $row = DB::table('ai_templates')
            ->where('template_key', $key)
            ->where(fn ($q) => $q->whereNull('sub_institute_id')->orWhere('sub_institute_id', $institute))
            ->when(
                $evaluation->template_version !== null,
                fn ($q) => $q->where('version', $evaluation->template_version)
            )
            // The organisation's own copy beats the platform baseline, and the newest
            // version wins when no version is pinned — the same precedence
            // TemplateCatalog uses, so an evaluation tests what the runtime would run.
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            throw new RuntimeException(sprintf('Template "%s" could not be found.', $key));
        }

        return [
            'system_prompt' => $row->system_prompt,
            'user_prompt' => $row->user_prompt,
            'max_tokens' => $row->max_tokens,
            'version' => (int) $row->version,
        ];
    }

    private function markRunning(int $evaluationId): void
    {
        DB::table('ai_evaluations')->where('id', $evaluationId)->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
            'updated_at' => now(),
        ]);
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
