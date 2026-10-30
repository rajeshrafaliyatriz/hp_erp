<?php

namespace App\Domain\Crm\Ai;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Gtm\GtmAiRunner;
use Illuminate\Support\Facades\DB;

/**
 * Runs an AI prospecting analysis for an organization or company name.
 * Uses the same GtmAiRunner / `gtm` module used for opportunity research.
 */
final class CrmProspectingAgent
{
    public function __construct(private readonly GtmAiRunner $runner)
    {
    }

    /**
     * @return array{icp_fit_score: float, icp_fit_reason: string, suggested_contacts: array, signals: array, recommendation: string}
     *
     * @throws AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException|\RuntimeException
     */
    public function run(
        int $tenantId,
        int $userId,
        ?int $organizationId,
        ?string $companyName,
    ): array {
        $org = $organizationId
            ? DB::table('crm_organizations')->where('id', $organizationId)->first()
            : null;

        $name      = $org?->name ?? $companyName ?? 'Unknown Company';
        $industry  = $org?->industry ?? 'unknown';
        $size      = $org?->size ?? 'unknown';
        $website   = $org?->website ?? '';
        $country   = $org?->country ?? '';

        $sources = $org ? [['type' => 'crm_organization', 'id' => $organizationId, 'name' => $name]] : [];

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a B2B sales intelligence assistant. Analyse the given company profile and return only valid JSON with no markdown fences.',
            ],
            [
                'role'    => 'user',
                'content' => <<<TEXT
                Analyse this company as a prospecting target and return a JSON object with these exact keys:
                - icp_fit_score: float 0-1 representing fit with a typical B2B SaaS ICP (target: 50-500 employees, tech-forward industry)
                - icp_fit_reason: one sentence
                - suggested_contacts: array of objects with {title, reason} for ideal contact roles to target (max 3)
                - signals: array of strings describing buying signals or triggers visible from publicly available info (max 4)
                - recommendation: one actionable sentence on the best next step

                Company name: {$name}
                Industry: {$industry}
                Size: {$size}
                Country: {$country}
                Website: {$website}

                Use only the facts provided above. Return only the JSON object.
                TEXT,
            ],
        ];

        $result = $this->runner->json(
            tenantId:     $tenantId,
            userId:       $userId,
            kind:         'crm_prospecting',
            subjectType:  'crm_organization',
            subjectId:    $organizationId,
            messages:     $messages,
            sources:      $sources,
            inputSummary: "Prospecting analysis for {$name}",
        );

        $data = $result['data'];

        return [
            'icp_fit_score'      => (float) ($data['icp_fit_score'] ?? 0.5),
            'icp_fit_reason'     => (string) ($data['icp_fit_reason'] ?? ''),
            'suggested_contacts' => (array)  ($data['suggested_contacts'] ?? []),
            'signals'            => (array)  ($data['signals'] ?? []),
            'recommendation'     => (string) ($data['recommendation'] ?? ''),
            'analysis_id'        => $result['analysis_id'],
            'provider'           => $result['provider'],
            'model'              => $result['model'],
        ];
    }
}
