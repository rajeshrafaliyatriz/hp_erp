<?php

namespace App\Domain\Crm\Ai;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Support\Facades\DB;

/**
 * Classifies a raw signal using the Signals AI module (StructuredAi).
 *
 * Returns classification, confidence, relevance score, classification_reason,
 * and optionally a matched account id.
 */
final class CrmSignalClassifier
{
    public function __construct(private readonly StructuredAi $ai)
    {
    }

    /**
     * @return array{
     *   classification: string,
     *   classification_confidence: float,
     *   classification_reason: string,
     *   relevance_score: float,
     *   linked_account_id: int|null
     * }
     *
     * @throws AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException|\RuntimeException
     */
    public function classify(int $tenantId, int $signalId): array
    {
        $signal = DB::table('crm_signal_inbox')
            ->where('id', $signalId)
            ->where('sub_institute_id', $tenantId)
            ->first();

        if (! $signal) {
            throw new \RuntimeException("Signal #{$signalId} not found for tenant {$tenantId}.");
        }

        $orgNames = DB::table('crm_organizations')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->pluck('name', 'id')
            ->take(50)
            ->toArray();

        $orgList = implode(', ', array_values($orgNames));

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a B2B signal intelligence classifier. Analyse market signals and return only valid JSON with no markdown fences.',
            ],
            [
                'role'    => 'user',
                'content' => <<<TEXT
                Classify this market signal and return a JSON object with these exact keys:
                - classification: one of buying_signal, brand_mention, competitor_move, industry_trend, irrelevant
                - classification_confidence: float 0-1
                - classification_reason: one sentence
                - relevance_score: float 0-1 (how relevant is this to a B2B software vendor)
                - linked_account_name: null OR the name of the most likely matching account from the list below (exact match only, null if none)

                Signal title: {$signal->title}
                Signal body: {$signal->body}
                Source: {$signal->source_name}

                Known accounts: {$orgList}

                Use only the facts provided. Return only the JSON object.
                TEXT,
            ],
        ];

        $result = $this->ai->json($tenantId, $messages, 600);
        $data   = $result['data'];

        $linkedAccountId = null;
        if (! empty($data['linked_account_name'])) {
            $matched = array_search($data['linked_account_name'], $orgNames, true);
            if ($matched !== false) {
                $linkedAccountId = (int) $matched;
            }
        }

        return [
            'classification'            => in_array($data['classification'] ?? '', [
                'buying_signal', 'brand_mention', 'competitor_move', 'industry_trend', 'irrelevant',
            ]) ? $data['classification'] : 'unclassified',
            'classification_confidence' => (float) ($data['classification_confidence'] ?? 0.5),
            'classification_reason'     => (string) ($data['classification_reason'] ?? ''),
            'relevance_score'           => (float) ($data['relevance_score'] ?? 0.5),
            'linked_account_id'         => $linkedAccountId,
        ];
    }
}
