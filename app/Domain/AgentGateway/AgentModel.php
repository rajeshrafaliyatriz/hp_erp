<?php

namespace App\Domain\AgentGateway;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\ProviderCatalog;
use App\Domain\AI\Support\AiCompletion;
use App\Domain\AI\Support\AiModelClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The agent's one door to a model (slice 7, thin). A thin wrapper around AiModelClient, so provider, model, key, quota and metering all come from the
 * existing AI configuration under the module named in config('agent_actions.ai_module'); nothing here chooses or switches providers.
 *
 * It exists as a class of its own so the tests bind a fake in the container (`app()->instance(AgentModel::class, $fake)`) and so the two questions the
 * run asks before spending anything have one place to be answered:
 *
 *   readiness()  may a run be made at all? A model that is not set up, or whose price is not in the rate card, makes the run `unavailable`: a call that
 *                cannot be priced would leave the run with no known cost, and an agent that spends money nobody can see is not run.
 *   complete()   the call itself: one request, JSON out, a token cap, filed under the run so its cost can be read back (AgentRunCost).
 */
class AgentModel
{
    public function __construct(
        private readonly AiModelClient $client,
        private readonly AiConfigurationResolver $configuration,
        private readonly ProviderCatalog $providers,
    ) {
    }

    public function module(): string
    {
        return (string) config('agent_actions.ai_module', 'gtm');
    }

    /** @return string|null why a run cannot be made (a sentence for a person), or null when it can */
    public function readiness(int $tenantId): ?string
    {
        $config = $this->config($tenantId);
        if (! $this->providers->isDriveable($config->provider) || ! $config->hasKey()) {
            return 'No AI model is set up for your organisation, so the agent cannot run. An administrator can add one under AI & Intelligence.';
        }
        if (! $this->hasRate($config->provider, $config->model, $tenantId)) {
            return 'The model "' . ($config->model ?? $config->provider) . '" has no price set, so the agent will not run it: its cost could not be shown. An administrator can set the price in the model list.';
        }

        return null;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  int|null  $maxTokens  the token cap of this call; null is the agent's own (config agent_actions.max_output_tokens). The Ask chat makes two calls of
     *                               different sizes and passes its own. A request, not a ceiling: an administrator's saved per-key ceiling still wins (AiModelClient).
     *
     * @throws \Throwable whatever AiModelClient throws (not configured, quota, credentials, the provider); the run turns it into a status
     */
    public function complete(int $tenantId, ?int $userId, array $messages, int $runId, ?int $maxTokens = null): AiCompletion
    {
        return $this->client->complete($this->module(), $messages, $this->options($userId, $runId, $maxTokens), $tenantId);
    }

    /**
     * What one call is asked for: JSON out, a token cap, a timeout, and the run and person it is filed under so its cost can be read back (AgentRunCost).
     * Public so the tests can read the cap without a provider.
     *
     * @return array<string, mixed>
     */
    public function options(?int $userId, int $runId, ?int $maxTokens = null): array
    {
        return [
            'json' => true,
            'temperature' => (float) config('agent_actions.temperature', 0.2),
            'max_tokens' => $maxTokens ?? (int) config('agent_actions.max_output_tokens', 1200),
            'thinking_budget' => 0,
            'timeout' => (int) config('agent_actions.timeout_seconds', 90),
            'related_type' => AgentRunCost::RELATED_TYPE,
            'related_id' => $runId,
            'user_id' => $userId,
        ];
    }

    /** What the AI configuration resolves to for this organisation under the agent's module (a seam for the tests). */
    protected function config(int $tenantId): \App\Domain\AI\Configuration\ResolvedAiConfiguration
    {
        return $this->configuration->resolve($this->module(), $tenantId);
    }

    /** The same lookup AiUsageMeter prices a call with: the organisation's own row, else the platform's, with both rates filled in. */
    private function hasRate(string $provider, ?string $model, int $tenantId): bool
    {
        if ($model === null || ! Schema::hasTable('ai_models')) {
            return false;
        }

        return DB::table('ai_models')->where('provider', $provider)->where('model_id', $model)
            ->where(fn ($q) => $q->whereNull('sub_institute_id')->orWhere('sub_institute_id', $tenantId))
            ->whereNotNull('input_cost_per_1k')->whereNotNull('output_cost_per_1k')->exists();
    }
}
