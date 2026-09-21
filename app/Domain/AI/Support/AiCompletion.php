<?php

namespace App\Domain\AI\Support;

/**
 * What one model call produced, and what it cost.
 *
 * The usage figures are carried rather than discarded because they are the only
 * honest source for Usage & Cost. Deriving spend from character counts is a guess;
 * the provider tells us the real numbers on every successful response, and dropping
 * them means the meter can never be more than an estimate.
 *
 * `provider` and `model` are here for the same reason `ResolvedAiConfiguration`
 * carries `source`: when an answer is wrong, the first question is which model
 * produced it, and a value that has to be re-derived from configuration after the
 * fact may no longer be the one that ran.
 */
final class AiCompletion
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $latencyMs = 0,
        /** Why the model stopped. `length` means the answer was cut off. */
        public readonly ?string $finishReason = null,
    ) {
    }

    /** True when the provider stopped writing because it hit the token ceiling. */
    public function wasTruncated(): bool
    {
        return in_array(strtolower((string) $this->finishReason), ['length', 'max_tokens'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'latency_ms' => $this->latencyMs,
            'finish_reason' => $this->finishReason,
            'truncated' => $this->wasTruncated(),
        ];
    }
}
