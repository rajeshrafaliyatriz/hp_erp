<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\GtmDeal;
use App\Domain\Gtm\Marketing\SegmentCriteria;
use Illuminate\Support\Facades\DB;

/**
 * What a workflow may be built from: its triggers (the triggerable gtm.* events), the facts each trigger
 * exposes to conditions and templates, and the actions. The screen builds its forms from this, and every
 * workflow is validated against it on save, so a definition can only ever reference things that exist.
 *
 * Facts come from the database at run time, scoped to the event's tenant. Contact e-mail addresses and phone
 * numbers are deliberately NOT available: a workflow can ask whether a contact HAS an email, not read it.
 */
final class WorkflowCatalog
{
    /** which entities a trigger's event makes available, by the table the event is about */
    public const PROVIDES = [
        'gtm_accounts' => ['account'], 'gtm_contacts' => ['contact', 'account'], 'gtm_deals' => ['deal', 'account'],
        'gtm_outreach_messages' => ['message', 'contact', 'account'], 'gtm_renewals' => ['renewal', 'account'],
        'crm_leads' => ['lead'],
        'crm_opportunities' => ['opportunity'],
        'crm_quotes' => ['quote'], 'crm_sales_orders' => ['sales_order'], 'crm_invoices' => ['invoice'],
    ];

    /** @var array<string, array<string, array{type: string, label: string, options?: list<string>}>> */
    private const FIELDS = [
        'account' => [
            'account.id' => ['type' => 'number', 'label' => 'Account id'], 'account.name' => ['type' => 'string', 'label' => 'Account name'],
            'account.stage' => ['type' => 'enum', 'label' => 'Account stage', 'options' => SegmentCriteria::ACCOUNT_STAGES],
            'account.industry' => ['type' => 'string', 'label' => 'Industry'], 'account.location' => ['type' => 'string', 'label' => 'Location'],
            'account.icp_fit_score' => ['type' => 'number', 'label' => 'ICP fit score'], 'account.source' => ['type' => 'enum', 'label' => 'Account source', 'options' => SegmentCriteria::SOURCES],
            'account.owner_user_id' => ['type' => 'number', 'label' => 'Account owner id'], 'account.contacts_count' => ['type' => 'number', 'label' => 'Number of contacts'],
            'account.has_contacts' => ['type' => 'bool', 'label' => 'Has contacts'],
        ],
        'contact' => [
            'contact.id' => ['type' => 'number', 'label' => 'Contact id'], 'contact.full_name' => ['type' => 'string', 'label' => 'Contact name'], 'contact.title' => ['type' => 'string', 'label' => 'Contact title'],
            'contact.role_in_deal' => ['type' => 'enum', 'label' => 'Buying role', 'options' => SegmentCriteria::CONTACT_ROLES],
            'contact.status' => ['type' => 'enum', 'label' => 'Contact status', 'options' => SegmentCriteria::CONTACT_STATUSES], 'contact.has_email' => ['type' => 'bool', 'label' => 'Has an email address'],
        ],
        'deal' => [
            'deal.id' => ['type' => 'number', 'label' => 'Deal id'], 'deal.name' => ['type' => 'string', 'label' => 'Deal name'],
            'deal.stage' => ['type' => 'enum', 'label' => 'Deal stage', 'options' => GtmDeal::STAGES], 'deal.amount' => ['type' => 'number', 'label' => 'Deal amount'],
            'deal.currency' => ['type' => 'string', 'label' => 'Deal currency'], 'deal.days_in_stage' => ['type' => 'number', 'label' => 'Days in current stage'],
            'deal.has_next_step' => ['type' => 'bool', 'label' => 'Has a next step'], 'deal.forecast_category' => ['type' => 'enum', 'label' => 'Forecast category', 'options' => ['pipeline', 'best_case', 'commit', 'omitted']],
        ],
        'lead' => [
            'lead.id' => ['type' => 'number', 'label' => 'Lead id'], 'lead.lead_no' => ['type' => 'string', 'label' => 'Lead number'], 'lead.company' => ['type' => 'string', 'label' => 'Lead company'],
            'lead.status' => ['type' => 'string', 'label' => 'Lead status'], 'lead.score' => ['type' => 'number', 'label' => 'Lead score'], 'lead.rating' => ['type' => 'string', 'label' => 'Lead rating'],
            'lead.source' => ['type' => 'string', 'label' => 'Lead source'], 'lead.industry' => ['type' => 'string', 'label' => 'Lead industry'], 'lead.assigned_to' => ['type' => 'number', 'label' => 'Assigned-to user id'],
            'lead.from_signal' => ['type' => 'bool', 'label' => 'Promoted from a Signal'], 'lead.converted' => ['type' => 'bool', 'label' => 'Already converted'], 'lead.email_opt_out' => ['type' => 'bool', 'label' => 'Opted out of email'],
            'lead.has_contact_name' => ['type' => 'bool', 'label' => 'Has a real contact name (not just the company)'], 'lead.has_email' => ['type' => 'bool', 'label' => 'Has an email address'],
            'lead.has_phone' => ['type' => 'bool', 'label' => 'Has a phone or mobile number'], 'lead.has_company' => ['type' => 'bool', 'label' => 'Has a company'], 'lead.has_website' => ['type' => 'bool', 'label' => 'Has a website'],
            'lead.converted_organization_id' => ['type' => 'number', 'label' => 'Converted Organization id'], 'lead.converted_contact_id' => ['type' => 'number', 'label' => 'Converted Contact id'], 'lead.converted_opportunity_id' => ['type' => 'number', 'label' => 'Converted Opportunity id'],
            'lead.missing_count' => ['type' => 'number', 'label' => 'Number of missing details'], 'lead.missing_fields' => ['type' => 'string', 'label' => 'Missing details (text)'],
            'lead.duplicate_leads' => ['type' => 'number', 'label' => 'Other open leads with the same email or name + company'], 'lead.duplicate_organizations' => ['type' => 'number', 'label' => 'Organizations with the same name'],
            'lead.duplicate_contacts' => ['type' => 'number', 'label' => 'Contacts with the same email'], 'lead.duplicate_count' => ['type' => 'number', 'label' => 'Total possible duplicates'],
            'converted.links_ok' => ['type' => 'bool', 'label' => 'After conversion: Organization, Contact and Opportunity are correctly linked'],
            'converted.organization_duplicates' => ['type' => 'number', 'label' => 'After conversion: other Organizations with the same name'],
            'converted.contact_duplicates' => ['type' => 'number', 'label' => 'After conversion: other Contacts with the same email'],
            'converted.organization_contacts' => ['type' => 'number', 'label' => 'After conversion: Contacts on the Organization'],
            'converted.contact_has_title' => ['type' => 'bool', 'label' => 'After conversion: the Contact has a job title (decision-maker known)'],
        ],
        'opportunity' => [
            'opportunity.id' => ['type' => 'number', 'label' => 'Opportunity id'], 'opportunity.no' => ['type' => 'string', 'label' => 'Opportunity number'], 'opportunity.name' => ['type' => 'string', 'label' => 'Opportunity name'],
            'opportunity.stage' => ['type' => 'string', 'label' => 'Sales stage'], 'opportunity.is_open' => ['type' => 'bool', 'label' => 'Still open (not Closed Won / Closed Lost)'],
            'opportunity.amount' => ['type' => 'number', 'label' => 'Amount'], 'opportunity.currency' => ['type' => 'string', 'label' => 'Currency'], 'opportunity.probability' => ['type' => 'number', 'label' => 'Probability %'],
            'opportunity.days_to_close' => ['type' => 'number', 'label' => 'Days until the closing date (negative = overdue)'], 'opportunity.days_in_stage' => ['type' => 'number', 'label' => 'Days in the current stage'],
            'opportunity.days_since_activity' => ['type' => 'number', 'label' => 'Days since the last recorded activity'], 'opportunity.assigned_to' => ['type' => 'number', 'label' => 'Owner user id'],
            'opportunity.lead_source' => ['type' => 'string', 'label' => 'Lead source'], 'opportunity.has_next_step' => ['type' => 'bool', 'label' => 'Has a next step'],
            'opportunity.contacts_count' => ['type' => 'number', 'label' => 'Contacts on the Opportunity'], 'opportunity.quotes_count' => ['type' => 'number', 'label' => 'Quotes on the Opportunity'], 'opportunity.from_lead' => ['type' => 'bool', 'label' => 'Came from a converted lead'],
        ],
        'quote' => [
            'quote.id' => ['type' => 'number', 'label' => 'Quote id'], 'quote.no' => ['type' => 'string', 'label' => 'Quote number'], 'quote.subject' => ['type' => 'string', 'label' => 'Quote subject'],
            'quote.stage' => ['type' => 'string', 'label' => 'Quote stage'], 'quote.total' => ['type' => 'number', 'label' => 'Quote total'], 'quote.currency' => ['type' => 'string', 'label' => 'Currency'],
            'quote.assigned_to' => ['type' => 'number', 'label' => 'Owner user id'], 'quote.has_contact' => ['type' => 'bool', 'label' => 'Has a contact'], 'quote.contact_has_email' => ['type' => 'bool', 'label' => 'The contact has an email address'],
            'quote.lines_count' => ['type' => 'number', 'label' => 'Number of line items'], 'quote.days_to_expiry' => ['type' => 'number', 'label' => 'Days until it expires (negative = expired)'],
            'quote.sales_orders_count' => ['type' => 'number', 'label' => 'Sales Orders already made from it'],
        ],
        'sales_order' => [
            'sales_order.id' => ['type' => 'number', 'label' => 'Sales Order id'], 'sales_order.no' => ['type' => 'string', 'label' => 'Sales Order number'], 'sales_order.subject' => ['type' => 'string', 'label' => 'Subject'],
            'sales_order.stage' => ['type' => 'string', 'label' => 'Sales Order stage'], 'sales_order.total' => ['type' => 'number', 'label' => 'Total'], 'sales_order.currency' => ['type' => 'string', 'label' => 'Currency'],
            'sales_order.assigned_to' => ['type' => 'number', 'label' => 'Owner user id'], 'sales_order.from_quote' => ['type' => 'bool', 'label' => 'Made from a Quote'],
            'sales_order.lines_count' => ['type' => 'number', 'label' => 'Number of line items'], 'sales_order.invoices_count' => ['type' => 'number', 'label' => 'Invoices already made from it'],
        ],
        'invoice' => [
            'invoice.id' => ['type' => 'number', 'label' => 'Invoice id'], 'invoice.no' => ['type' => 'string', 'label' => 'Invoice number'], 'invoice.subject' => ['type' => 'string', 'label' => 'Subject'],
            'invoice.stage' => ['type' => 'string', 'label' => 'Invoice stage'], 'invoice.total' => ['type' => 'number', 'label' => 'Total'], 'invoice.amount_paid' => ['type' => 'number', 'label' => 'Paid so far (the payment ledger)'],
            'invoice.balance_due' => ['type' => 'number', 'label' => 'Balance due'], 'invoice.currency' => ['type' => 'string', 'label' => 'Currency'], 'invoice.assigned_to' => ['type' => 'number', 'label' => 'Owner user id'],
            'invoice.payment_status' => ['type' => 'enum', 'label' => 'Payment status', 'options' => ['Unpaid', 'Partially Paid', 'Paid']], 'invoice.is_overdue' => ['type' => 'bool', 'label' => 'Past its due date with a balance'],
            'invoice.days_overdue' => ['type' => 'number', 'label' => 'Days past the due date'], 'invoice.has_due_date' => ['type' => 'bool', 'label' => 'Has a due date'], 'invoice.from_sales_order' => ['type' => 'bool', 'label' => 'Made from a Sales Order'],
        ],
        'message' => [
            'message.id' => ['type' => 'number', 'label' => 'Message id'], 'message.status' => ['type' => 'string', 'label' => 'Message status'],
            'message.step' => ['type' => 'number', 'label' => 'Sequence step'], 'message.has_campaign' => ['type' => 'bool', 'label' => 'Belongs to a campaign'],
        ],
        'renewal' => [
            'renewal.id' => ['type' => 'number', 'label' => 'Renewal id'], 'renewal.name' => ['type' => 'string', 'label' => 'Renewal name'],
            'renewal.status' => ['type' => 'enum', 'label' => 'Renewal status', 'options' => ['upcoming', 'in_progress', 'renewed', 'churned', 'cancelled']],
            'renewal.days_until' => ['type' => 'number', 'label' => 'Days until renewal (negative = overdue)'], 'renewal.amount' => ['type' => 'number', 'label' => 'Renewal amount'],
            'renewal.currency' => ['type' => 'string', 'label' => 'Renewal currency'],
        ],
    ];

    /** facts that come from the event's own payload, per event type */
    private const EVENT_FIELDS = [
        'gtm.deal.stage_changed' => ['event.from' => ['type' => 'enum', 'label' => 'Stage moved from', 'options' => GtmDeal::STAGES], 'event.to' => ['type' => 'enum', 'label' => 'Stage moved to', 'options' => GtmDeal::STAGES]],
        'gtm.deal.health_flagged' => ['event.flag' => ['type' => 'enum', 'label' => 'Health flag raised', 'options' => []]],
        'gtm.renewal.due_soon' => ['event.kind' => ['type' => 'enum', 'label' => 'Alert kind', 'options' => ['overdue', 'due_soon_not_started']], 'event.days' => ['type' => 'number', 'label' => 'Days until renewal']],
        'gtm.renewal.status_changed' => ['event.from' => ['type' => 'enum', 'label' => 'Status moved from', 'options' => ['upcoming', 'in_progress']], 'event.to' => ['type' => 'enum', 'label' => 'Status moved to', 'options' => ['in_progress', 'renewed', 'churned', 'cancelled']]],
        'gtm.account.icp_scored' => ['event.score' => ['type' => 'number', 'label' => 'ICP score just calculated']],
        'crm.opportunity.created' => ['event.from_lead' => ['type' => 'bool', 'label' => 'Created by converting a lead']],
        'crm.opportunity.stage_changed' => ['event.from' => ['type' => 'string', 'label' => 'Stage moved from'], 'event.to' => ['type' => 'string', 'label' => 'Stage moved to']],
        'crm.opportunity.inactive' => ['event.days_inactive' => ['type' => 'number', 'label' => 'Days without activity']],
        'crm.opportunity.overdue' => ['event.days_overdue' => ['type' => 'number', 'label' => 'Days past the closing date']],
        'crm.quote.created' => ['event.opportunity_id' => ['type' => 'number', 'label' => 'Opportunity id']],
        'crm.quote.stage_changed' => ['event.from' => ['type' => 'string', 'label' => 'Stage moved from'], 'event.to' => ['type' => 'string', 'label' => 'Stage moved to']],
        'crm.sales_order.stage_changed' => ['event.from' => ['type' => 'string', 'label' => 'Stage moved from'], 'event.to' => ['type' => 'string', 'label' => 'Stage moved to']],
        'crm.invoice.stage_changed' => ['event.from' => ['type' => 'string', 'label' => 'Stage moved from'], 'event.to' => ['type' => 'string', 'label' => 'Stage moved to']],
        'crm.invoice.paid' => ['event.total' => ['type' => 'number', 'label' => 'Invoice total']],
        'crm.invoice.overdue' => ['event.days_overdue' => ['type' => 'number', 'label' => 'Days past the due date'], 'event.balance_due' => ['type' => 'number', 'label' => 'Balance due']],
        'crm.lead.qualified' => ['event.from' => ['type' => 'string', 'label' => 'Status it was before']],
        'crm.lead.converted' => ['event.organization_id' => ['type' => 'number', 'label' => 'Organization id'], 'event.contact_id' => ['type' => 'number', 'label' => 'Contact id'], 'event.opportunity_id' => ['type' => 'number', 'label' => 'Opportunity id'], 'event.from_signal' => ['type' => 'bool', 'label' => 'Came from a Signal']],
        'crm.lead.replied' => ['event.inbound_id' => ['type' => 'number', 'label' => 'Inbound reply id'], 'event.classification' => ['type' => 'enum', 'label' => 'Already-known classification (set when the customer asked to stop)', 'options' => ['no_further_contact']]],
        'crm.lead.reply_classified' => ['event.inbound_id' => ['type' => 'number', 'label' => 'Inbound reply id'], 'event.classification' => ['type' => 'enum', 'label' => 'What the reply says', 'options' => ['interested', 'needs_information', 'meeting_requested', 'not_interested', 'no_further_contact', 'unclear']], 'event.confidence' => ['type' => 'enum', 'label' => 'Classifier confidence', 'options' => ['high', 'medium', 'low']]],
        'crm.lead.meeting_proposed' => ['event.inbound_id' => ['type' => 'number', 'label' => 'Inbound reply id'], 'event.slot_count' => ['type' => 'number', 'label' => 'How many free times were found'], 'event.slot_start' => ['type' => 'string', 'label' => 'First proposed time (start)'], 'event.slot_end' => ['type' => 'string', 'label' => 'First proposed time (end)']],
        'gtm.customer.at_risk' => ['event.score' => ['type' => 'number', 'label' => 'Health score']],
    ];

    public const AGENT_INPUTS = [
        'gtm-prospecting' => ['mode', 'account_id'], 'gtm-outreach' => ['account_id', 'contact_id', 'mode', 'campaign_id'],
        'gtm-deal-coach' => ['deal_id'], 'gtm-revops' => [], 'gtm-customer-success' => ['mode'],
    ];

    /**
     * @return array<string, array{label: string, params: array<string, string>, needs: list<string>, consequential: bool, description: string}>
     */
    public static function actions(): array
    {
        return [
            'log_activity' => ['label' => 'Log a note on the record', 'needs' => ['account'], 'consequential' => false, 'description' => 'Adds a note or task to the account timeline (and the deal or contact, when there is one). Supports {{field}} placeholders.',
                'params' => ['subject' => 'text, required, supports {{field}}', 'type' => 'note | task', 'body' => 'text, optional']],
            'set_next_step' => ['label' => 'Set the deal\'s next step', 'needs' => ['deal'], 'consequential' => false, 'description' => 'Writes the next step on an OPEN deal. By default only when it has none.',
                'params' => ['text' => 'text, required, supports {{field}}', 'due_in_days' => 'whole number 0-365, optional', 'only_if_empty' => 'true | false (default true)']],
            'run_agent' => ['label' => 'Run a GTM agent', 'needs' => [], 'consequential' => false, 'description' => 'Runs one of the five read-only agents through the central AI configuration. For the Outreach agent, save_drafts stores the result as DRAFTS - they still need a person to approve them.',
                'params' => ['agent' => implode(' | ', array_keys(self::AGENT_INPUTS)), 'input' => 'object: each value is a fixed value or "@field" to take it from the record', 'save_drafts' => 'true | false (Outreach agent only)']],
            'add_to_campaign' => ['label' => 'Add to a campaign', 'needs' => ['account'], 'consequential' => false, 'description' => 'Adds the account (and the contact, when there is one) as a target of an open campaign.',
                'params' => ['campaign_id' => 'id of one of your open campaigns']],
            'add_lead_note' => ['label' => 'Add a note to the lead', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Adds an "[Automation]" comment to the lead\'s Comments tab. Supports {{field}} placeholders.',
                'params' => ['text' => 'text, required, supports {{field}}']],
            'create_lead_task' => ['label' => 'Create a follow-up task for the lead', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Schedules a calendar task (visible on the Activity/Schedule tab), owned by the lead\'s assigned user. By default it is attached to the lead; after a conversion it can be attached to the converted Organization or Opportunity instead. Skipped when an open task with the same title already exists.',
                'params' => ['title' => 'text, required, supports {{field}}', 'due_in_days' => 'whole number 0-90, optional (default 1)', 'description' => 'text, optional, supports {{field}}', 'link_to' => 'lead | organization | opportunity']],
            'set_lead_status' => ['label' => 'Set the lead status', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Sets Lead Status to a value from the Lead Status picklist. Never touches a converted lead. Optionally only when the current status is one of only_from.',
                'params' => ['status' => 'text, required (a Lead Status picklist value)', 'only_from' => 'text, optional (comma-separated current statuses; (blank) matches an empty status)']],
            'assign_lead_round_robin' => ['label' => 'Assign the lead by the round-robin rotation', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Uses Master Fields -> Lead Scoring & Assignment. Skipped when no rotation is enabled, or when the lead has already been worked (status other than blank, New or Not Contacted).',
                'params' => []],
            'start_lead_followup' => ['label' => 'Start the follow-up email cadence', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Prepares the first email to the lead and then waits, sending the next ones on the schedule below until the lead replies, opts out, is worked by a person, is converted or marked Lost/Junk, or the attempts run out. Emails go through the existing CRM email gates: nothing is delivered unless CRM email sending is switched on. Turn on "requires approval" on the workflow to have an administrator approve each lead before it starts.',
                'params' => ['subject' => 'text, required, supports {{field}}', 'body' => 'text, required, supports {{field}}', 'intervals' => 'text, required (days to wait after each email, e.g. 2,4,7; the last value repeats)',
                    'max_attempts' => 'whole number 1-10, required', 'window_start' => 'text, optional (HH:MM, default 09:00)', 'window_end' => 'text, optional (HH:MM, default 17:00)',
                    'timezone' => 'text, optional (e.g. Asia/Kolkata; default is the server time zone)', 'weekdays_only' => 'true | false']],
            'classify_lead_reply' => ['label' => 'Read the customer\'s reply (classify it)', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Starts from "Customer replied to a lead email". The CRM Reply Classifier reads ONLY the customer\'s new words and decides: interested, needs_information, meeting_requested, not_interested, no_further_contact or unclear. The result is written on the lead and announced as "Lead reply classified", which other workflows can react to. It sends nothing. If the customer asks not to be contacted, the lead is opted out. A temporary AI failure is retried automatically.',
                'params' => []],
            'draft_lead_reply' => ['label' => 'Prepare a suggested reply (draft only)', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Starts from "Lead reply classified". Saves an AI-written suggested answer as a DRAFT email on the lead (Emails tab) for the owner to edit and send. It is never sent automatically, contains no links or invented facts, and is only prepared for interested, needs_information and meeting_requested replies.',
                'params' => []],
            'propose_lead_meeting' => ['label' => 'Propose meeting times (from the owner\'s calendar)', 'needs' => ['lead'], 'consequential' => false, 'description' => 'Starts from "Lead reply classified" (meeting_requested). Reads the lead owner\'s real calendar, finds free times inside the business hours below and writes a note, a task and a DRAFT email offering them. It never invents a time (no free time = it says so) and sends and books nothing.',
                'params' => ['duration_minutes' => 'whole number 5-240, optional (default 30)', 'days_ahead' => 'whole number 1-30, optional (default 7)', 'slots' => 'whole number 1-5, optional (default 3)', 'window_start' => 'text, optional (HH:MM, default 09:00)', 'window_end' => 'text, optional (HH:MM, default 17:00)', 'timezone' => 'text, optional (e.g. Asia/Kolkata; default is the server time zone)', 'weekdays_only' => 'true | false']],
            'book_lead_meeting' => ['label' => 'Hold the first proposed time on the owner\'s calendar', 'needs' => ['lead'], 'consequential' => true, 'description' => 'Starts from "Meeting times proposed". Consequential, so it waits for an administrator\'s approval. Re-checks that the first proposed time is still free and puts a [Tentative] event on the OWNER\'s calendar. It sends NO invitation and adds no attendee: send the invitation yourself once the customer confirms.',
                'params' => []],
            'convert_lead' => ['label' => 'Convert the lead (Organization + Contact + Opportunity)', 'needs' => ['lead'], 'consequential' => true, 'description' => 'Runs the same conversion as Marketing -> Leads -> Convert: creates (or reuses by exact name) the Organization, a Contact and, optionally, an Opportunity owned by the lead\'s owner, and keeps the links back to the lead and its originating Signal. Consequential: a run waits for an administrator\'s approval, and is judged again at approval time (a lead no longer Qualified, or missing its company, name or contact detail, is NOT converted).',
                'params' => ['create_opportunity' => 'true | false', 'required_status' => 'text, optional (the Lead Status the lead must still have; default Qualified)']],
            'add_opportunity_note' => ['label' => 'Add a note to the Opportunity', 'needs' => ['opportunity'], 'consequential' => false, 'description' => 'Adds an "[Automation]" comment to the Opportunity\'s Comments tab. Supports {{field}} placeholders.',
                'params' => ['text' => 'text, required, supports {{field}}']],
            'create_opportunity_task' => ['label' => 'Create a task for the Opportunity owner', 'needs' => ['opportunity'], 'consequential' => false, 'description' => 'Schedules a calendar task on the Opportunity\'s Schedule tab, owned by the Opportunity\'s owner - a call, a meeting to prepare, a reminder. Skipped when an open task with the same title already exists.',
                'params' => ['title' => 'text, required, supports {{field}}', 'due_in_days' => 'whole number 0-90, optional (default 1)', 'description' => 'text, optional, supports {{field}}']],
            'set_opportunity_stage' => ['label' => 'Set the Opportunity sales stage', 'needs' => ['opportunity'], 'consequential' => false, 'description' => 'Moves an OPEN Opportunity to a Sales Stage from the picklist and writes the Stage History, exactly as a person changing it would. The workflow\'s conditions are the business rule that must hold; an email being sent is never one. Closed Lost needs a reason. Optionally only when the current stage is one of only_from.',
                'params' => ['stage' => 'text, required (a Sales Stage picklist value)', 'only_from' => 'text, optional (comma-separated current stages; (blank) matches no stage yet)', 'reason' => 'text, optional (required when the stage is Closed Lost), supports {{field}}']],
            'prepare_quote' => ['label' => 'Prepare a draft Quote from the Opportunity', 'needs' => ['opportunity'], 'consequential' => true, 'description' => 'Creates a DRAFT Quote for the Opportunity from its own products, each priced ONLY at the product\'s list price and tax rate on the Products screen (no discount, nothing guessed). Held back, with the reason on the run, when the Opportunity has no Organization or products, a product has no list price, currencies differ, or a live Quote already exists. Consequential: an administrator approves first, and a person still reviews and sends the Quote.',
                'params' => []],
            'email_quote' => ['label' => 'Email the Quote to the customer', 'needs' => ['quote'], 'consequential' => true, 'description' => 'Queues an email to the Quote\'s contact (a summary: number, lines, total) through the CRM email gates: nothing is delivered unless CRM email sending is on, and the contact must have an address and not have opted out. Only when it is really sent is the Quote moved to Sent. Consequential: an administrator approves first. Supports {{quote.*}} placeholders.',
                'params' => ['subject' => 'text, required, supports {{field}}', 'body' => 'text, required, supports {{field}}']],
            'create_sales_order' => ['label' => 'Create a Sales Order from the accepted Quote', 'needs' => ['quote'], 'consequential' => true, 'description' => 'Runs the same conversion as Quotes -> Create Sales Order, owned by the Quote\'s owner. Judged again at approval time: the Quote must still be at required_stage (default Accepted), have line items and a total, and have no Sales Order yet. Consequential: an administrator approves first.',
                'params' => ['required_stage' => 'text, optional (the Quote stage it must still have; default Accepted)']],
            'create_invoice' => ['label' => 'Create an Invoice from the Sales Order', 'needs' => ['sales_order'], 'consequential' => true, 'description' => 'Runs the same conversion as Sales Orders -> Create Invoice, owned by the Sales Order\'s owner. Judged again at approval time: the Sales Order must have line items and a total and no Invoice yet. due_in_days sets the due date (your payment terms); without it the due date stays empty for a person to set. It never records a payment. Consequential: an administrator approves first.',
                'params' => ['due_in_days' => 'whole number 0-365, optional', 'required_stage' => 'text, optional (a Sales Order stage it must have, e.g. Delivered)']],
            'create_invoice_task' => ['label' => 'Create a task about the Invoice', 'needs' => ['invoice'], 'consequential' => false, 'description' => 'Schedules a calendar task on the invoice\'s Organization, owned by the invoice\'s owner (chase the payment, send a reminder). Skipped when an open task with the same title exists.',
                'params' => ['title' => 'text, required, supports {{field}}', 'due_in_days' => 'whole number 0-90, optional (default 0)', 'description' => 'text, optional, supports {{field}}']],
            'create_support_onboarding' => ['label' => 'Open a support onboarding ticket for the customer', 'needs' => [], 'consequential' => false, 'description' => 'After a sale (start it from an Opportunity stage change to Closed Won, a paid Invoice, a delivered Sales Order or an accepted Quote): opens ONE ticket on the customer\'s Organization in Support -> Tickets, assigned to the person you choose, so the existing SLA policies and SLA-breach escalation apply. It lists the real Opportunity / Quote / Sales Order / Invoice numbers, links the Organization\'s single In Progress Service Contract if there is exactly one, and lists the Published FAQs of the category you name. Put the document number in the title ({{opportunity.no}} / {{invoice.no}}): the same title is never opened twice for an Organization.',
                'params' => ['title' => 'text, required, supports {{field}}', 'assign_to_user_id' => 'whole number, required (an active user of your organisation)', 'priority' => 'text, optional (a Ticket Priority picklist value)', 'category' => 'text, optional (a Ticket Category picklist value)', 'faq_category' => 'text, optional (an FAQ category whose Published FAQs are listed on the ticket)']],
            'change_account_stage' => ['label' => 'Change the account stage', 'needs' => ['account'], 'consequential' => true, 'description' => 'Moves the account to another stage. Consequential: a run needs an administrator\'s approval before it executes.',
                'params' => ['stage' => implode(' | ', SegmentCriteria::ACCOUNT_STAGES)]],
        ];
    }

    /** @return array<string, array{label: string, entity: ?string, provides: list<string>, fields: array<string, array<string, mixed>>}> */
    public static function triggers(): array
    {
        $out = [];
        foreach (GtmEvents::triggerable() as $type) {
            $entity = GtmEvents::TYPES[$type]['entity'];
            $provides = self::PROVIDES[$entity] ?? [];
            $fields = [];
            foreach ($provides as $p) {
                $fields += self::FIELDS[$p];
            }
            $fields += self::EVENT_FIELDS[$type] ?? [];
            if ($type === 'gtm.deal.health_flagged') {
                $fields['event.flag']['options'] = array_keys(DealHealth::FLAGS);
            }
            $out[$type] = ['label' => GtmEvents::TYPES[$type]['label'], 'entity' => $entity, 'provides' => $provides, 'fields' => $fields];
        }

        return $out;
    }

    /**
     * Every problem with a definition, as plain sentences (empty = valid). Checks against this catalogue and, for
     * campaigns, this tenant's own records.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    public static function validate(int $tenant, string $trigger, array $conditions, array $actions): array
    {
        $errors = [];
        $triggers = self::triggers();
        if (! isset($triggers[$trigger])) {
            return ["'{$trigger}' is not an event a workflow can start from."];
        }
        $t = $triggers[$trigger];
        if (count($conditions) > 10) {
            $errors[] = 'At most 10 conditions.';
        }
        foreach ($conditions as $i => $c) {
            $n = $i + 1;
            if (! is_array($c) || ! isset($c['field'], $c['op'])) {
                $errors[] = "Condition {$n} needs a field and an operator.";

                continue;
            }
            if (! isset($t['fields'][$c['field']])) {
                $errors[] = "Condition {$n}: '{$c['field']}' is not a fact this trigger provides.";
            }
            if (! in_array($c['op'], ConditionEvaluator::OPS, true)) {
                $errors[] = "Condition {$n}: unknown operator '{$c['op']}'.";
            } elseif (! in_array($c['op'], ['empty', 'not_empty'], true) && ! array_key_exists('value', $c)) {
                $errors[] = "Condition {$n} needs a value.";
            } elseif (in_array($c['op'], ['in', 'not_in'], true) && ! is_array($c['value'] ?? null)) {
                $errors[] = "Condition {$n}: '{$c['op']}' needs a list of values.";
            } elseif (in_array($c['op'], ['gt', 'gte', 'lt', 'lte'], true) && (($t['fields'][$c['field']]['type'] ?? '') !== 'number' || ! is_numeric($c['value'] ?? null))) {
                $errors[] = "Condition {$n}: '{$c['op']}' compares numbers, and needs a number fact and a number.";
            }
        }
        $errors = array_merge($errors, self::stagePicklistErrors($trigger, $conditions));
        if ($actions === []) {
            $errors[] = 'Add at least one action.';
        }
        if (count($actions) > 6) {
            $errors[] = 'At most 6 actions.';
        }
        $catalog = self::actions();
        foreach ($actions as $i => $a) {
            $n = $i + 1;
            $type = is_array($a) ? ($a['type'] ?? null) : null;
            if (! is_string($type) || ! isset($catalog[$type])) {
                $errors[] = "Action {$n}: unknown action type.";

                continue;
            }
            foreach ($catalog[$type]['needs'] as $need) {
                if (! in_array($need, $t['provides'], true)) {
                    $errors[] = "Action {$n} ({$type}) needs a {$need}, which this trigger does not provide.";
                }
            }
            $errors = array_merge($errors, self::validateAction($tenant, $n, $type, (array) ($a['params'] ?? []), $t));
        }

        return $errors;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $t @return list<string> */
    private static function validateAction(int $tenant, int $n, string $type, array $p, array $t): array
    {
        $e = [];
        $placeholders = function (?string $text) use ($t, $n, &$e) {
            if (preg_match_all('/\{\{\s*([a-z_.]+)\s*\}\}/i', (string) $text, $m)) {
                foreach ($m[1] as $f) {
                    if (! isset($t['fields'][$f])) {
                        $e[] = "Action {$n}: '{{{$f}}}' is not a fact this trigger provides.";
                    }
                }
            }
        };
        switch ($type) {
            case 'log_activity':
                $s = trim((string) ($p['subject'] ?? ''));
                if ($s === '' || mb_strlen($s) > 255) {
                    $e[] = "Action {$n}: a subject of 1-255 characters is required.";
                }
                if (isset($p['type']) && ! in_array($p['type'], ['note', 'task'], true)) {
                    $e[] = "Action {$n}: type must be note or task.";
                }
                if (isset($p['body']) && mb_strlen((string) $p['body']) > 2000) {
                    $e[] = "Action {$n}: the body is limited to 2000 characters.";
                }
                $placeholders($s.' '.($p['body'] ?? ''));
                break;
            case 'set_next_step':
                $s = trim((string) ($p['text'] ?? ''));
                if ($s === '' || mb_strlen($s) > 1000) {
                    $e[] = "Action {$n}: text of 1-1000 characters is required.";
                }
                if (isset($p['due_in_days']) && (! is_int($p['due_in_days']) || $p['due_in_days'] < 0 || $p['due_in_days'] > 365)) {
                    $e[] = "Action {$n}: due_in_days must be a whole number from 0 to 365.";
                }
                if (isset($p['only_if_empty']) && ! is_bool($p['only_if_empty'])) {
                    $e[] = "Action {$n}: only_if_empty must be true or false.";
                }
                $placeholders($s);
                break;
            case 'run_agent':
                $agent = $p['agent'] ?? null;
                if (! is_string($agent) || ! isset(self::AGENT_INPUTS[$agent])) {
                    $e[] = "Action {$n}: choose one of: ".implode(', ', array_keys(self::AGENT_INPUTS)).'.';
                    break;
                }
                foreach ((array) ($p['input'] ?? []) as $k => $v) {
                    if (! in_array($k, self::AGENT_INPUTS[$agent], true)) {
                        $e[] = "Action {$n}: '{$k}' is not an input of {$agent}.";
                    } elseif (is_string($v) && str_starts_with($v, '@') && ! isset($t['fields'][substr($v, 1)])) {
                        $e[] = "Action {$n}: '{$v}' is not a fact this trigger provides.";
                    }
                }
                if (! empty($p['save_drafts']) && $agent !== 'gtm-outreach') {
                    $e[] = "Action {$n}: save_drafts only applies to gtm-outreach.";
                }
                break;
            case 'add_to_campaign':
                $id = $p['campaign_id'] ?? null;
                if (! is_int($id) || ! DB::table('gtm_campaigns')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('status', ['draft', 'active', 'paused'])->where('id', $id)->exists()) {
                    $e[] = "Action {$n}: choose one of your own open campaigns.";
                }
                break;
            case 'add_lead_note':
                $s = trim((string) ($p['text'] ?? ''));
                if ($s === '' || mb_strlen($s) > 2000) {
                    $e[] = "Action {$n}: text of 1-2000 characters is required.";
                }
                $placeholders($s);
                break;
            case 'create_lead_task':
                $s = trim((string) ($p['title'] ?? ''));
                if ($s === '' || mb_strlen($s) > 150) {
                    $e[] = "Action {$n}: a title of 1-150 characters is required.";
                }
                if (isset($p['due_in_days']) && (! is_int($p['due_in_days']) || $p['due_in_days'] < 0 || $p['due_in_days'] > 90)) {
                    $e[] = "Action {$n}: due_in_days must be a whole number from 0 to 90.";
                }
                if (isset($p['description']) && mb_strlen((string) $p['description']) > 2000) {
                    $e[] = "Action {$n}: the description is limited to 2000 characters.";
                }
                if (isset($p['link_to']) && ! in_array($p['link_to'], ['lead', 'organization', 'opportunity'], true)) {
                    $e[] = "Action {$n}: link_to must be lead, organization or opportunity.";
                }
                $placeholders($s.' '.($p['description'] ?? ''));
                break;
            case 'set_lead_status':
                $status = (string) ($p['status'] ?? '');
                if (! self::leadStatuses()->contains($status)) {
                    $e[] = "Action {$n}: status must be one of the Lead Status picklist values (".self::leadStatuses()->implode(', ').').';
                }
                break;
            case 'assign_lead_round_robin':
                break;
            case 'create_support_onboarding':
                if (array_intersect(['opportunity', 'invoice', 'sales_order', 'quote'], $t['provides']) === []) {
                    $e[] = "Action {$n}: onboarding starts from a sale - use an Opportunity, Quote, Sales Order or Invoice trigger.";
                }
                $v = trim((string) ($p['title'] ?? ''));
                if ($v === '' || mb_strlen($v) > 191) {
                    $e[] = "Action {$n}: a title of 1-191 characters is required.";
                }
                $placeholders($v);
                if (! isset($p['assign_to_user_id']) || ! is_int($p['assign_to_user_id']) || ! DB::table('tbluser')->where('id', $p['assign_to_user_id'])->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
                    $e[] = "Action {$n}: assign_to_user_id must be an active user of your organisation.";
                }
                foreach (['priority' => ['tickets', 'priority'], 'category' => ['tickets', 'category'], 'faq_category' => ['faqs', 'category']] as $k => [$module, $field]) {
                    if (isset($p[$k]) && ! DB::table('crm_picklist_values')->where('module', $module)->where('field_key', $field)->where('value', (string) $p[$k])->exists()) {
                        $e[] = "Action {$n}: {$k} must be one of the {$module} {$field} picklist values.";
                    }
                }
                break;
            case 'prepare_quote':
                break;
            case 'email_quote':
                foreach (['subject' => 255, 'body' => 4000] as $k => $max) {
                    $v = trim((string) ($p[$k] ?? ''));
                    if ($v === '' || mb_strlen($v) > $max) {
                        $e[] = "Action {$n}: {$k} of 1-{$max} characters is required.";
                    }
                    $placeholders($v);
                }
                break;
            case 'create_sales_order':
                if (isset($p['required_stage']) && ! DB::table('crm_picklist_values')->where('module', 'quotes')->where('field_key', 'quote_stage')->where('value', (string) $p['required_stage'])->exists()) {
                    $e[] = "Action {$n}: required_stage must be a Quote stage picklist value.";
                }
                break;
            case 'create_invoice':
                if (isset($p['required_stage']) && ! DB::table('crm_picklist_values')->where('module', 'sales_orders')->where('field_key', 'so_stage')->where('value', (string) $p['required_stage'])->exists()) {
                    $e[] = "Action {$n}: required_stage must be a Sales Order Stage value.";
                }
                if (isset($p['due_in_days']) && (! is_int($p['due_in_days']) || $p['due_in_days'] < 0 || $p['due_in_days'] > 365)) {
                    $e[] = "Action {$n}: due_in_days must be a whole number from 0 to 365.";
                }
                break;
            case 'create_invoice_task':
                $v = trim((string) ($p['title'] ?? ''));
                if ($v === '' || mb_strlen($v) > 150) {
                    $e[] = "Action {$n}: a title of 1-150 characters is required.";
                }
                if (isset($p['due_in_days']) && (! is_int($p['due_in_days']) || $p['due_in_days'] < 0 || $p['due_in_days'] > 90)) {
                    $e[] = "Action {$n}: due_in_days must be a whole number from 0 to 90.";
                }
                $placeholders($v.' '.($p['description'] ?? ''));
                break;
            case 'add_opportunity_note':
                $v = trim((string) ($p['text'] ?? ''));
                if ($v === '' || mb_strlen($v) > 2000) {
                    $e[] = "Action {$n}: text of 1-2000 characters is required.";
                }
                $placeholders($v);
                break;
            case 'create_opportunity_task':
                $v = trim((string) ($p['title'] ?? ''));
                if ($v === '' || mb_strlen($v) > 150) {
                    $e[] = "Action {$n}: a title of 1-150 characters is required.";
                }
                if (isset($p['due_in_days']) && (! is_int($p['due_in_days']) || $p['due_in_days'] < 0 || $p['due_in_days'] > 90)) {
                    $e[] = "Action {$n}: due_in_days must be a whole number from 0 to 90.";
                }
                $placeholders($v.' '.($p['description'] ?? ''));
                break;
            case 'set_opportunity_stage':
                $stage = (string) ($p['stage'] ?? '');
                if (! self::opportunityStages()->contains($stage)) {
                    $e[] = "Action {$n}: stage must be one of the Sales Stage picklist values (".self::opportunityStages()->implode(', ').').';
                }
                if ($stage === 'Closed Lost' && trim((string) ($p['reason'] ?? '')) === '') {
                    $e[] = "Action {$n}: Closed Lost needs a reason.";
                }
                $placeholders((string) ($p['reason'] ?? ''));
                break;
            case 'convert_lead':
                if (isset($p['create_opportunity']) && ! is_bool($p['create_opportunity'])) {
                    $e[] = "Action {$n}: create_opportunity must be true or false.";
                }
                if (isset($p['required_status']) && ! self::leadStatuses()->contains((string) $p['required_status'])) {
                    $e[] = "Action {$n}: required_status must be one of the Lead Status picklist values (".self::leadStatuses()->implode(', ').').';
                }
                break;
            case 'classify_lead_reply':
                if (! isset($t['fields']['event.inbound_id'])) {
                    $e[] = "Action {$n}: reading a reply only works from the 'Customer replied to a lead email' or 'Lead reply classified' trigger.";
                }
                break;
            case 'propose_lead_meeting':
                if (! isset($t['fields']['event.confidence'])) {
                    $e[] = "Action {$n}: proposing meeting times only works from the 'Lead reply classified' trigger.";
                }
                foreach (['duration_minutes' => [5, 240], 'days_ahead' => [1, 30], 'slots' => [1, 5]] as $k => [$lo, $hi]) {
                    if (isset($p[$k]) && (! is_int($p[$k]) || $p[$k] < $lo || $p[$k] > $hi)) {
                        $e[] = "Action {$n}: {$k} must be a whole number from {$lo} to {$hi}.";
                    }
                }
                foreach (['window_start', 'window_end'] as $k) {
                    if (isset($p[$k]) && ! \App\Domain\Crm\Leads\FollowUpSchedule::validTime((string) $p[$k])) {
                        $e[] = "Action {$n}: {$k} must be a time such as 09:00.";
                    }
                }
                if (\App\Domain\Crm\Leads\FollowUpSchedule::validTime((string) ($p['window_start'] ?? '09:00')) && \App\Domain\Crm\Leads\FollowUpSchedule::validTime((string) ($p['window_end'] ?? '17:00')) && (string) ($p['window_start'] ?? '09:00') >= (string) ($p['window_end'] ?? '17:00')) {
                    $e[] = "Action {$n}: window_end must be later than window_start.";
                }
                if (isset($p['timezone']) && ! in_array($p['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                    $e[] = "Action {$n}: '{$p['timezone']}' is not a time zone name such as Asia/Kolkata.";
                }
                if (isset($p['weekdays_only']) && ! is_bool($p['weekdays_only'])) {
                    $e[] = "Action {$n}: weekdays_only must be true or false.";
                }
                break;
            case 'book_lead_meeting':
                if (! isset($t['fields']['event.slot_start'])) {
                    $e[] = "Action {$n}: holding a meeting time only works from the 'Meeting times proposed' trigger.";
                }
                break;
            case 'draft_lead_reply':
                if (! isset($t['fields']['event.confidence'])) {
                    $e[] = "Action {$n}: a suggested reply can only be prepared from the 'Lead reply classified' trigger.";
                }
                break;
            case 'start_lead_followup':
                foreach (['subject' => 255, 'body' => 4000] as $k => $max) {
                    $v = trim((string) ($p[$k] ?? ''));
                    if ($v === '' || mb_strlen($v) > $max) {
                        $e[] = "Action {$n}: {$k} of 1-{$max} characters is required.";
                    }
                    $placeholders($v);
                }
                if (\App\Domain\Crm\Leads\FollowUpSchedule::parseIntervals((string) ($p['intervals'] ?? '')) === []) {
                    $e[] = "Action {$n}: intervals must be whole days from 0 to 60 separated by commas, for example 2,4,7.";
                }
                if (! isset($p['max_attempts']) || ! is_int($p['max_attempts']) || $p['max_attempts'] < 1 || $p['max_attempts'] > 10) {
                    $e[] = "Action {$n}: max_attempts must be a whole number from 1 to 10.";
                }
                foreach (['window_start', 'window_end'] as $k) {
                    if (isset($p[$k]) && ! \App\Domain\Crm\Leads\FollowUpSchedule::validTime((string) $p[$k])) {
                        $e[] = "Action {$n}: {$k} must be a time such as 09:00.";
                    }
                }
                if (\App\Domain\Crm\Leads\FollowUpSchedule::validTime((string) ($p['window_start'] ?? '09:00')) && \App\Domain\Crm\Leads\FollowUpSchedule::validTime((string) ($p['window_end'] ?? '17:00')) && (string) ($p['window_start'] ?? '09:00') >= (string) ($p['window_end'] ?? '17:00')) {
                    $e[] = "Action {$n}: window_end must be later than window_start.";
                }
                if (isset($p['timezone']) && ! in_array($p['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                    $e[] = "Action {$n}: '{$p['timezone']}' is not a time zone name such as Asia/Kolkata.";
                }
                if (isset($p['weekdays_only']) && ! is_bool($p['weekdays_only'])) {
                    $e[] = "Action {$n}: weekdays_only must be true or false.";
                }
                break;
            case 'change_account_stage':
                if (! in_array($p['stage'] ?? null, SegmentCriteria::ACCOUNT_STAGES, true)) {
                    $e[] = "Action {$n}: stage must be one of ".implode(', ', SegmentCriteria::ACCOUNT_STAGES).'.';
                }
                break;
        }

        return $e;
    }

    /** picklist (module, field) behind each "stage changed" trigger: a rule on event.to / event.from must name a value that exists there */
    private const STAGE_PICKLISTS = [
        'crm.opportunity.stage_changed' => ['opportunities', 'sales_stage', 'Sales Stage'],
        'crm.quote.stage_changed' => ['quotes', 'quote_stage', 'Quote Stage'],
        'crm.sales_order.stage_changed' => ['sales_orders', 'so_stage', 'Sales Order Stage'],
        'crm.invoice.stage_changed' => ['invoices', 'invoice_stage', 'Invoice Stage'],
    ];

    /**
     * A condition such as "stage moved to Proposal" on a value that is not in the application's own list can never be true, so the
     * workflow would sit there doing nothing without anyone knowing. Refuse it when saving, and say what the real values are.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return list<string>
     */
    private static function stagePicklistErrors(string $trigger, array $conditions): array
    {
        if (! isset(self::STAGE_PICKLISTS[$trigger])) {
            return [];
        }
        [$module, $field, $label] = self::STAGE_PICKLISTS[$trigger];
        $values = DB::table('crm_picklist_values')->where('module', $module)->where('field_key', $field)->where('status', true)->pluck('value')->all();
        if ($values === []) {
            return [];
        }
        $errors = [];
        foreach ($conditions as $i => $c) {
            if (! is_array($c) || ! in_array($c['field'] ?? null, ['event.to', 'event.from'], true) || ! in_array($c['op'] ?? null, ['eq', 'neq', 'in', 'not_in'], true)) {
                continue;
            }
            foreach ((array) ($c['value'] ?? []) === [] ? [] : (is_array($c['value']) ? $c['value'] : [$c['value']]) as $v) {
                if (! in_array((string) $v, $values, true)) {
                    $errors[] = 'Condition '.($i + 1).": '{$v}' is not a {$label} value in this application, so it could never match. Use one of: ".implode(', ', $values).'.';
                }
            }
        }

        return $errors;
    }
    /** The Lead Status picklist (Master Fields), in its own order. @return \Illuminate\Support\Collection<int, string> */
    public static function leadStatuses(): \Illuminate\Support\Collection
    {
        return DB::table('crm_picklist_values')->where('module', 'leads')->where('field_key', 'lead_status')->where('status', true)->orderBy('sort_order')->orderBy('value')->pluck('value');
    }

    /** The Sales Stage picklist (Master Fields), in its own order. @return \Illuminate\Support\Collection<int, string> */
    public static function opportunityStages(): \Illuminate\Support\Collection
    {
        return DB::table('crm_picklist_values')->where('module', 'opportunities')->where('field_key', 'sales_stage')->where('status', true)->orderBy('sort_order')->orderBy('value')->pluck('value');
    }

    /** True when any action in the list is consequential (forces an approval step). @param list<array<string, mixed>> $actions */
    public static function hasConsequential(array $actions): bool
    {
        $catalog = self::actions();

        return (bool) array_filter($actions, fn ($a) => ($catalog[$a['type'] ?? '']['consequential'] ?? false) === true);
    }

    /** Replace {{field}} with the real value. Unknown or missing facts become empty - never a placeholder left in text. @param array<string, mixed> $values */
    public static function render(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/i', function ($m) use ($values) {
            $v = $values[$m[1]] ?? null;

            return is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? (string) $v : '');
        }, $template) ?? $template;
    }
}
