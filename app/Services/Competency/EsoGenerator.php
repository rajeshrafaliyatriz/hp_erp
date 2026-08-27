<?php

namespace App\Services\Competency;

use App\Exceptions\DeepSeekBudgetException;
use App\Exceptions\DeepSeekTruncatedException;
use App\Services\DeepSeekService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drafting an ESO for one task — the §6.3 "Generate ESO with AI" action.
 *
 * ── WHAT IT IS ALLOWED TO PRODUCE ───────────────────────────────────────────
 *
 * A Draft, marked `ai-generated`, for one task. Never a Template: §6.4 says the
 * first 20 templates are authored BY HAND precisely so somebody discovers which
 * of the 18 fields are load-bearing before anything is generated at scale.
 * Generating templates would skip the only step that answers that question.
 *
 * ── WHY THE CLASSIFICATION IS FED IN ────────────────────────────────────────
 *
 * If a task is already classified `human_only` at High risk, an ESO that hands
 * the work to an agent contradicts a decision somebody may already have
 * approved. So the existing execution mode and risk class go INTO the prompt as
 * constraints - the ESO describes how to execute the task in the mode it has
 * been assigned, rather than re-litigating that.
 */
class EsoGenerator
{
    public function __construct(private readonly DeepSeekService $ai)
    {
    }

    /**
     * @return array{id:?int, title:?string, reason:?string, detail?:string, spent?:array|null}
     */
    public function generateForTask(int $tenantId, int $taskId, ?int $actorId): array
    {
        $empty = ['id' => null, 'title' => null];

        if (!$this->ai->isConfigured()) {
            return $empty + ['reason' => 'not_configured'];
        }

        $task = DB::table('s_user_jobrole_task')
            ->where('sub_institute_id', $tenantId)->where('id', $taskId)
            ->whereNull('deleted_at')->first(['id', 'task', 'jobrole', 'critical_work_function', 'catalogue_task_id']);

        if (!$task) {
            return $empty + ['reason' => 'no_task'];
        }

        // One ESO per task. A second would be a second answer to the same
        // question, and nobody could tell which one is the procedure.
        $existing = DB::table('eso')->where('sub_institute_id', $tenantId)
            ->where('user_jobrole_task_id', $taskId)->whereNull('deleted_at')->first(['id']);
        if ($existing) {
            return ['id' => (int) $existing->id, 'title' => null, 'reason' => 'exists'];
        }

        // The classification, if there is one — it constrains what the ESO may say.
        $execution = DB::table('jobrole_task_execution')
            ->where('sub_institute_id', $tenantId)->where('user_jobrole_task_id', $taskId)
            ->first(['execution_mode_target', 'risk_class', 'automation_rationale', 'classification_status']);

        try {
            $result = $this->ai->chatJson([
                ['role' => 'system', 'content' =>
                    'You write execution procedures for workplace tasks. You are precise and '
                    . 'conservative: you never assign a machine work that needs human '
                    . 'accountability, and you always state what must NOT be done. '
                    . 'You return only valid JSON.'],
                ['role' => 'user', 'content' => $this->prompt($task, $execution)],
            ], ['json' => true, 'temperature' => 0.3, 'max_tokens' => 2600]);
        } catch (DeepSeekBudgetException $e) {
            return $empty + ['reason' => 'insufficient_balance', 'detail' => $e->getMessage()];
        } catch (DeepSeekTruncatedException $e) {
            return $empty + ['reason' => 'truncated', 'detail' => $e->getMessage(), 'spent' => $this->ai->lastUsage()];
        } catch (\Throwable $e) {
            Log::warning('ESO generation failed', [
                'tenant' => $tenantId, 'task' => $taskId, 'error' => $e->getMessage(),
            ]);
            return $empty + ['reason' => 'ai_error', 'spent' => $this->ai->lastUsage()];
        }

        $spent = $this->ai->lastUsage();
        $title = trim((string) ($result['title'] ?? '')) ?: mb_substr(trim((string) $task->task), 0, 180);

        $mode = $execution->execution_mode_target ?? null;
        // The mode is NOT taken from the model. It is a property of the
        // classification, which has its own review path and its own risk ceiling.
        if ($mode !== null && !array_key_exists($mode, TaskExecutionClassifier::MODES)) {
            $mode = null;
        }

        $id = DB::table('eso')->insertGetId([
            'scope' => 'Instance',
            'sub_institute_id' => $tenantId,
            'user_jobrole_task_id' => $taskId,
            'catalogue_task_id' => $task->catalogue_task_id,
            'title' => $title,
            'version' => 1,
            // NEVER Published, and never Reviewed. A person reads it first.
            'status' => 'Draft',
            'execution_mode' => $mode,
            'objective' => $this->text($result, 'objective'),
            'expected_outcome' => $this->text($result, 'expected_outcome'),
            'human_responsibility' => $this->text($result, 'human_responsibility'),
            'agent_responsibility' => $this->text($result, 'agent_responsibility'),
            'human_decision_points' => $this->list($result, 'human_decision_points'),
            'escalation_triggers' => $this->list($result, 'escalation_triggers'),
            'steps' => $this->list($result, 'steps'),
            'inputs' => $this->list($result, 'inputs'),
            'outputs' => $this->list($result, 'outputs'),
            'required_controls' => $this->list($result, 'required_controls'),
            'prohibited_actions' => $this->list($result, 'prohibited_actions'),
            // §5.18 links back to the capability engine. Left to a person: it
            // needs a real competency_id, and inventing one would create a
            // reference to a competency that may not exist.
            'evidence_emitted' => null,
            'source' => 'ai-generated',
            'model' => $this->ai->model(),
            'created_by' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'id' => $id, 'title' => $title, 'reason' => null,
            'spent' => $spent,
            'execution_mode' => $mode,
        ];
    }

    private function text(array $result, string $key): ?string
    {
        $v = $result[$key] ?? null;
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** A list field, JSON-encoded. Anything that is not a list becomes null. */
    private function list(array $result, string $key): ?string
    {
        $v = $result[$key] ?? null;
        if (!is_array($v) || $v === []) {
            return null;
        }
        return json_encode(array_values($v));
    }

    private function prompt(object $task, ?object $execution): string
    {
        $modes = collect(TaskExecutionClassifier::MODES)->map(fn ($d, $m) => "  $m - $d")->implode("\n");

        /*
         * The classification is a CONSTRAINT, not a suggestion. It has already
         * been through the risk ceiling and possibly a human review, and an ESO
         * that quietly reassigns the work would undo that silently.
         */
        $constraint = $execution && $execution->execution_mode_target
            ? "This task has ALREADY been classified as `{$execution->execution_mode_target}`"
              . ($execution->risk_class ? " at {$execution->risk_class} risk" : '')
              . ". Write the procedure FOR THAT MODE. Do not reassign the work to a machine "
              . "or to a person beyond what that mode allows."
              . ($execution->automation_rationale ? "\nWhy it was classified that way: {$execution->automation_rationale}" : '')
            : "This task has not been classified yet, so be conservative: assume a person is "
              . "accountable for the outcome.";

        return <<<TXT
Write an execution procedure (an "ESO") for this single workplace task.

TASK
  {$task->task}
  Job role: {$task->jobrole}
  Work function: {$task->critical_work_function}

EXECUTION MODE
{$constraint}

The six modes, for reference:
{$modes}

In `steps`, `actor` is exactly one of:
  H = a human does this step
  A = an AI agent does this step
  S = deterministic software does this step

RULES
- Be specific to THIS task. Generic project-management filler is worthless.
- 4 to 8 steps. Fewer if the task is genuinely simple.
- `required_controls` are the checks that must be in place - human approval,
  audit log, citation required, confidence threshold, PII restriction,
  segregation of duties.
- `prohibited_actions` are things that must NEVER happen while doing this task -
  external disclosure, irreversible transaction, self-approval, unauthorised
  system change. This field matters more than the others; do not leave it thin.
- Omit any field you cannot answer honestly. An empty field is more useful than
  an invented one.

Return JSON:
{
  "title": "short name for this procedure",
  "objective": "why this task exists",
  "expected_outcome": "what must be true when it is complete",
  "human_responsibility": "what the person is accountable for",
  "agent_responsibility": "what a machine may do, or empty if none",
  "human_decision_points": ["where a person must decide"],
  "escalation_triggers": ["conditions where execution must stop and hand over"],
  "steps": [{"seq":1,"description":"...","actor":"H","tool":"...","output":"..."}],
  "inputs": [{"name":"...","source":"...","format":"...","required":true}],
  "outputs": [{"name":"...","format":"...","destination":"..."}],
  "required_controls": ["..."],
  "prohibited_actions": ["..."]
}
TXT;
    }
}
