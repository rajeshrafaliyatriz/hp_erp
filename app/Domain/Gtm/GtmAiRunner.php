<?php

namespace App\Domain\Gtm;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use Illuminate\Support\Facades\DB;

/**
 * The one way a GTM feature asks a model a question.
 *
 * Provider, model, key, quota and usage metering all come from AiModelClient under the
 * `gtm` AI module, so the AI Providers screen controls GTM like every other module. Each
 * call leaves a gtm_analyses row - success OR failure - recording which provider/model
 * answered and whether the result is an estimate, so nothing a model said can later be
 * mistaken for a measured number, and a failed run is visible instead of silently absent.
 *
 * The model is only ever shown rows the caller passes in. Prompts built on top of this
 * must say "use only the facts provided" and the output is stored with `sources`.
 */
final class GtmAiRunner
{
    public const MODULE = 'gtm';

    public function __construct(private readonly AiModelClient $client)
    {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, mixed>  $sources  rows/URLs the answer rests on
     * @return array{data: array<string, mixed>, analysis_id: int, provider: string, model: string}
     *
     * @throws AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException|\RuntimeException
     */
    public function json(
        int $tenantId,
        ?int $userId,
        string $kind,
        ?string $subjectType,
        ?int $subjectId,
        array $messages,
        array $sources = [],
        string $inputSummary = '',
        ?int $playbookId = null,
        int $maxTokens = 1800,
    ): array {
        $base = [
            'sub_institute_id' => $tenantId, 'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'playbook_id' => $playbookId, 'input_summary' => mb_substr($inputSummary, 0, 1000),
            'sources' => json_encode($sources, JSON_UNESCAPED_UNICODE), 'is_estimate' => 1,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ];

        try {
            $completion = $this->client->complete(
                self::MODULE,
                $messages,
                ['json' => true, 'temperature' => 0.2, 'max_tokens' => $maxTokens, 'thinking_budget' => 0,
                    'related_type' => 'gtm_'.$kind, 'related_id' => $subjectId, 'user_id' => $userId],
                $tenantId,
            );
            $data = $this->decode($completion->text);
        } catch (\Throwable $e) {
            DB::table('gtm_analyses')->insert($base + [
                'status' => 'failed', 'error_code' => $this->code($e),
                'result' => json_encode(['error' => mb_substr($e->getMessage(), 0, 500)]),
            ]);
            throw $e;
        }

        $id = (int) DB::table('gtm_analyses')->insertGetId($base + [
            'status' => 'success', 'result' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'provider' => $completion->provider, 'model' => $completion->model,
            'tokens' => $completion->inputTokens + $completion->outputTokens,
        ]);

        return ['data' => $data, 'analysis_id' => $id, 'provider' => $completion->provider, 'model' => $completion->model];
    }

    /**
     * Replace a stored result with the final one after the caller has verified/normalised it,
     * so gtm_analyses holds what the user was actually shown, not the raw model text.
     *
     * @param  array<string, mixed>  $result
     */
    public function amend(int $tenantId, int $analysisId, array $result): void
    {
        DB::table('gtm_analyses')->where('id', $analysisId)->where('sub_institute_id', $tenantId)
            ->update(['result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    /** Whether the tenant can reach a model at all (used by the readiness banner and as a guard). */
    public function configured(int $tenantId): bool
    {
        return app(\App\Domain\AI\Configuration\AiConfigurationResolver::class)->resolve(self::MODULE, $tenantId)->hasKey();
    }

    /** @return array<string, mixed> */
    private function decode(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text;
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('The model did not return valid JSON.');
        }

        return $decoded;
    }

    private function code(\Throwable $e): string
    {
        return match (true) {
            $e instanceof AiNotConfiguredException => 'ai_not_configured',
            $e instanceof AiQuotaExceededException => 'ai_quota',
            $e instanceof AiCredentialsExhaustedException => 'ai_credentials_exhausted',
            default => 'ai_error',
        };
    }
}
