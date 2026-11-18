<?php

namespace App\Domain\AgentGateway;

use App\Domain\AgentGateway\Tools\Text;
use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\OrgAccounts\OrgAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Organisation agent (slice 7, thin of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md): a person opens an organisation and
 * presses Run; the agent reads what the CRM knows, writes a short evidence-cited brief and up to five proposals, and the gateway decides what happens
 * to each. The whole run is one fixed PIPELINE, not a loop in which a model chooses tools:
 *
 *   1. checks that need no model: the organisation is the caller's, the agent is not switched off, no run is under way, the daily cap is not spent
 *   2. a model that is set up AND priced is required, or the run is recorded as `unavailable` with the reason and nothing else happens
 *   3. OrgAgentContextBuilder: bounded, tenant-scoped, read-only facts, each with a ref
 *   4. ONE model call (AgentModel), JSON out, a token cap; its cost is read back in rupees from the metering rows
 *   5. the brief is kept only if every record it cites was in the context; the proposals go to the Gateway
 *   6. the run is closed with its status, tokens, cost and tool-call count, and `agent.run.finished` is recorded
 *
 * The run is recorded as `running` before the model is called, so a crash leaves a row that says so (a run still `running` after
 * config('agent_actions.stale_run_minutes') is taken to have died and does not block the next one).
 */
final class OrgAgentService
{
    public function __construct(
        private readonly OrgAgentContextBuilder $contexts,
        private readonly OrgAgentPrompt $prompt,
        private readonly AgentModel $model,
        private readonly Gateway $gateway,
        private readonly AutonomyPolicy $policy,
        private readonly AgentEvents $events,
    ) {
    }

    /**
     * @param  string  $trigger  manual | cli
     * @return object the run row as it stands when the run is over (status completed, unavailable or failed)
     *
     * @throws AgentRefused before any run exists: not_found, agent_off, already_running, daily_cap
     */
    public function run(int $tenantId, int $userId, int $orgAccountId, string $trigger = 'manual'): object
    {
        $correlation = (string) Str::uuid();

        // The checks and the new run are ONE transaction that holds a lock on the organisation's row, so two presses of the button at once cannot both
        // find "nothing is running" and both start: the second waits here, then sees the first one's run and is refused. (The lock only holds on MariaDB;
        // sqlite, which the tests run on, has none, so the tests prove the checks and the transaction, not the race.)
        [$subject, $runId] = DB::transaction(function () use ($tenantId, $userId, $orgAccountId, $trigger, $correlation): array {
            $subject = OrgAccount::query()->where('sub_institute_id', $tenantId)->lockForUpdate()->find($orgAccountId);
            if ($subject === null) {
                throw new AgentRefused('not_found', 'Organisation not found.', 404);
            }
            if ($this->policy->killed($tenantId)) {
                throw new AgentRefused('agent_off', 'The agent is switched off for your organisation.', 403);
            }

            $base = DB::table('g2g_agent_runs')->where('sub_institute_id', $tenantId)->where('agent_key', 'organisation')->where('subject_type', 'org_account')->where('subject_id', $subject->id);
            if ((clone $base)->where('status', 'running')->where('started_at', '>', now()->subMinutes((int) config('agent_actions.stale_run_minutes', 5)))->exists()) {
                throw new AgentRefused('already_running', 'The agent is already working on this organisation. Wait for it to finish.', 409);
            }
            $cap = (int) config('agent_actions.daily_run_cap', 10);
            if ((clone $base)->where('started_at', '>=', now()->startOfDay())->count() >= $cap) {
                throw new AgentRefused('daily_cap', "The agent has already run {$cap} times today on this organisation. Try again tomorrow.", 429);
            }

            $now = now();

            return [$subject, (int) DB::table('g2g_agent_runs')->insertGetId([
                'sub_institute_id' => $tenantId, 'user_id' => $userId, 'agent_key' => 'organisation', 'subject_type' => 'org_account', 'subject_id' => $subject->id,
                'trigger_source' => $trigger, 'status' => 'running', 'started_at' => $now, 'correlation_id' => $correlation, 'created_at' => $now, 'updated_at' => $now,
            ])];
        });
        $audit = new AgentAudit($tenantId, $runId);

        try {
            $unavailable = $this->model->readiness($tenantId);
            if ($unavailable !== null) {
                return $this->finish($tenantId, $runId, $userId, $correlation, $audit, 'unavailable', [], 'model_unavailable', $unavailable);
            }

            $context = $this->contexts->build($tenantId, $subject);
            $audit->record('read_context', $context->counts, 'read');

            $started = microtime(true);
            try {
                $completion = $this->model->complete($tenantId, $userId, $this->prompt->messages($context), $runId);
            } catch (AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException $e) {
                return $this->finish($tenantId, $runId, $userId, $correlation, $audit, 'unavailable', $this->costFields($tenantId, $runId), 'model_unavailable', $e->getMessage());
            } catch (\Throwable $e) {
                Log::warning('agent.model_call_failed', ['run' => $runId, 'tenant' => $tenantId, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);

                return $this->finish($tenantId, $runId, $userId, $correlation, $audit, 'failed', $this->costFields($tenantId, $runId), 'model_error', 'The model could not be reached or did not answer. Try again in a moment.');
            }
            $fields = $this->costFields($tenantId, $runId) + ['provider' => mb_substr($completion->provider, 0, 30), 'model' => $completion->model !== null ? mb_substr($completion->model, 0, 80) : null];
            $audit->record('model_call', ['module' => $this->model->module(), 'input_tokens' => $completion->inputTokens, 'output_tokens' => $completion->outputTokens, 'finish_reason' => $completion->finishReason], 'read', null, null, null, (int) round((microtime(true) - $started) * 1000));

            $answer = $this->decode($completion->text);
            if ($answer === null) {
                return $this->finish($tenantId, $runId, $userId, $correlation, $audit, 'failed', $fields, $completion->wasTruncated() ? 'output_truncated' : 'bad_model_output',
                    'The model did not return a usable answer. Try again.');
            }

            $fields += $this->brief($answer, $context, $audit, $runId);
            $this->gateway->handle($tenantId, $runId, $userId, $subject, $context, is_array($answer['proposals'] ?? null) ? $answer['proposals'] : [], $audit);

            return $this->finish($tenantId, $runId, $userId, $correlation, $audit, 'completed', $fields);
        } catch (\Throwable $e) {
            Log::error('agent.run_failed', ['run' => $runId, 'tenant' => $tenantId, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);

            return $this->finish($tenantId, $runId, $userId, $correlation, $audit, 'failed', [], 'internal_error', 'The agent hit a problem and stopped. Nothing further was changed.');
        }
    }

    /** @return array<string, mixed>|null the model's JSON object, or null when its text is not one */
    private function decode(string $text): ?array
    {
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
        $decoded = json_decode($text, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * The brief, kept only when it cites at least one record and every record it cites was in the context. Too long is cut (it is prose).
     *
     * @param  array<string, mixed>  $answer
     * @return array{brief: ?string, brief_evidence: ?string}
     */
    private function brief(array $answer, AgentContext $context, AgentAudit $audit, int $runId): array
    {
        $text = Text::plain($answer['brief'] ?? null, true);
        if ($text === '') {
            $audit->record('write_brief', ['chars' => 0], 'denied', 'empty_brief');

            return ['brief' => null, 'brief_evidence' => null];
        }
        try {
            $evidence = $this->gateway->evidence($answer['brief_evidence'] ?? null, $context);
        } catch (Rejected $r) {
            $audit->record('write_brief', ['chars' => mb_strlen($text)], 'denied', $r->reason);

            return ['brief' => null, 'brief_evidence' => null];
        }
        $max = (int) config('agent_actions.max_brief_chars', 600);
        $audit->record('write_brief', ['chars' => mb_strlen($text)], 'applied', null, null, "g2g_agent_runs:{$runId}");

        return ['brief' => mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text, 'brief_evidence' => (string) json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /** @return array<string, mixed> the run's token and cost columns, read back from the metering rows */
    private function costFields(int $tenantId, int $runId): array
    {
        $cost = AgentRunCost::read($tenantId, $runId);

        return ['input_tokens' => $cost['input_tokens'], 'output_tokens' => $cost['output_tokens'], 'cost' => $cost['cost'], 'cost_currency' => $cost['currency']];
    }

    /**
     * Closes the run and tells the event store. The event is best effort: the run row is the record, and a store that is down must not turn a finished run
     * into an error for the person who pressed the button.
     *
     * @param  array<string, mixed>  $fields
     */
    private function finish(int $tenantId, int $runId, ?int $userId, string $correlation, AgentAudit $audit, string $status, array $fields, ?string $errorCode = null, ?string $message = null): object
    {
        DB::table('g2g_agent_runs')->where('sub_institute_id', $tenantId)->where('id', $runId)->update($fields + [
            'status' => $status, 'finished_at' => now(), 'tool_calls' => min(65535, $audit->count()), 'updated_at' => now(),
            'error_code' => $errorCode, 'error_message' => $message !== null ? mb_substr($message, 0, 500) : null,
        ]);
        $run = DB::table('g2g_agent_runs')->where('sub_institute_id', $tenantId)->where('id', $runId)->first();

        try {
            $proposals = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('run_id', $runId)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();
            $this->events->runFinished($tenantId, $runId, $userId, $correlation, [
                'status' => $status, 'subject_id' => (int) $run->subject_id, 'trigger' => $run->trigger_source, 'model' => $run->model, 'error_code' => $errorCode,
                'input_tokens' => $run->input_tokens, 'output_tokens' => $run->output_tokens, 'cost' => $run->cost !== null ? (float) $run->cost : null, 'cost_currency' => $run->cost_currency,
                'proposals' => array_map('intval', $proposals),
            ]);
        } catch (\Throwable $e) {
            Log::warning('agent.run_event_not_recorded', ['run' => $runId, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return $run;
    }
}
