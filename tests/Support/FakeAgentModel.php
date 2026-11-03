<?php

namespace Tests\Support;

use App\Domain\AgentGateway\AgentModel;
use App\Domain\AgentGateway\AgentRunCost;
use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use App\Domain\AI\Support\AiCompletion;
use App\Domain\AI\Support\AiUsageMeter;

/**
 * The model the agent tests talk to. It is the real AgentModel with its two methods replaced and nothing else: no provider is called, but a call still
 * leaves its row in ai_usage_events through the REAL AiUsageMeter, so the price comes from the real ai_models rate card and the cost the run reads back
 * is the real AgentRunCost, not a number the test made up.
 *
 * `respond` is the model's answer: an array (encoded as JSON), a string (returned as it is, to test a broken answer) or a closure that receives the
 * messages the agent sent, so a test can look at the prompt and answer from it.
 */
class FakeAgentModel extends AgentModel
{
    /** @var list<array{tenant: int, user: ?int, messages: list<array{role: string, content: string}>, run: int, max_tokens: ?int}> */
    public array $calls = [];

    /** @var array<string, mixed>|string|\Closure|null */
    public mixed $respond = null;

    public ?string $unavailable = null;

    public ?\Throwable $throws = null;

    public int $inputTokens = 1200;

    public int $outputTokens = 300;

    public function __construct()
    {
        // Deliberately does not call the parent: the real model client is not needed.
    }

    public function readiness(int $tenantId): ?string
    {
        return $this->unavailable;
    }

    public function complete(int $tenantId, ?int $userId, array $messages, int $runId, ?int $maxTokens = null): AiCompletion
    {
        $this->calls[] = ['tenant' => $tenantId, 'user' => $userId, 'messages' => $messages, 'run' => $runId, 'max_tokens' => $maxTokens];
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $answer = $this->respond instanceof \Closure ? ($this->respond)($messages) : $this->respond;
        $text = is_string($answer) ? $answer : (string) json_encode($answer ?? ['brief' => '', 'proposals' => []]);

        app(AiUsageMeter::class)->record(
            $this->module(),
            new ResolvedAiConfiguration(provider: 'deepseek', model: 'deepseek-flash', apiKey: null, source: 'env'),
            $tenantId, $this->inputTokens, $this->outputTokens, 120,
            ['related_type' => AgentRunCost::RELATED_TYPE, 'related_id' => $runId, 'user_id' => $userId],
        );

        return new AiCompletion($text, 'deepseek', 'deepseek-flash', $this->inputTokens, $this->outputTokens, 120, 'stop');
    }
}
