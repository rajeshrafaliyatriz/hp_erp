<?php

namespace App\Domain\Crm\Ai;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Gtm\GtmAiRunner;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a personalised outreach email for a Lead or Contact.
 */
final class CrmOutreachAgent
{
    public function __construct(private readonly GtmAiRunner $runner)
    {
    }

    /**
     * @return array{subject: string, body: string, tone: string, confidence: float}
     *
     * @throws AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException|\RuntimeException
     */
    public function run(
        int $tenantId,
        int $userId,
        ?int $leadId,
        ?int $contactId,
        ?int $campaignId,
    ): array {
        $subjectType = null;
        $subjectId   = null;
        $name        = 'Prospect';
        $company     = '';
        $role        = '';
        $sources     = [];
        $context     = '';

        if ($leadId) {
            $lead = DB::table('crm_leads as l')
                ->leftJoin('crm_organizations as o', 'o.id', '=', 'l.organization_id')
                ->where('l.id', $leadId)
                ->select(['l.*', 'o.name as org_name', 'o.industry'])
                ->first();

            if ($lead) {
                $name        = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) ?: 'Prospect';
                $company     = $lead->org_name ?? $lead->company ?? '';
                $role        = $lead->title ?? '';
                $subjectType = 'crm_lead';
                $subjectId   = $leadId;
                $sources[]   = ['type' => 'crm_lead', 'id' => $leadId, 'name' => $name];
                $context     = "Lead stage: " . ($lead->stage ?? 'new') . ". Lifecycle: " . ($lead->lifecycle_status ?? 'unknown') . ".";
            }
        } elseif ($contactId) {
            $contact = DB::table('crm_contacts as c')
                ->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')
                ->where('c.id', $contactId)
                ->select(['c.*', 'o.name as org_name', 'o.industry'])
                ->first();

            if ($contact) {
                $name        = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Prospect';
                $company     = $contact->org_name ?? '';
                $role        = $contact->title ?? '';
                $subjectType = 'crm_contact';
                $subjectId   = $contactId;
                $sources[]   = ['type' => 'crm_contact', 'id' => $contactId, 'name' => $name];
            }
        }

        if ($campaignId) {
            $campaign = DB::table('crm_campaigns')->where('id', $campaignId)->first();
            if ($campaign) {
                $context .= " Campaign goal: " . ($campaign->name ?? '') . ".";
                $sources[] = ['type' => 'crm_campaign', 'id' => $campaignId, 'name' => $campaign->name ?? ''];
            }
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are a B2B sales copywriter. Draft personalised outreach emails. Return only valid JSON with no markdown fences.',
            ],
            [
                'role'    => 'user',
                'content' => <<<TEXT
                Draft a personalised cold outreach email for the following prospect. Return a JSON object with these exact keys:
                - subject: email subject line (max 60 chars)
                - body: email body (plain text, max 200 words, professional tone, include [Name] placeholder where referring to the prospect)
                - tone: one of professional, friendly, consultative
                - confidence: float 0-1 reflecting how personalised the draft is given available data

                Prospect name: {$name}
                Company: {$company}
                Role: {$role}
                Context: {$context}

                Use only the facts provided above. Return only the JSON object.
                TEXT,
            ],
        ];

        $result = $this->runner->json(
            tenantId:     $tenantId,
            userId:       $userId,
            kind:         'crm_outreach_draft',
            subjectType:  $subjectType,
            subjectId:    $subjectId,
            messages:     $messages,
            sources:      $sources,
            inputSummary: "Outreach draft for {$name} at {$company}",
        );

        $data = $result['data'];

        return [
            'subject'     => (string) ($data['subject'] ?? ''),
            'body'        => (string) ($data['body'] ?? ''),
            'tone'        => (string) ($data['tone'] ?? 'professional'),
            'confidence'  => (float)  ($data['confidence'] ?? 0.7),
            'analysis_id' => $result['analysis_id'],
            'provider'    => $result['provider'],
            'model'       => $result['model'],
        ];
    }
}
