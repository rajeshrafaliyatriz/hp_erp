<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiProviderHttpException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * Runs a GTM agent and records the run in the platform's own agent tables.
 *
 * The five agents are platform rows in `agentic_agents` (sub_institute_id NULL, like the other
 * platform agents), so they appear in the Agentic AI module. Each RUN is a per-organisation row
 * in `agentic_agent_runs`, whether it succeeded or not, and an audit event is written either way.
 * The model call itself goes through GtmAiRunner -> AiModelClient, so provider, key, quota and
 * usage metering are the central AI configuration's, not this class's.
 */
final class GtmAgentService
{
    /** @var array<int, class-string<GtmAgent>> */
    public const AGENTS = [ProspectingAgent::class, OutreachAgent::class, DealCoachAgent::class, RevOpsAgent::class, CustomerSuccessAgent::class];

    /** @return class-string<GtmAgent>|null */
    public function find(string $slug): ?string
    {
        foreach (self::AGENTS as $class) {
            if ($class::slug() === $slug) {
                return $class;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function catalogue(int $tenant): array
    {
        $rows = DB::table('agentic_agents')->whereNull('sub_institute_id')->where('origin', 'platform')->whereNull('deleted_at')
            ->whereIn('slug', array_map(fn ($c) => $c::slug(), self::AGENTS))->get()->keyBy('slug');

        return array_map(function ($class) use ($rows, $tenant) {
            $row = $rows[$class::slug()] ?? null;
            $last = $row ? DB::table('agentic_agent_runs')->where('agent_id', $row->id)->where('sub_institute_id', $tenant)->orderByDesc('id')
                ->first(['id', 'status', 'error_message', 'started_at', 'completed_at']) : null;
            $reason = $class::unavailable() ?? ($row === null ? 'Not registered. Run the GTM agents migration.' : ($row->status !== 'deployed' ? 'This agent is not active.' : null));

            return [
                'slug' => $class::slug(), 'name' => $class::name(), 'description' => $class::description(), 'inputs' => $class::inputs(),
                'reads' => $class::reads(), 'agent_id' => $row?->id, 'runnable' => $reason === null, 'unavailable_reason' => $reason,
                'writes' => 'Nothing. Agents read your records and return analysis or drafts for a person to review.',
                'last_run' => $last,
            ];
        }, self::AGENTS);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, http: int, code?: string, message?: string, run_id?: int, data?: array<string, mixed>}
     */
    public function run(string $slug, int $tenant, ?int $userId, array $input): array
    {
        $class = $this->find($slug);
        if (! $class) {
            return ['ok' => false, 'http' => 404, 'code' => 'unknown_agent', 'message' => 'Unknown agent.'];
        }
        $row = DB::table('agentic_agents')->whereNull('sub_institute_id')->where('slug', $slug)->whereNull('deleted_at')->first();
        $reason = $class::unavailable() ?? ($row === null ? 'Not registered. Run the GTM agents migration.' : ($row->status !== 'deployed' ? 'This agent is not active.' : null));
        if ($reason !== null) {
            return ['ok' => false, 'http' => 409, 'code' => 'agent_unavailable', 'message' => $reason];
        }

        $started = now();
        $clock = microtime(true);
        $runId = (int) DB::table('agentic_agent_runs')->insertGetId([
            'sub_institute_id' => $tenant, 'agent_id' => $row->id, 'status' => 'running', 'trigger' => 'manual',
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE), 'started_at' => $started, 'created_by' => $userId, 'created_at' => $started, 'updated_at' => $started,
        ]);

        $out = null;
        $http = 200;
        $code = null;
        $message = null;
        try {
            $out = app($class)->run($tenant, $userId, $input);
        } catch (NoDataException $e) {
            [$http, $code, $message] = [422, 'no_data', $e->getMessage()];
        } catch (\DomainException $e) {
            [$http, $code, $message] = [422, 'invalid_request', $e->getMessage()];
        } catch (AiNotConfiguredException $e) {
            [$http, $code, $message] = [503, 'ai_not_configured', $e->getMessage()];
        } catch (AiQuotaExceededException|AiCredentialsExhaustedException $e) {
            [$http, $code, $message] = [503, 'ai_unavailable', $e->getMessage()];
        } catch (AiProviderHttpException $e) {
            report($e);
            [$http, $code, $message] = match (true) {
                $e->httpStatus === 429 => [503, 'ai_rate_limited', 'The AI provider\'s quota or rate limit has been reached. Nothing was changed - try again later, or check the key under AI & Intelligence → AI Providers.'],
                $e->httpStatus >= 500 => [503, 'ai_provider_busy', 'The AI provider is temporarily overloaded. Nothing was changed - please try again in a minute.'],
                default => [502, 'ai_error', 'The AI provider rejected this request. Nothing was changed.'],
            };
        } catch (\Throwable $e) {
            report($e);
            [$http, $code, $message] = [502, 'ai_error', 'The AI provider could not complete this run. Nothing was changed.'];
        }

        $tokens = $out ? (int) DB::table('gtm_analyses')->where('id', $out['analysis_id'])->value('tokens') : null;
        DB::table('agentic_agent_runs')->where('id', $runId)->update([
            'status' => $out ? 'success' : 'error', 'error_message' => $out ? null : $message,
            'output' => $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null, 'tokens_used' => $tokens,
            'duration_ms' => (int) round((microtime(true) - $clock) * 1000), 'completed_at' => now(), 'updated_at' => now(),
        ]);
        GtmAudit::record('gtm.agent.run', $tenant, 'agentic_agent_runs', $runId, $userId, ['agent' => $slug, 'status' => $out ? 'success' : 'error', 'code' => $code]);

        return $out
            ? ['ok' => true, 'http' => 200, 'run_id' => $runId, 'data' => $out]
            : ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message, 'run_id' => $runId];
    }
}
