<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Gtm\GtmAudit;

/**
 * Tells the workflow engine (and the audit log) what happened to a CRM Opportunity. Recording never fails the action it
 * describes (GtmAudit logs instead). Open means "not Closed Won / Closed Lost".
 */
final class OpportunityEvents
{
    public const CLOSED = ['closed won', 'closed lost'];

    public static function isOpen(?string $stage): bool
    {
        return ! in_array(strtolower(trim((string) $stage)), self::CLOSED, true);
    }

    public static function created(int $tenant, int $opportunityId, ?int $actorId, ?int $organizationId, bool $fromLead): void
    {
        GtmAudit::record('crm.opportunity.created', $tenant, 'crm_opportunities', $opportunityId, $actorId,
            ['organization_id' => $organizationId, 'from_lead' => $fromLead], null, "crm.opportunity.created:{$tenant}:{$opportunityId}");
    }

    /** @param array<string, mixed>|null $meta pass ['source' => 'workflow'] for a change a workflow made (the engine then ignores it: no loops) */
    public static function stageChanged(int $tenant, int $opportunityId, ?string $from, ?string $to, ?int $actorId, ?array $meta = null): void
    {
        if ((string) $from === (string) $to || $to === null) {
            return;
        }
        GtmAudit::record('crm.opportunity.stage_changed', $tenant, 'crm_opportunities', $opportunityId, $actorId, ['from' => $from, 'to' => $to], $meta,
            'crm.opportunity.stage_changed:'.$tenant.':'.$opportunityId.':'.now()->format('YmdHis').':'.substr(md5((string) $from.'>'.(string) $to), 0, 6));
    }
}
