<?php

namespace App\Services\Competency;

use App\Exceptions\DeepSeekBudgetException;
use App\Exceptions\DeepSeekTruncatedException;
use App\Services\DeepSeekService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deciding HOW a job role task is executed — human, hybrid, agent or software.
 *
 * ── WHAT THIS PRODUCES ──────────────────────────────────────────────────────
 *
 * For each task: a current and a target execution mode, four dimension scores,
 * a composite executability score, a risk class, and a written reason. Nothing
 * it writes is ever `Approved` - a model's opinion about whether a person is
 * still needed for a piece of work is a proposal, and a person confirms it.
 *
 * ── THE RISK CEILING IS CODE, NOT A PROMPT ──────────────────────────────────
 *
 * A model asked nicely not to recommend autonomy on a regulated task will
 * comply most of the time. Most of the time is not a control. `clamp()` applies
 * the ceiling after the model has spoken, and records in the rationale that it
 * did - so a reviewer sees the model's view AND the rule that overrode it.
 *
 * ── DEDUPLICATION, AND WHERE THE DUPLICATES ACTUALLY ARE ────────────────────
 *
 * Tenant 6 is 3,572 task rows across 150 roles but only 2,984 distinct texts -
 * 16% repeat. The obvious design, dedup the role being classified, saves
 * NOTHING: measured on both databases, within-role distinct = 3,572 = every row.
 * Every duplicate is ACROSS roles, because roles draw from a shared catalogue.
 *
 * So dedup here has two halves, and the second is the one that pays:
 *   1. within this role      - near-zero saving, kept because it costs nothing
 *   2. against the TENANT    - any text already classified in ANY role is
 *                              reused without a model call
 *
 * That makes the saving grow as roles are classified: role 1 pays full price,
 * role 150 pays for almost nothing. Reused rows are still written 'AI-proposed'
 * - approval was granted for a task in one role's context, and copying that
 * approval into another role would be approving something nobody looked at.
 */
class TaskExecutionClassifier
{
    /**
     * The six modes.
     *
     * `system_automated` is deliberately NOT an AI mode. Deterministic workflow
     * is not machine learning, and a product that conflates them loses the
     * first technical buyer who notices.
     */
    public const MODES = [
        'human_only'      => 'Judgment or accountability requires a person; AI must not execute',
        'human_ai_assist' => 'Human performs; AI supplies analysis, drafts or recommendations',
        'ai_human_review' => 'AI produces the work; a human approves before it takes effect',
        'ai_supervised'   => 'Agent executes within controls; human handles exceptions only',
        'ai_autonomous'   => 'Agent executes end to end, with monitoring and audit',
        'system_automated'=> 'Deterministic software or workflow - not AI',
    ];

    public const RISK_CLASSES = ['Low', 'Medium', 'High', 'Regulated'];

    /** Appended once to a reused rationale, and matched to avoid appending twice. */
    private const REUSE_NOTE = '[Reused: this task text was already classified elsewhere in this organisation.]';

    /**
     * How far up the automation ladder each risk class may go.
     *
     * These are a POLICY, not a measurement, so they are written down here
     * rather than buried in a condition, and the API returns them so a reviewer
     * can see the rule that produced a clamp.
     */
    public const RISK_CEILING = [
        'Regulated' => 'ai_human_review',
        'High'      => 'ai_supervised',
        'Medium'    => 'ai_autonomous',
        'Low'       => 'ai_autonomous',
    ];

    /** Ladder order, least to most autonomous. `system_automated` sits outside it. */
    private const LADDER = [
        'human_only', 'human_ai_assist', 'ai_human_review', 'ai_supervised', 'ai_autonomous',
    ];

    /**
     * Dimension weights for the composite score.
     *
     * judgment_required and error_consequence are INVERSE - a task needing lots
     * of judgement, or costly to get wrong, is less executable by a machine -
     * so they are subtracted from 100 before weighting.
     */
    public const WEIGHTS = [
        'digital_input'     => 0.25,
        'rule_clarity'      => 0.30,
        'judgment_required' => 0.25,
        'error_consequence' => 0.20,
    ];

    public function __construct(private readonly DeepSeekService $ai)
    {
    }

    /**
     * Classify one job role's tasks.
     *
     * ONE ROLE AT A TIME, and one model call for the whole role. It matches the
     * shape AiAssessmentController::generate already uses. There is no queue
     * worker in this deployment (one job class, nothing runs queue:work), so a
     * background pass would mean owning a worker process; per-role synchronous
     * does not.
     *
     * MEASURED on tenant 6, 2026-08-27 (an earlier version of this docblock said
     * "median 19, p90 31, max 209" - the 209 does not exist on either database):
     *
     *   150 roles · median 23 tasks · p90 35 · max 55
     *   only 4 roles exceed 40 tasks
     *
     * At ~84 output tokens per answer, every one of those fits a single sized
     * call. If a future tenant has a role big enough not to, the call raises
     * DeepSeekTruncatedException rather than silently losing the work.
     *
     * @return array{classified:int, rows_written:int, distinct:int, dropped:int, clamped:int, reason:?string}
     */
    public function classifyRole(int $tenantId, string $jobrole, ?int $actorId, bool $reclassify = false): array
    {
        $empty = ['classified' => 0, 'rows_written' => 0, 'distinct' => 0, 'dropped' => 0,
                  'clamped' => 0, 'reused_rows' => 0, 'reused_texts' => 0];

        if (!$this->ai->isConfigured()) {
            return $empty + ['reason' => 'not_configured'];
        }

        $tasks = DB::table('s_user_jobrole_task')
            ->where('sub_institute_id', $tenantId)
            ->whereRaw('TRIM(LOWER(jobrole)) = ?', [mb_strtolower(trim($jobrole))])
            ->whereNull('deleted_at')
            ->whereNotNull('task')->where('task', '<>', '')
            ->get(['id', 'task', 'critical_work_function', 'catalogue_task_id', 'sector']);

        if ($tasks->isEmpty()) {
            return $empty + ['reason' => 'no_tasks'];
        }

        /*
         * A HUMAN DECISION IS NOT RE-DECIDABLE BY A RE-RUN.
         *
         * Rows a person approved or overrode are held back from BOTH paths below,
         * whatever `$reclassify` says. Without this, re-running a role reset every
         * reviewed row to 'AI-proposed' - and because `reviewed_by`/`reviewed_at`/
         * `review_note` are not in the update payload, the row was left naming a
         * reviewer while claiming nobody had reviewed it. The decision was gone
         * and only the attribution survived, attached to a proposal that person
         * never saw.
         *
         * Overriding a human decision has to be a deliberate act through review(),
         * by a person, with a reason. It is not a side effect of pressing a
         * classify button twice.
         */
        $reviewed = DB::table('jobrole_task_execution')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('user_jobrole_task_id', $tasks->pluck('id'))
            ->whereIn('classification_status', ['Approved', 'Human-reviewed'])
            ->pluck('user_jobrole_task_id')->all();

        $protected = count($reviewed);
        if ($protected > 0) {
            $tasks = $tasks->reject(fn ($t) => in_array($t->id, $reviewed, true))->values();
        }

        // Already classified rows are left alone unless explicitly re-run, so a
        // second press does not spend money re-deciding settled work.
        if (!$reclassify) {
            $done = DB::table('jobrole_task_execution')
                ->where('sub_institute_id', $tenantId)
                ->whereIn('user_jobrole_task_id', $tasks->pluck('id'))
                ->pluck('user_jobrole_task_id')->all();
            $tasks = $tasks->reject(fn ($t) => in_array($t->id, $done, true))->values();
        }

        if ($tasks->isEmpty()) {
            return $empty + [
                'reason' => $protected > 0 ? 'all_reviewed' : 'already_classified',
                'protected' => $protected,
            ];
        }

        // DEDUPLICATE within the role. The model sees each distinct sentence
        // once - worth almost nothing here (measured: 0% on tenant 6) but free.
        $byText = $tasks->groupBy(fn ($t) => mb_strtolower(trim((string) $t->task)));
        $distinct = $byText->map(fn ($group) => $group->first())->values();

        // REUSE ACROSS THE TENANT. This is where the 16% actually lives.
        // `$tasks->pluck('id')` is passed so the lookup can exclude the rows it is
        // about to overwrite - see reuseExisting().
        $reused = $this->reuseExisting($tenantId, $byText, $actorId, $tasks->pluck('id')->all());
        $distinct = $distinct->reject(
            fn ($t) => isset($reused['texts'][mb_strtolower(trim((string) $t->task))])
        )->values();

        if ($distinct->isEmpty()) {
            // Every text was already known. Nothing to ask, nothing to spend.
            return [
                'classified' => 0, 'rows_written' => $reused['written'], 'distinct' => 0,
                'dropped' => 0, 'clamped' => 0, 'reused_rows' => $reused['written'],
                'reused_texts' => count($reused['texts']), 'reason' => null,
                'protected' => $protected, 'spent' => null, 'max_tokens' => 0,
            ];
        }

        /*
         * SIZE max_tokens TO THE BATCH, rather than inheriting a flat 4000.
         *
         * Measured on this account: a completed answer object averages 84 output
         * tokens. 110 per task plus 400 of headroom leaves ~30% slack for a
         * wordier rationale without leaving room for the model to ramble.
         *
         * A ceiling that fits the work is also the cheapest failure: if the model
         * overruns it, the truncation is caught below at a known, small cost
         * instead of a 4000-token one.
         */
        $budget = max(800, $distinct->count() * 110 + 400);

        try {
            $result = $this->ai->chatJson([
                ['role' => 'system', 'content' =>
                    'You classify how workplace tasks are executed. You are conservative about '
                    . 'automation: a task needing accountability, judgement or physical presence '
                    . 'stays with a person. You return only valid JSON.'],
                ['role' => 'user', 'content' => $this->prompt($distinct, $jobrole)],
            ], ['json' => true, 'temperature' => 0.2, 'max_tokens' => $budget]);
        } catch (DeepSeekBudgetException $e) {
            // Refused BEFORE sending, so nothing was charged. Distinct from a
            // failure, because the fix is topping up rather than retrying.
            Log::info('Task execution classification refused on balance', [
                'tenant' => $tenantId, 'jobrole' => $jobrole, 'error' => $e->getMessage(),
            ]);
            return $empty + ['reason' => 'insufficient_balance', 'detail' => $e->getMessage()];
        } catch (DeepSeekTruncatedException $e) {
            /*
             * Billed and unusable. This is the expensive failure, so it gets its
             * own reason and says what to do - "the role is too large for one
             * call" is actionable; "could not be parsed" is not.
             */
            Log::warning('Task execution classification truncated', [
                'tenant' => $tenantId, 'jobrole' => $jobrole,
                'tasks' => $distinct->count(), 'max_tokens' => $budget,
            ]);
            return $empty + [
                'reason' => 'truncated',
                'detail' => $e->getMessage(),
                'spent'  => $this->ai->lastUsage(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Task execution classification failed', [
                'tenant' => $tenantId, 'jobrole' => $jobrole, 'error' => $e->getMessage(),
            ]);
            // The counts survive a failure, so a wasted call is still reported
            // as a cost rather than disappearing.
            return $empty + ['reason' => 'ai_error', 'spent' => $this->ai->lastUsage()];
        }

        $spent = $this->ai->lastUsage();

        $sent = $distinct->keyBy('id');
        $written = 0; $dropped = 0; $clamped = 0; $classified = 0;

        foreach ($result['tasks'] ?? [] as $row) {
            $source = $sent->get((int) ($row['task_id'] ?? 0));
            if (!$source) {
                // A task id the model invented. Dropped and counted, never applied.
                $dropped++;
                continue;
            }

            $shaped = $this->shape($row);
            if ($shaped['clamped']) {
                $clamped++;
            }
            $classified++;

            // Fan the one answer out to every row sharing this exact text.
            $siblings = $byText->get(mb_strtolower(trim((string) $source->task))) ?? collect([$source]);

            foreach ($siblings as $task) {
                $this->upsert($tenantId, (int) $task->id, $shaped['values'] + [
                    'catalogue_task_id' => $task->catalogue_task_id,
                    'model'             => $this->ai->model(),
                    'classified_at'     => now(),
                    'classified_by'     => $actorId,
                    // NEVER 'Approved'. A person confirms.
                    'classification_status' => 'AI-proposed',
                ]);
                $written++;
            }
        }

        return [
            'classified' => $classified, 'rows_written' => $written + $reused['written'],
            'distinct' => $distinct->count(), 'dropped' => $dropped,
            'clamped' => $clamped, 'reused_rows' => $reused['written'],
            'reused_texts' => count($reused['texts']), 'reason' => null,
            // Rows a person had decided on, held back from this run.
            'protected' => $protected,
            // What it actually cost, from DeepSeek's own counts rather than an
            // estimate. Null only if the provider omitted `usage`.
            'spent' => $spent,
            'max_tokens' => $budget,
        ];
    }

    /**
     * Copy classifications this tenant already holds for the same task text.
     *
     * Roles share a catalogue, so the same sentence turns up in many of them.
     * Once a text has been classified anywhere in the tenant, asking again buys
     * nothing and costs a token budget.
     *
     * The copy is written 'AI-proposed' even when its source was 'Approved'.
     * Somebody approved that classification for a task inside ANOTHER role, and
     * inheriting their approval would put their name on a decision they were
     * never shown. The rationale records where the answer came from.
     *
     * ── IT MUST NOT REUSE A ROW IT IS ABOUT TO OVERWRITE ────────────────────────
     *
     * `$excludeTaskIds` is the set of rows this run is going to write. Without
     * excluding them, a re-run found each task's OWN row as the "existing
     * classification elsewhere", copied it onto itself, emptied the send list,
     * and returned early - so `reclassify` never called the model, produced no new
     * judgement, and reported success. The one thing it changed was resetting the
     * status. That is the failure this parameter exists to prevent.
     *
     * @param  \Illuminate\Support\Collection  $byText  task rows grouped by lowered text
     * @param  array<int>  $excludeTaskIds  rows this run will write; never a source
     * @return array{written:int, texts:array<string,bool>}
     */
    private function reuseExisting(int $tenantId, $byText, ?int $actorId, array $excludeTaskIds = []): array
    {
        $texts = $byText->keys()->all();
        if ($texts === []) {
            return ['written' => 0, 'texts' => []];
        }

        // The newest classification per distinct text, from any OTHER role's rows
        // in this tenant.
        $known = DB::table('jobrole_task_execution as e')
            ->join('s_user_jobrole_task as t', 't.id', '=', 'e.user_jobrole_task_id')
            ->where('e.sub_institute_id', $tenantId)
            ->whereIn(DB::raw('TRIM(LOWER(t.task))'), $texts)
            ->whereNotNull('e.execution_mode_target')
            ->when($excludeTaskIds !== [], fn ($q) => $q->whereNotIn('e.user_jobrole_task_id', $excludeTaskIds))
            ->orderBy('e.id')
            ->get([
                DB::raw('TRIM(LOWER(t.task)) as text'),
                'e.execution_mode_current', 'e.execution_mode_target',
                'e.digital_input', 'e.rule_clarity', 'e.judgment_required', 'e.error_consequence',
                'e.ai_executability_score', 'e.risk_class', 'e.automation_rationale',
                'e.human_effort_current_min', 'e.human_effort_target_min', 'e.model',
            ])
            ->keyBy('text');

        $written = 0;
        $matched = [];

        foreach ($known as $text => $source) {
            $rows = $byText->get($text);
            if (!$rows) {
                continue;
            }
            $matched[$text] = true;

            /*
             * The suffix is added ONCE, not once per run.
             *
             * It used to be appended unconditionally, so a text reused three times
             * carried the note three times. TEXT does not error on overflow, so
             * this grew silently rather than failing.
             */
            $rationale = trim((string) $source->automation_rationale);
            if (!str_contains($rationale, self::REUSE_NOTE)) {
                $rationale = trim($rationale . ' ' . self::REUSE_NOTE);
            }

            foreach ($rows as $task) {
                $this->upsert($tenantId, (int) $task->id, [
                    'execution_mode_current' => $source->execution_mode_current,
                    'execution_mode_target'  => $source->execution_mode_target,
                    'digital_input' => $source->digital_input,
                    'rule_clarity' => $source->rule_clarity,
                    'judgment_required' => $source->judgment_required,
                    'error_consequence' => $source->error_consequence,
                    'ai_executability_score' => $source->ai_executability_score,
                    'risk_class' => $source->risk_class,
                    'automation_rationale' => $rationale,
                    'human_effort_current_min' => $source->human_effort_current_min,
                    'human_effort_target_min' => $source->human_effort_target_min,
                    'catalogue_task_id' => $task->catalogue_task_id,
                    'model' => $source->model,
                    'classified_at' => now(),
                    'classified_by' => $actorId,
                    // Not 'Approved', whatever the source was.
                    'classification_status' => 'AI-proposed',
                ]);
                $written++;
            }
        }

        return ['written' => $written, 'texts' => $matched];
    }

    /**
     * Write one classification, preserving when it was FIRST created.
     *
     * `updateOrInsert()` applies one array as both the insert and the update, so
     * a `created_at` in it is rewritten on every re-run - which made
     * "when was this task first classified" unanswerable. Splitting the two
     * paths keeps `created_at` on insert only.
     *
     * `reviewed_by` / `reviewed_at` / `review_note` are deliberately absent from
     * every payload that reaches here: a classification pass must never touch a
     * person's review columns. Rows a person has decided on are held back before
     * this point (see classifyRole), so reaching here with one is already a bug.
     */
    private function upsert(int $tenantId, int $taskId, array $values): void
    {
        $match = ['sub_institute_id' => $tenantId, 'user_jobrole_task_id' => $taskId];

        $exists = DB::table('jobrole_task_execution')->where($match)->exists();

        if ($exists) {
            DB::table('jobrole_task_execution')->where($match)
                ->update($values + ['updated_at' => now()]);
            return;
        }

        DB::table('jobrole_task_execution')
            ->insert($values + $match + ['created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Validate, score and clamp one returned row.
     *
     * @return array{values:array<string,mixed>, clamped:bool}
     */
    private function shape(array $row): array
    {
        $dims = [];
        foreach (array_keys(self::WEIGHTS) as $key) {
            // Clamped to 0-100: a model returning 340 has misread the scale, and
            // its number must not become a score.
            $dims[$key] = max(0, min(100, (int) ($row[$key] ?? 0)));
        }

        $risk = in_array($row['risk_class'] ?? '', self::RISK_CLASSES, true)
            ? $row['risk_class']
            // Unrecognised risk is treated as HIGH, not Low. An unreadable
            // answer must not become permission to automate.
            : 'High';

        $current = $this->validMode($row['execution_mode_current'] ?? null) ?? 'human_only';
        $proposed = $this->validMode($row['execution_mode_target'] ?? null) ?? $current;
        $target = $this->clamp($proposed, $risk);

        $rationale = trim((string) ($row['automation_rationale'] ?? ''));
        if ($target !== $proposed) {
            $rationale = trim($rationale . ' [Capped at ' . $target . ': a ' . $risk
                . ' task cannot be ' . $proposed . '.]');
        }

        return [
            'clamped' => $target !== $proposed,
            'values'  => $dims + [
                'execution_mode_current'  => $current,
                'execution_mode_target'   => $target,
                'ai_executability_score'  => $this->score($dims),
                'risk_class'              => $risk,
                'automation_rationale'    => $rationale !== '' ? $rationale : null,
                'human_effort_current_min' => isset($row['human_effort_current_min'])
                    ? max(0, (int) $row['human_effort_current_min']) : null,
                'human_effort_target_min' => isset($row['human_effort_target_min'])
                    ? max(0, (int) $row['human_effort_target_min']) : null,
            ],
        ];
    }

    /** Weighted mean, with the two inverse dimensions flipped first. */
    public function score(array $dims): int
    {
        $total = 0.0;
        foreach (self::WEIGHTS as $key => $weight) {
            $value = (int) ($dims[$key] ?? 0);
            // High judgement and high consequence REDUCE executability.
            if ($key === 'judgment_required' || $key === 'error_consequence') {
                $value = 100 - $value;
            }
            $total += $value * $weight;
        }

        return (int) round($total);
    }

    /**
     * The risk ceiling. A Regulated task can never be proposed for autonomy,
     * whatever it scored.
     */
    public function clamp(string $mode, string $risk): string
    {
        // Deterministic software is not on the AI ladder and is not capped by it.
        if ($mode === 'system_automated') {
            return $mode;
        }

        $ceiling = self::RISK_CEILING[$risk] ?? 'ai_human_review';
        $modeAt = array_search($mode, self::LADDER, true);
        $capAt  = array_search($ceiling, self::LADDER, true);

        if ($modeAt === false || $capAt === false) {
            return $mode;
        }

        return $modeAt > $capAt ? $ceiling : $mode;
    }

    private function validMode(?string $mode): ?string
    {
        return $mode !== null && array_key_exists($mode, self::MODES) ? $mode : null;
    }

    /** Built from the real task rows, never from a fixed list of subjects. */
    private function prompt($tasks, string $jobrole): string
    {
        $lines = $tasks->map(fn ($t) =>
            "- task_id={$t->id} | " . trim((string) $t->task)
            . ($t->critical_work_function ? " | function: {$t->critical_work_function}" : '')
        )->implode("\n");

        $modes = collect(self::MODES)->map(fn ($d, $m) => "  $m - $d")->implode("\n");

        return <<<TXT
Job role: {$jobrole}

For EACH task below decide how it is executed today and how it could be executed
with current AI, then score four dimensions.

EXECUTION MODES
{$modes}

DIMENSIONS, each 0-100
  digital_input     - are the inputs already digital and structured?
  rule_clarity      - can correct execution be specified as rules or criteria?
  judgment_required - how much contextual or ethical judgement is needed?
  error_consequence - how costly is it to get wrong?

RISK CLASS: Low | Medium | High | Regulated
  Regulated means law, safety or a professional duty governs it.

RULES
- Use ONLY the task_id values listed. Do not invent tasks.
- Be conservative. Accountability, physical presence, or a person's judgement
  about another person means human_only.
- system_automated is for deterministic software, NOT for AI.
- automation_rationale: one sentence on why, naming the deciding factor.
- human_effort_current_min / human_effort_target_min: minutes, only if you can
  estimate honestly; otherwise omit them.

TASKS
{$lines}

Return JSON:
{"tasks":[{"task_id":1,"execution_mode_current":"human_only","execution_mode_target":"human_ai_assist","digital_input":40,"rule_clarity":55,"judgment_required":70,"error_consequence":60,"risk_class":"Medium","automation_rationale":"...","human_effort_current_min":30,"human_effort_target_min":15}]}
TXT;
    }
}
