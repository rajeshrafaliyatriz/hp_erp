<?php

namespace App\Domain\Signals\Support;

use App\Domain\AI\Support\AiCompletion;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;

/**
 * One place that asks the configured AI provider for a JSON object and returns it
 * decoded. Shared by the company-opportunity researcher and the ingestion analyser.
 *
 * It reuses AiModelClient, so provider, model, key and metering all come from the
 * existing AI Providers configuration under the module named in config('signals.ai_module').
 * Nothing here chooses or switches providers.
 */
class StructuredAi
{
    public function __construct(private readonly AiModelClient $client)
    {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{data: array<string, mixed>, completion: AiCompletion}
     */
    public function json(int $tenantId, array $messages, ?int $maxTokens = null): array
    {
        $attempts = max(1, (int) config('signals.ai_attempts', 2));
        $last = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $completion = $this->client->complete(
                    (string) config('signals.ai_module'),
                    $messages,
                    ['json' => true, 'temperature' => (float) config('signals.temperature'), 'max_tokens' => $maxTokens ?? (int) config('signals.max_output_tokens')],
                    $tenantId,
                );

                return ['data' => $this->decode($completion->text), 'completion' => $completion];
            } catch (AiNotConfiguredException|AiQuotaExceededException $e) {
                throw $e; // retrying cannot fix configuration or a quota
            } catch (\Throwable $e) {
                $last = $e;
                if ($i < $attempts) {
                    usleep(1_500_000);
                }
            }
        }

        throw $last;
    }

    /** @return array<string, mixed> */
    private function decode(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text;
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('AI response was not valid JSON.');
        }

        return $decoded;
    }

    /** Lower-cased, whitespace-collapsed, quote/dash-normalised text for verbatim checks. */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ']);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
