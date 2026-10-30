<?php

namespace App\Domain\Crm\Ai;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Gtm\GtmAiRunner;
use Illuminate\Support\Facades\DB;

/**
 * Provides AI deal coaching for an Opportunity.
 * Analyses deal context and recommends the next best action.
 */
final class CrmDealCoachAgent
{
    public function __construct(private readonly GtmAiRunner $runner)
    {
    }

    /**
     * @return array{next_best_action: string, reason: string, risk_level: string, suggestions: array}
     *
     * @throws AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException|\RuntimeException
     */
    public function run(int $tenantId, int $userId, int $opportunityId): array
    {
        $opp = DB::table('crm_opportunities as o')
            ->leftJoin('crm_organizations as org', 'org.id', '=', 'o.organization_id')
            ->where('o.id', $opportunityId)
            ->select(['o.*', 'org.name as org_name', 'org.industry'])
            ->first();

        if (! $opp) {
            return [
                'next_best_action' => 'Verify the opportunity exists in the CRM.',
                'reason'           => 'Opportunity record not found.',
                'risk_level'       => 'high',
                'suggestions'      => [],
            ];
        }

        $daysSinceActivity = null;
        if ($opp->updated_at) {
            $daysSinceActivity = (int) round(
                (time() - strtotime($opp->updated_at)) / 86400
            );
        }

        $sources = [
            ['type' => 'crm_opportunity', 'id' => $opportunityId, 'name' => $opp->name ?? ''],
        ];

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an expert B2B sales coach. Analyse the deal details and provide concise, actionable coaching. Return only valid JSON with no markdown fences.',
            ],
            [
                'role'    => 'user',
                'content' => <<<TEXT
                Analyse this sales deal and return a JSON object with these exact keys:
                - next_best_action: one concrete action the rep should take now (max 20 words)
                - reason: one sentence explaining why
                - risk_level: one of low, medium, high
                - suggestions: array of 2-3 short actionable strings (max 15 words each)

                Deal name: {$opp->name}
                Company: {$opp->org_name}
                Industry: {$opp->industry}
                Stage: {$opp->stage}
                Deal value: {$opp->value}
                Expected close: {$opp->expected_close_date}
                Days since last activity: {$daysSinceActivity}
                Probability: {$opp->probability}

                Use only the facts provided above. Return only the JSON object.
                TEXT,
            ],
        ];

        $result = $this->runner->json(
            tenantId:     $tenantId,
            userId:       $userId,
            kind:         'crm_deal_coach',
            subjectType:  'crm_opportunity',
            subjectId:    $opportunityId,
            messages:     $messages,
            sources:      $sources,
            inputSummary: "Deal coaching for opportunity #{$opportunityId}",
        );

        $data = $result['data'];

        return [
            'next_best_action' => (string) ($data['next_best_action'] ?? ''),
            'reason'           => (string) ($data['reason'] ?? ''),
            'risk_level'       => (string) ($data['risk_level'] ?? 'medium'),
            'suggestions'      => (array)  ($data['suggestions'] ?? []),
            'analysis_id'      => $result['analysis_id'],
            'provider'         => $result['provider'],
            'model'            => $result['model'],
        ];
    }
}
