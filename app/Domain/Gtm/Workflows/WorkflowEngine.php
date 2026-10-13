<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\GtmAudit;
use App\Support\RoleKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs workflows: TRIGGER (a recorded gtm.* event) -> CONDITIONS (over the record's real facts) -> ACTIONS, with
 * an approval step where the definition (or a consequential action) demands one.
 *
 * GUARANTEES, each enforced here and covered by tests:
 *  - IDEMPOTENT. UNIQUE (workflow_id, event_id): however often an event is delivered, it starts a workflow once.
 *    Steps are keyed (run_id, step), so a retry or a manual re-run resumes after the last success and never repeats it.
 *  - NO DUPLICATE EXECUTION. A run is CLAIMED atomically (queued/retry_wait -> running); a second worker gets nothing.
 *  - NO LOOPS. An event caused by a workflow action (metadata.source = workflow) never triggers a workflow, and
 *    workflow.* events are not triggerable at all.
 *  - SAFE. Tenant comes from the event; the workflow's creator must STILL be an administrator of that tenant when
 *    it runs; each workflow has a daily run limit; nothing here can send email.
 *  - APPROVAL. A run of a workflow that requires it - or that contains a consequential action - waits in
 *    `pending_approval` and executes only after an administrator approves it.
 *  - RECOVERABLE. A transient failure (AI quota/overload) retries after 10, 60 and 240 minutes, then fails; every
 *    step's result or error is stored.
 */
final class WorkflowEngine
{
    public const BACKOFF_MINUTES = [10, 60, 240];

    public function __construct(private readonly WorkflowActions $actions)
    {
    }

    /** @return array{matched: int, started: int, skipped: int, ignored: bool} */
    public function handleEvent(object $event): array
    {
        $out = ['matched' => 0, 'started' => 0, 'skipped' => 0, 'ignored' => false];
        $meta = json_decode((string) ($event->metadata ?? ''), true);
        if (is_array($meta) && ($meta['source'] ?? null) === 'workflow') {
            $out['ignored'] = true; // caused by a workflow action: never a trigger

            return $out;
        }
        $tenant = (int) $event->sub_institute_id;
        // Only events recorded AFTER activation: a workflow switched on today must not fire on last month's history.
        $workflows = DB::table('gtm_workflows')->where('sub_institute_id', $tenant)->where('status', 'active')->where('trigger_event', $event->type)
            ->whereNotNull('activated_at')->where('activated_at', '<=', $event->recorded_at ?? now())->get();
        if ($workflows->isEmpty()) {
            return $out;
        }
        $ctx = WorkflowContext::fromEvent($event);

        foreach ($workflows as $w) {
            $actions = json_decode((string) $w->actions, true) ?: [];
            $needsApproval = (bool) $w->requires_approval || WorkflowCatalog::hasConsequential($actions);
            $status = null;
            $error = null;
            $trace = [];

            if ($ctx === null) {
                // Without the record a workflow's conditions cannot be judged, so one that HAS conditions is not given a run at all
                // (it might never have matched). Only an unconditional workflow, which would have run regardless, records the skip.
                if ((json_decode((string) $w->conditions, true) ?: []) !== []) {
                    continue;
                }
                [$status, $error] = ['skipped', 'The record this event is about no longer exists.'];
            } else {
                $eval = ConditionEvaluator::evaluate(json_decode((string) $w->conditions, true) ?: [], $ctx['values']);
                if (! $eval['pass']) {
                    continue; // not for this record: no run is recorded
                }
                $trace = $eval['trace'];
                $today = DB::table('gtm_workflow_runs')->where('workflow_id', $w->id)->where('created_at', '>=', now()->startOfDay())->count();
                if ($today >= (int) $w->max_runs_per_day) {
                    [$status, $error] = ['skipped', "The daily limit of {$w->max_runs_per_day} runs for this workflow was reached."];
                } else {
                    $status = $needsApproval ? 'pending_approval' : 'queued';
                }
            }
            $out['matched']++;

            try {
                $runId = (int) DB::table('gtm_workflow_runs')->insertGetId([
                    'sub_institute_id' => $tenant, 'workflow_id' => $w->id, 'event_id' => (int) $event->id, 'event_type' => $event->type, 'entity_type' => $event->entity_type,
                    'entity_id' => $event->entity_id, 'status' => $status, 'context' => $ctx ? json_encode(['values' => $ctx['values'], 'ids' => $ctx['ids'], 'trace' => $trace], JSON_UNESCAPED_UNICODE) : null,
                    'error' => $error, 'attempts' => 0, 'finished_at' => $status === 'skipped' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                continue; // (workflow, event) was already handled: that is the idempotency guarantee working
            }
            if ($status === 'skipped') {
                $out['skipped']++;

                continue;
            }
            $out['started']++;
            if ($status === 'queued') {
                $this->execute($runId);
            }
        }

        return $out;
    }

    /** Execute one run (claimed atomically). Returns its resulting status. */
    public function execute(int $runId): string
    {
        $claimed = DB::table('gtm_workflow_runs')->where('id', $runId)->whereIn('status', ['queued', 'retry_wait'])
            ->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => 'running', 'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => null, 'started_at' => DB::raw('COALESCE(started_at, NOW())'), 'updated_at' => now()]);
        $run = DB::table('gtm_workflow_runs')->find($runId);
        if ($claimed !== 1 || ! $run) {
            return (string) ($run->status ?? 'missing');
        }
        $tenant = (int) $run->sub_institute_id;
        $w = DB::table('gtm_workflows')->where('sub_institute_id', $tenant)->find($run->workflow_id);

        if (! $w || $w->status !== 'active') {
            return $this->finish($run, $w, 'cancelled', 'The workflow is no longer active.');
        }
        if (! $this->ownerIsAdministrator($tenant, $w->created_by ? (int) $w->created_by : null)) {
            return $this->finish($run, $w, 'failed', 'The administrator who created this workflow is no longer an administrator of this organisation, so it will not run. Re-create it under a current administrator.');
        }
        $event = DB::table('g2g_event')->where('sub_institute_id', $tenant)->find($run->event_id);
        $ctx = $event ? WorkflowContext::fromEvent($event) : null;
        if (! $ctx) {
            return $this->finish($run, $w, 'skipped', 'The record this event is about no longer exists.');
        }

        foreach ((json_decode((string) $w->actions, true) ?: []) as $i => $action) {
            $n = $i + 1;
            $done = DB::table('gtm_workflow_run_steps')->where('run_id', $run->id)->where('step', $n)->whereIn('status', ['succeeded', 'skipped'])->exists();
            if ($done) {
                continue; // a resumed run never repeats a step that already succeeded
            }
            $started = now();
            try {
                $result = $this->actions->run((string) $action['type'], (array) ($action['params'] ?? []), $ctx, $run, $w);
                $this->step($run, $n, $action['type'], 'succeeded', $result, null, $started);
            } catch (StepSkipped $e) {
                $this->step($run, $n, $action['type'], 'skipped', ['reason' => $e->getMessage()], null, $started);
            } catch (StepTransient $e) {
                $this->step($run, $n, $action['type'], 'failed', null, $e->getMessage(), $started);
                $wait = self::BACKOFF_MINUTES[(int) $run->attempts - 1] ?? null;
                if ($wait === null) {
                    return $this->finish($run, $w, 'failed', 'Gave up after repeated temporary failures: '.$e->getMessage());
                }
                DB::table('gtm_workflow_runs')->where('id', $run->id)->update(['status' => 'retry_wait', 'error' => mb_substr($e->getMessage(), 0, 500), 'next_attempt_at' => now()->addMinutes($wait), 'updated_at' => now()]);

                return 'retry_wait';
            } catch (\Throwable $e) {
                $this->step($run, $n, $action['type'], 'failed', null, $e->getMessage(), $started);

                return $this->finish($run, $w, 'failed', 'Step '.$n.' ('.$action['type'].'): '.$e->getMessage());
            }
        }

        return $this->finish($run, $w, 'succeeded', null);
    }

    /** Runs waiting for a retry whose time has come. @return array{due: int, results: array<string, int>} */
    public function retryDue(): array
    {
        $ids = DB::table('gtm_workflow_runs')->where('status', 'retry_wait')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(20)->pluck('id');
        $results = [];
        foreach ($ids as $id) {
            $s = $this->execute((int) $id);
            $results[$s] = ($results[$s] ?? 0) + 1;
        }

        return ['due' => $ids->count(), 'results' => $results];
    }

    /** @return array{ok: bool, http: int, code?: string, message?: string, status?: string} */
    public function decide(int $tenant, int $runId, int $userId, bool $approve, ?string $note): array
    {
        $run = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->find($runId);
        if (! $run) {
            return ['ok' => false, 'http' => 404, 'message' => 'Run not found'];
        }
        if ($run->status !== 'pending_approval') {
            return ['ok' => false, 'http' => 422, 'code' => 'not_pending', 'message' => 'Only a run awaiting approval can be approved or rejected.'];
        }
        $w = DB::table('gtm_workflows')->where('sub_institute_id', $tenant)->find($run->workflow_id);
        if ($approve && config('gtm.workflows.require_second_approver') && $w && (int) $w->created_by === $userId) {
            return ['ok' => false, 'http' => 403, 'code' => 'second_approver_required', 'message' => 'A different administrator must approve a run of a workflow you created.'];
        }
        $claimed = DB::table('gtm_workflow_runs')->where('id', $runId)->where('status', 'pending_approval')
            ->update(['status' => $approve ? 'queued' : 'rejected', 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 500) : null, 'finished_at' => $approve ? null : now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return ['ok' => false, 'http' => 409, 'code' => 'already_decided', 'message' => 'Someone else already decided this run.'];
        }
        GtmAudit::record('gtm.workflow.run_decided', $tenant, 'gtm_workflow_runs', $runId, $userId, ['decision' => $approve ? 'approved' : 'rejected']);

        return ['ok' => true, 'http' => 200, 'status' => $approve ? $this->execute($runId) : 'rejected'];
    }

    /** Re-run a FAILED run: completed steps are not repeated. @return array{ok: bool, http: int, code?: string, message?: string, status?: string} */
    public function retry(int $tenant, int $runId, int $userId): array
    {
        $n = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('id', $runId)->where('status', 'failed')->update(['status' => 'queued', 'attempts' => 0, 'error' => null, 'finished_at' => null, 'next_attempt_at' => null, 'updated_at' => now()]);
        if ($n !== 1) {
            return ['ok' => false, 'http' => 422, 'code' => 'not_failed', 'message' => 'Only a failed run can be retried.'];
        }
        GtmAudit::record('gtm.workflow.run_decided', $tenant, 'gtm_workflow_runs', $runId, $userId, ['decision' => 'retried']);

        return ['ok' => true, 'http' => 200, 'status' => $this->execute($runId)];
    }

    /** @return array{ok: bool, http: int, message?: string} */
    public function cancel(int $tenant, int $runId, int $userId): array
    {
        $n = DB::table('gtm_workflow_runs')->where('sub_institute_id', $tenant)->where('id', $runId)->whereIn('status', ['pending_approval', 'queued', 'retry_wait'])
            ->update(['status' => 'cancelled', 'decided_by' => $userId, 'decided_at' => now(), 'finished_at' => now(), 'updated_at' => now()]);
        if ($n !== 1) {
            return ['ok' => false, 'http' => 422, 'message' => 'Only a run that has not started can be cancelled.'];
        }
        GtmAudit::record('gtm.workflow.run_decided', $tenant, 'gtm_workflow_runs', $runId, $userId, ['decision' => 'cancelled']);

        return ['ok' => true, 'http' => 200];
    }

    /**
     * What a workflow WOULD do for one real record, with no side effects: whether its conditions pass (and why),
     * the actions with their templates rendered, and whether a run would need approval.
     *
     * @param  array{values: array<string, mixed>, ids: array<string, int>}  $ctx
     * @return array<string, mixed>
     */
    public function dryRun(object $workflow, array $ctx): array
    {
        $eval = ConditionEvaluator::evaluate(json_decode((string) $workflow->conditions, true) ?: [], $ctx['values']);
        $actions = json_decode((string) $workflow->actions, true) ?: [];
        $catalog = WorkflowCatalog::actions();

        return [
            'would_run' => $eval['pass'], 'conditions' => $eval['trace'],
            'needs_approval' => (bool) $workflow->requires_approval || WorkflowCatalog::hasConsequential($actions),
            'actions' => array_map(function ($a) use ($ctx, $catalog) {
                $p = (array) ($a['params'] ?? []);
                foreach (['subject', 'body', 'text'] as $k) {
                    if (isset($p[$k])) {
                        $p[$k] = WorkflowCatalog::render((string) $p[$k], $ctx['values']);
                    }
                }
                if (isset($p['input']) && is_array($p['input'])) {
                    $p['input'] = array_map(fn ($v) => is_string($v) && str_starts_with($v, '@') ? ($ctx['values'][substr($v, 1)] ?? null) : $v, $p['input']);
                }

                return ['type' => $a['type'], 'label' => $catalog[$a['type']]['label'] ?? $a['type'], 'consequential' => $catalog[$a['type']]['consequential'] ?? false, 'params' => $p];
            }, $actions),
            'facts' => $ctx['values'],
        ];
    }

    private function finish(object $run, ?object $w, string $status, ?string $error): string
    {
        DB::table('gtm_workflow_runs')->where('id', $run->id)->update(['status' => $status, 'error' => $error ? mb_substr($error, 0, 1000) : null, 'finished_at' => now(), 'next_attempt_at' => null, 'updated_at' => now()]);
        $type = $status === 'succeeded' ? 'gtm.workflow.run_completed' : ($status === 'failed' ? 'gtm.workflow.run_failed' : null);
        if ($type) {
            GtmAudit::record($type, (int) $run->sub_institute_id, 'gtm_workflow_runs', (int) $run->id, null, ['workflow_id' => (int) $run->workflow_id, 'attempts' => (int) $run->attempts, 'error' => $error], ['source' => 'workflow']);
        }

        return $status;
    }

    /** @param array<string, mixed>|null $result */
    private function step(object $run, int $n, string $action, string $status, ?array $result, ?string $error, \DateTimeInterface $started): void
    {
        DB::table('gtm_workflow_run_steps')->updateOrInsert(['run_id' => $run->id, 'step' => $n], [
            'sub_institute_id' => $run->sub_institute_id, 'action' => $action, 'status' => $status, 'result' => $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
            'error' => $error ? mb_substr($error, 0, 1000) : null, 'started_at' => $started, 'finished_at' => now(),
        ]);
    }

    /** The creator must STILL be an administrator of this organisation at run time: permissions are checked when it runs, not only when it was saved. */
    private function ownerIsAdministrator(int $tenant, ?int $userId): bool
    {
        if (! $userId) {
            return false;
        }
        $profile = DB::table('tbluser as u')->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')->where('u.id', $userId)->where('u.sub_institute_id', $tenant)->where('u.status', 1)
            ->first(['p.id', 'p.sub_institute_id', 'p.role_key', 'p.name']);

        return $profile !== null && RoleKey::fromProfile($profile) === 'administrator';
    }
}
