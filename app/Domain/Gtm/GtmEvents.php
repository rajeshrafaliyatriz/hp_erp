<?php

namespace App\Domain\Gtm;

/**
 * Every `gtm.*` event the module emits, with what a workflow needs to know about it.
 *
 * THIS IS NOT A SECOND EVENT CATALOGUE. The authority on which events exist and who consumes them
 * is App\Services\Events\EventCatalogue::SHIPPED, where every type below is registered (audit-only
 * ones against AuditLogProjector, triggerable ones also against GtmWorkflowRunner). This class holds
 * only GTM's own metadata - the entity an event is about, a label, whether a workflow may trigger on
 * it - and tests/Unit/Gtm/GtmEventsTest asserts the two lists are identical, so they cannot drift.
 *
 * `entity` is the table the event's entity_id refers to; the workflow engine uses it to load the
 * facts a condition may test. `triggerable` is false for events a workflow must never react to:
 * its own run events (a workflow reacting to itself is a loop), agent runs, and bookkeeping.
 */
final class GtmEvents
{
    /** @var array<string, array{entity: ?string, label: string, triggerable: bool}> */
    public const TYPES = [
        // accounts
        'gtm.account.created' => ['entity' => 'gtm_accounts', 'label' => 'Account created', 'triggerable' => true],
        'gtm.account.updated' => ['entity' => 'gtm_accounts', 'label' => 'Account updated', 'triggerable' => true],
        'gtm.account.deleted' => ['entity' => 'gtm_accounts', 'label' => 'Account removed', 'triggerable' => false],
        'gtm.account.icp_scored' => ['entity' => 'gtm_accounts', 'label' => 'Account ICP fit scored', 'triggerable' => true],
        // contacts
        'gtm.contact.created' => ['entity' => 'gtm_contacts', 'label' => 'Contact added', 'triggerable' => true],
        'gtm.contact.updated' => ['entity' => 'gtm_contacts', 'label' => 'Contact updated', 'triggerable' => false],
        'gtm.contact.deleted' => ['entity' => 'gtm_contacts', 'label' => 'Contact removed', 'triggerable' => false],
        'gtm.contact.unsubscribed' => ['entity' => 'gtm_contacts', 'label' => 'Contact unsubscribed', 'triggerable' => true],
        'gtm.contact.replied' => ['entity' => 'gtm_contacts', 'label' => 'Contact replied', 'triggerable' => true],
        'gtm.contact.bounced' => ['entity' => 'gtm_contacts', 'label' => 'Contact email bounced', 'triggerable' => true],
        'gtm.contacts.imported' => ['entity' => null, 'label' => 'Contacts imported from CSV', 'triggerable' => false],
        'gtm.activity.logged' => ['entity' => 'gtm_activities', 'label' => 'Activity logged', 'triggerable' => false],
        // playbooks
        'gtm.playbook.created' => ['entity' => 'gtm_playbooks', 'label' => 'Playbook created', 'triggerable' => false],
        'gtm.playbook.updated' => ['entity' => 'gtm_playbooks', 'label' => 'Playbook edited (new version)', 'triggerable' => false],
        'gtm.playbook.restored' => ['entity' => 'gtm_playbooks', 'label' => 'Playbook version restored', 'triggerable' => false],
        'gtm.playbook.customised' => ['entity' => 'gtm_playbooks', 'label' => 'Platform playbook customised', 'triggerable' => false],
        'gtm.playbook.archived' => ['entity' => 'gtm_playbooks', 'label' => 'Playbook archived', 'triggerable' => false],
        // agents
        'gtm.agent.run' => ['entity' => 'agentic_agent_runs', 'label' => 'GTM agent run', 'triggerable' => false],
        // deals
        'gtm.deal.created' => ['entity' => 'gtm_deals', 'label' => 'Deal created', 'triggerable' => true],
        'gtm.deal.updated' => ['entity' => 'gtm_deals', 'label' => 'Deal updated', 'triggerable' => false],
        'gtm.deal.stage_changed' => ['entity' => 'gtm_deals', 'label' => 'Deal stage changed', 'triggerable' => true],
        'gtm.deal.deleted' => ['entity' => 'gtm_deals', 'label' => 'Deal removed', 'triggerable' => false],
        'gtm.deal.scored' => ['entity' => 'gtm_deals', 'label' => 'Deal methodology scored', 'triggerable' => true],
        'gtm.deal.discovery_analysed' => ['entity' => 'gtm_deals', 'label' => 'Discovery notes analysed', 'triggerable' => false],
        'gtm.deal.health_flagged' => ['entity' => 'gtm_deals', 'label' => 'Deal newly flagged by a health rule', 'triggerable' => true],
        // outreach
        'gtm.outreach.created' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach draft created', 'triggerable' => false],
        'gtm.outreach.updated' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach draft edited', 'triggerable' => false],
        'gtm.outreach.submitted' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach submitted for approval', 'triggerable' => false],
        'gtm.outreach.approved' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach approved', 'triggerable' => true],
        'gtm.outreach.approval_invalidated' => ['entity' => 'gtm_outreach_messages', 'label' => 'Approval withdrawn because the text was edited', 'triggerable' => false],
        'gtm.outreach.scheduled' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach scheduled', 'triggerable' => false],
        'gtm.outreach.rejected' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach rejected', 'triggerable' => false],
        'gtm.outreach.cancelled' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach cancelled', 'triggerable' => false],
        'gtm.outreach.sent' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach email sent', 'triggerable' => true],
        'gtm.outreach.send_failed' => ['entity' => 'gtm_outreach_messages', 'label' => 'Outreach send failed', 'triggerable' => false],
        'gtm.inbound.received' => ['entity' => 'gtm_inbound_events', 'label' => 'Inbound reply/bounce event received', 'triggerable' => false],
        'gtm.suppression.added' => ['entity' => 'gtm_suppressions', 'label' => 'Address added to suppression list', 'triggerable' => false],
        'gtm.suppression.removed' => ['entity' => 'gtm_suppressions', 'label' => 'Address removed from suppression list', 'triggerable' => false],
        // marketing
        'gtm.segment.created' => ['entity' => 'gtm_segments', 'label' => 'Segment created', 'triggerable' => false],
        'gtm.segment.updated' => ['entity' => 'gtm_segments', 'label' => 'Segment updated', 'triggerable' => false],
        'gtm.segment.deleted' => ['entity' => 'gtm_segments', 'label' => 'Segment removed', 'triggerable' => false],
        'gtm.campaign.created' => ['entity' => 'gtm_campaigns', 'label' => 'Campaign created', 'triggerable' => false],
        'gtm.campaign.updated' => ['entity' => 'gtm_campaigns', 'label' => 'Campaign updated', 'triggerable' => false],
        'gtm.campaign.status_changed' => ['entity' => 'gtm_campaigns', 'label' => 'Campaign status changed', 'triggerable' => false],
        'gtm.campaign.members_added' => ['entity' => 'gtm_campaigns', 'label' => 'Campaign targets added', 'triggerable' => false],
        // customers
        'gtm.renewal.created' => ['entity' => 'gtm_renewals', 'label' => 'Renewal recorded', 'triggerable' => true],
        'gtm.renewal.updated' => ['entity' => 'gtm_renewals', 'label' => 'Renewal updated', 'triggerable' => false],
        'gtm.renewal.status_changed' => ['entity' => 'gtm_renewals', 'label' => 'Renewal status changed', 'triggerable' => true],
        'gtm.renewal.due_soon' => ['entity' => 'gtm_renewals', 'label' => 'Renewal due soon without progress', 'triggerable' => true],
        'gtm.customer.at_risk' => ['entity' => 'gtm_accounts', 'label' => 'Customer newly at risk', 'triggerable' => true],
        // forecasting
        'gtm.forecast.settings_updated' => ['entity' => null, 'label' => 'Forecast probabilities changed', 'triggerable' => false],
        // workflows (never triggerable: a workflow reacting to its own runs is a loop)
        'gtm.workflow.created' => ['entity' => 'gtm_workflows', 'label' => 'Workflow created', 'triggerable' => false],
        'gtm.workflow.updated' => ['entity' => 'gtm_workflows', 'label' => 'Workflow updated', 'triggerable' => false],
        'gtm.workflow.status_changed' => ['entity' => 'gtm_workflows', 'label' => 'Workflow activated / paused / archived', 'triggerable' => false],
        'gtm.workflow.run_completed' => ['entity' => 'gtm_workflow_runs', 'label' => 'Workflow run completed', 'triggerable' => false],
        'gtm.workflow.run_failed' => ['entity' => 'gtm_workflow_runs', 'label' => 'Workflow run failed', 'triggerable' => false],
        'gtm.workflow.run_decided' => ['entity' => 'gtm_workflow_runs', 'label' => 'Workflow run approved or rejected', 'triggerable' => false],
        // reports
        'gtm.report.exported' => ['entity' => null, 'label' => 'Report exported', 'triggerable' => false],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return list<string> events a workflow may use as a trigger */
    public static function triggerable(): array
    {
        return array_keys(array_filter(self::TYPES, fn ($t) => $t['triggerable']));
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }
}
