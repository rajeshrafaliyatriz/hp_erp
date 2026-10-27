<?php

namespace App\Domain\Crm\Automation;

/**
 * The recommended configuration of the Signal -> Lead -> ... -> Support automation, as data: 24 small single-purpose workflows
 * for the seven steps. They are ordinary rows of Sales & Marketing -> GTM Agents -> Workflows - nothing here is special to the
 * engine - and every one is validated against WorkflowCatalog before it is stored, so this file cannot describe something the
 * screen could not. The end-to-end test installs exactly these.
 *
 * WHY 24 and not 7: a workflow's conditions are all-must-match and its actions run in order with no branching, so "if complete
 * do A, if missing details do B, if duplicate do C" is three workflows. Each workflow stays one readable rule.
 *
 * Money and customer commitments are never automatic: converting a lead, preparing a Quote, emailing it, creating the Sales
 * Order and the Invoice are all consequential actions, so every run waits for an administrator's approval. Nothing here records
 * a payment or changes an invoice's status.
 */
final class LeadAutomationPresets
{
    /**
     * @param  array<string, mixed>  $o  support_user_id (required); prefix, followup{...}, quote_email{...}, invoice_due_days, proposal_stage, invoice_stage, faq_category, ticket_priority
     * @return list<array{name: string, trigger_event: string, conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, description: string}>
     */
    public static function all(array $o): array
    {
        $p = (string) ($o['prefix'] ?? 'Lead Automation');
        $f = ($o['followup'] ?? []) + [
            'subject' => 'Following up: {{lead.company}}',
            'body' => "Hello,\n\nI wanted to follow up regarding {{lead.company}}. If this is of interest, just reply to this email and we will take it from there.\n\nBest regards",
            'intervals' => '2,4,7', 'max_attempts' => 4, 'window_start' => '09:00', 'window_end' => '17:00', 'timezone' => (string) config('app.timezone'), 'weekdays_only' => true,
        ];
        $q = ($o['quote_email'] ?? []) + ['subject' => 'Your quote {{quote.no}}', 'body' => 'Hello, please find our quote below. Reply to this email to accept it or to ask for changes.'];
        $support = (int) ($o['support_user_id'] ?? 0);
        $dueDays = (int) ($o['invoice_due_days'] ?? 30);
        // the application's own stage names (Master Fields picklists); change them here or in the workflow if your lists differ
        $proposalStage = (string) ($o['proposal_stage'] ?? 'Proposal/Quotation');
        $invoiceStage = (string) ($o['invoice_stage'] ?? 'Shipped');

        $m = ($o['meeting'] ?? []) + ['duration_minutes' => 30, 'days_ahead' => 7, 'slots' => 3, 'window_start' => '09:00', 'window_end' => '17:00', 'timezone' => (string) (config('crm.meeting.timezone') ?: config('app.timezone')), 'weekdays_only' => true];
        $normalise = ['type' => 'set_lead_status', 'params' => ['status' => 'Not Contacted', 'only_from' => 'New, (blank)']];
        $cadence = ['type' => 'start_lead_followup', 'params' => $f];
        $w = fn (string $name, string $trigger, array $conditions, array $actions, string $why) => ['name' => "{$p} {$name}", 'trigger_event' => $trigger, 'conditions' => $conditions, 'actions' => $actions, 'description' => $why];
        $eq = fn (string $field, mixed $value) => ['field' => $field, 'op' => 'eq', 'value' => $value];
        $gt = fn (string $field, int $value) => ['field' => $field, 'op' => 'gt', 'value' => $value];

        $onboarding = ['title' => 'Onboarding: {{opportunity.name}} ({{opportunity.no}})', 'assign_to_user_id' => $support] + (isset($o['ticket_priority']) ? ['priority' => $o['ticket_priority']] : []) + (isset($o['faq_category']) ? ['faq_category' => $o['faq_category']] : []);

        return [
            // ---- Step 1: Marketing -> Leads (intake)
            $w('1a Intake - complete lead', 'crm.lead.created', [$eq('lead.duplicate_count', 0), $eq('lead.missing_count', 0), $eq('lead.email_opt_out', false)],
                [$normalise, ['type' => 'assign_lead_round_robin', 'params' => (object) []], ['type' => 'create_lead_task', 'params' => ['title' => 'First contact: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 1, 'description' => 'Score {{lead.score}}, source {{lead.source}}.']]],
                'A new lead with nothing missing and no look-alikes: normalise the status, assign by the round-robin rotation (if enabled) and schedule the first contact.'),
            $w('1b Intake - missing details', 'crm.lead.created', [$gt('lead.missing_count', 0), $eq('lead.duplicate_count', 0)],
                [$normalise, ['type' => 'add_lead_note', 'params' => ['text' => 'This lead is missing: {{lead.missing_fields}}. Nothing was filled in automatically - please add it from a verified source.']], ['type' => 'create_lead_task', 'params' => ['title' => 'Complete lead details: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 1]]],
                'A new lead (for example one promoted from a Signal) that lacks a real contact name, email or phone: ask a person for them; invent nothing.'),
            $w('1c Intake - possible duplicate', 'crm.lead.created', [$gt('lead.duplicate_count', 0)],
                [$normalise, ['type' => 'add_lead_note', 'params' => ['text' => 'Possible duplicate: {{lead.duplicate_leads}} lead(s), {{lead.duplicate_organizations}} organization(s), {{lead.duplicate_contacts}} contact(s) already match. Review before working this lead.']], ['type' => 'create_lead_task', 'params' => ['title' => 'Review possible duplicate: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 0]]],
                'A new lead that looks like an existing lead, organization or contact: hold it for a person to review.'),
            // ---- Step 2: follow-up cadence
            $w('2a Follow-up - new lead', 'crm.lead.created', [$eq('lead.has_email', true), $eq('lead.has_contact_name', true), $eq('lead.email_opt_out', false), $eq('lead.duplicate_count', 0)], [$cadence],
                'Start the follow-up email cadence (waits, retries on the schedule, stops on any reply, opt-out or conversion).'),
            $w('2b Follow-up - details completed', 'crm.lead.ready', [$eq('lead.email_opt_out', false), $eq('lead.duplicate_count', 0)], [$cadence],
                'The same cadence for a lead whose missing details were completed later by a person.'),
            // ---- Step 3: customer replies
            $w('3a Reply - read it and tell the owner', 'crm.lead.replied', [], [['type' => 'classify_lead_reply', 'params' => (object) []], ['type' => 'create_lead_task', 'params' => ['title' => 'Customer replied: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 0, 'description' => 'Read the reply on the lead\'s Emails tab.']], ['type' => 'set_lead_status', 'params' => ['status' => 'Contacted', 'only_from' => 'Attempted to Contact, Not Contacted, New, (blank)']]],
                'A customer answered: read it (AI), give the owner a task, and mark the lead Contacted.'),
            $w('3b Reply - interested', 'crm.lead.reply_classified', [$eq('event.classification', 'interested')], [['type' => 'set_lead_status', 'params' => ['status' => 'Pre Qualified', 'only_from' => 'Contacted']]],
                'An interested reply moves a Contacted lead to Pre Qualified.'),
            $w('3c Reply - prepare a suggested answer', 'crm.lead.reply_classified', [['field' => 'event.classification', 'op' => 'in', 'value' => ['interested', 'needs_information', 'meeting_requested']]], [['type' => 'draft_lead_reply', 'params' => (object) []]],
                'Prepare an AI-suggested DRAFT reply for the owner to edit and send. Never sent automatically.'),
            $w('3d Reply - declined', 'crm.lead.reply_classified', [$eq('event.classification', 'not_interested'), $eq('event.confidence', 'high')], [['type' => 'set_lead_status', 'params' => ['status' => 'Lost Lead']], ['type' => 'add_lead_note', 'params' => ['text' => 'The customer declined; marked Lost per the workflow rule.']]],
                'A clear, high-confidence refusal marks the lead Lost (never deleted).'),
            $w('3e Reply - meeting requested: propose times', 'crm.lead.reply_classified', [$eq('event.classification', 'meeting_requested')], [['type' => 'propose_lead_meeting', 'params' => $m]],
                'The customer asked for a call: read the owner\'s real calendar, propose free times (note, task, DRAFT email). Books and sends nothing.'),
            $w('3f Meeting proposed - hold the first time (needs approval)', 'crm.lead.meeting_proposed', [['field' => 'event.slot_count', 'op' => 'gt', 'value' => 0]], [['type' => 'book_lead_meeting', 'params' => (object) []]],
                'After an administrator approves, put a [Tentative] event for the first proposed time on the owner\'s calendar. Sends no invitation.'),
            // ---- Step 4: qualification and conversion
            $w('4 Qualified - convert (needs approval)', 'crm.lead.qualified', [$eq('lead.has_email', true), $eq('lead.duplicate_leads', 0)], [['type' => 'convert_lead', 'params' => ['create_opportunity' => true]]],
                'When a lead becomes Qualified, convert it into an Organization, Contact and Opportunity - after an administrator approves.'),
            // ---- Step 5: organizations, contacts, opportunities
            $w('5a After conversion - broken links', 'crm.lead.converted', [$eq('converted.links_ok', false)], [['type' => 'add_lead_note', 'params' => ['text' => 'After conversion the Organization, Contact and Opportunity are NOT correctly linked. Please check them.']], ['type' => 'create_lead_task', 'params' => ['title' => 'Fix links after conversion: {{lead.company}}', 'due_in_days' => 0, 'link_to' => 'organization']]],
                'Check the converted records are linked to each other; flag it if not.'),
            $w('5b After conversion - duplicate organization', 'crm.lead.converted', [$gt('converted.organization_duplicates', 0)], [['type' => 'create_lead_task', 'params' => ['title' => 'Review duplicate organization: {{lead.company}}', 'due_in_days' => 1, 'link_to' => 'organization', 'description' => '{{converted.organization_duplicates}} other organization(s) have a very similar name. Nothing was merged.']]],
                'A look-alike Organization exists: ask a person to review; nothing is merged.'),
            $w('5c After conversion - decision-maker unknown', 'crm.lead.converted', [$eq('converted.contact_has_title', false)], [['type' => 'create_lead_task', 'params' => ['title' => 'Identify the decision-maker at {{lead.company}}', 'due_in_days' => 2, 'link_to' => 'organization', 'description' => 'The contact has no job title and the organization has {{converted.organization_contacts}} contact(s). Add the decision-maker from a verified source.']]],
                'The converted contact has no job title: ask for the decision-maker; invent nothing.'),
            $w('5d New Opportunity - enter the pipeline', 'crm.opportunity.created', [], [['type' => 'set_opportunity_stage', 'params' => ['stage' => 'Prospecting', 'only_from' => '(blank)']], ['type' => 'create_opportunity_task', 'params' => ['title' => 'Qualify {{opportunity.name}}', 'due_in_days' => 2, 'description' => 'Set the amount, closing date and next step.']], ['type' => 'add_opportunity_note', 'params' => ['text' => 'Opportunity {{opportunity.no}} entered the pipeline (from a converted lead: {{opportunity.from_lead}}).']]],
                'A new Opportunity with no stage starts at Prospecting, with a task for its owner.'),
            $w('5e Opportunity inactive', 'crm.opportunity.inactive', [], [['type' => 'create_opportunity_task', 'params' => ['title' => 'Inactive {{opportunity.no}}: contact the customer', 'due_in_days' => 0, 'description' => 'No recorded activity for {{opportunity.days_since_activity}} days.']]],
                'An open Opportunity went quiet: a task for its owner (the owner is also notified).'),
            $w('5f Opportunity overdue', 'crm.opportunity.overdue', [], [['type' => 'create_opportunity_task', 'params' => ['title' => 'Overdue {{opportunity.no}}: update the closing date', 'due_in_days' => 0]]],
                'An open Opportunity is past its closing date: a task for its owner (the owner is also notified).'),
            // ---- Step 6: quotes, sales orders, invoices
            $w('6a Proposal/Quotation - prepare a draft quote (needs approval)', 'crm.opportunity.stage_changed', [['field' => 'event.to', 'op' => 'eq', 'value' => $proposalStage]], [['type' => 'prepare_quote', 'params' => (object) []]],
                'When an Opportunity moves to the Proposal stage, prepare a DRAFT Quote from its products at list price - after approval; refuses to guess.'),
            $w('6b Quote created - email it (needs approval)', 'crm.quote.created', [$eq('quote.contact_has_email', true), $gt('quote.lines_count', 0)], [['type' => 'email_quote', 'params' => ['subject' => $q['subject'], 'body' => $q['body']]]],
                'Email the Quote to its contact - after approval, only if CRM email sending is on; the Quote becomes Sent only when really sent.'),
            $w('6c Quote accepted - create the sales order (needs approval)', 'crm.quote.stage_changed', [['field' => 'event.to', 'op' => 'eq', 'value' => 'Accepted']], [['type' => 'create_sales_order', 'params' => (object) []]],
                'A person marks the Quote Accepted: create the Sales Order - after approval, and only if it is still Accepted then.'),
            $w('6d Order shipped - create the invoice (needs approval)', 'crm.sales_order.stage_changed', [['field' => 'event.to', 'op' => 'eq', 'value' => $invoiceStage]], [['type' => 'create_invoice', 'params' => ['due_in_days' => $dueDays, 'required_stage' => $invoiceStage]]],
                'A Sales Order reaches the stage you chose (default Shipped): create the Invoice with your payment terms - after approval. Never records a payment.'),
            $w('6e Invoice overdue - chase', 'crm.invoice.overdue', [], [['type' => 'create_invoice_task', 'params' => ['title' => 'Chase {{invoice.no}} ({{invoice.days_overdue}} days overdue)', 'due_in_days' => 0, 'description' => '{{invoice.balance_due}} {{invoice.currency}} unpaid.']]],
                'An invoice has a balance past its due date: a task for its owner (the owner is also notified).'),
            // ---- Step 7: support
            $w('7 Closed Won - support onboarding', 'crm.opportunity.stage_changed', [['field' => 'event.to', 'op' => 'eq', 'value' => 'Closed Won']], [['type' => 'create_support_onboarding', 'params' => $onboarding]],
                'An Opportunity is won: open ONE onboarding ticket for the customer in Support -> Tickets (SLA policies and escalation then apply).'),
        ];
    }
}
