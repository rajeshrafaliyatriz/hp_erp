<?php

namespace App\Domain\Crm\Support;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts support for a customer once a sale has happened: ONE onboarding ticket on the customer's Organization, using the
 * Support module's own tables (crm_tickets, crm_ticket_comments) - so the existing SLA policies and the SLA-breach escalation
 * (crm:tickets-escalate-sla -> ticket.sla_breached -> notification) apply to it exactly as to any other ticket.
 *
 * Everything on the ticket is a recorded fact:
 *  - the Organization and the Contact come from the sale (the Opportunity's own contact, else the Organization's only contact);
 *  - the Service Contract is linked only when the Organization has EXACTLY ONE In Progress one (never created, never guessed);
 *  - the source records (Opportunity, Quote, Sales Order, Invoice numbers) are listed from the real relations between them;
 *  - the "helpful articles" are the tenant's own Published FAQs of the category the rule names.
 * It is idempotent: a ticket with the same title on the same Organization is never created twice.
 */
final class OnboardingTicket
{
    /**
     * @param  array{title: string, assigned_to: int, priority?: ?string, category?: ?string, faq_category?: ?string}  $opts
     * @return array{ok: bool, reason?: string, ticket_id?: int}
     */
    public static function create(int $tenant, int $organizationId, ?int $contactId, array $sources, array $opts, int $actorId): array
    {
        $org = DB::table('crm_organizations')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($organizationId);
        if (! $org) {
            return ['ok' => false, 'reason' => 'the Organization no longer exists'];
        }
        $title = mb_substr($opts['title'], 0, 191);
        if (DB::table('crm_tickets')->where('sub_institute_id', $tenant)->where('organization_id', $org->id)->where('title', $title)->whereNull('deleted_at')->exists()) {
            return ['ok' => false, 'reason' => 'an onboarding ticket with this title already exists for the Organization'];
        }
        if (! DB::table('tbluser')->where('id', $opts['assigned_to'])->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
            return ['ok' => false, 'reason' => 'the person the ticket should be assigned to is not an active user of this organisation'];
        }
        if ($contactId === null) {
            $only = DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('organization_id', $org->id)->limit(2)->pluck('id');
            $contactId = $only->count() === 1 ? (int) $only->first() : null;
        } elseif (! DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('id', $contactId)->exists()) {
            $contactId = null;
        }
        $contracts = DB::table('crm_service_contracts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('organization_id', $org->id)->where('contract_status', 'In Progress')->limit(2)->pluck('id');
        $contractId = $contracts->count() === 1 ? (int) $contracts->first() : null;

        $lines = ["Onboarding for {$org->name}."];
        if ($sources !== []) {
            $lines[] = 'Source records: '.implode('; ', $sources).'.';
        }
        if (! empty($opts['faq_category'])) {
            $faqs = DB::table('crm_faqs')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('status', 'Published')->where('category', $opts['faq_category'])->orderBy('id')->limit(5)->get(['faq_no', 'question']);
            if ($faqs->isNotEmpty()) {
                $lines[] = "Helpful articles ({$opts['faq_category']}): ".$faqs->map(fn ($f) => "{$f->faq_no} {$f->question}")->implode(' | ');
            }
        }
        $lines[] = 'Created automatically after the sale. Nothing on this ticket was filled in beyond the records above.';

        $id = DB::transaction(function () use ($tenant, $org, $contactId, $contractId, $title, $opts, $lines, $actorId) {
            $id = (int) DB::table('crm_tickets')->insertGetId([
                'ticket_no' => 'TT-'.Str::upper(Str::random(8)), 'title' => $title, 'organization_id' => $org->id, 'contact_id' => $contactId, 'service_contract_id' => $contractId,
                'status' => 'Open', 'priority' => $opts['priority'] ?? null, 'category' => $opts['category'] ?? null, 'description' => implode("\n", $lines),
                'assigned_to' => $opts['assigned_to'], 'created_by' => $actorId, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('crm_ticket_comments')->insert(['ticket_id' => $id, 'sub_institute_id' => $tenant, 'comment' => '[Automation] Onboarding ticket opened after the sale.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });
        $t = DB::table('crm_tickets')->find($id, ['ticket_no', 'title', 'priority']);
        GtmAudit::record('crm.ticket.created', $tenant, 'crm_tickets', $id, $actorId, ['ticket_no' => $t->ticket_no, 'title' => $t->title, 'priority' => $t->priority, 'organization_id' => (int) $org->id, 'source' => 'onboarding'], null, "crm.ticket.created:{$tenant}:{$id}");

        return ['ok' => true, 'ticket_id' => $id];
    }

    /**
     * The numbers of the records a sale is made of, found by walking the real relations in both directions.
     *
     * @param  array{opportunity?: int, quote?: int, sales_order?: int, invoice?: int}  $ids
     * @return array{organization_id: ?int, contact_id: ?int, sources: list<string>}
     */
    public static function sourcesFor(int $tenant, array $ids): array
    {
        $oppId = $ids['opportunity'] ?? null; $quoteIds = isset($ids['quote']) ? [$ids['quote']] : []; $soIds = isset($ids['sales_order']) ? [$ids['sales_order']] : []; $invIds = isset($ids['invoice']) ? [$ids['invoice']] : [];
        $orgId = null; $contactId = null;
        if ($invIds) {
            $inv = DB::table('crm_invoices')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($invIds[0]);
            $orgId = $inv?->organization_id; $contactId = $inv?->contact_id;
            if ($inv?->sales_order_id) { $soIds[] = (int) $inv->sales_order_id; }
        }
        if ($soIds) {
            foreach (DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('id', $soIds)->get() as $so) {
                $orgId ??= $so->organization_id; $contactId ??= $so->contact_id; $oppId ??= $so->opportunity_id;
                if ($so->quote_id) { $quoteIds[] = (int) $so->quote_id; }
            }
        }
        if ($quoteIds) {
            foreach (DB::table('crm_quotes')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('id', $quoteIds)->get() as $q) {
                $orgId ??= $q->organization_id; $contactId ??= $q->contact_id; $oppId ??= $q->opportunity_id;
            }
        }
        $opp = $oppId ? DB::table('crm_opportunities')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($oppId) : null;
        if ($opp) {
            $orgId ??= $opp->organization_id;
            $contactId ??= DB::table('crm_opportunity_contacts')->where('opportunity_id', $opp->id)->orderBy('id')->value('contact_id');
            $quoteIds = array_merge($quoteIds, DB::table('crm_quotes')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('opportunity_id', $opp->id)->pluck('id')->all());
            $soIds = array_merge($soIds, DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('opportunity_id', $opp->id)->pluck('id')->all());
        }
        $soIds = array_values(array_unique($soIds));
        $invIds = array_values(array_unique(array_merge($invIds, $soIds ? DB::table('crm_invoices')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('sales_order_id', $soIds)->pluck('id')->all() : [])));
        $num = fn (string $table, string $col, array $ids) => $ids ? DB::table($table)->where('sub_institute_id', $tenant)->whereIn('id', array_values(array_unique($ids)))->pluck($col)->all() : [];
        $sources = array_filter([
            $opp ? "Opportunity {$opp->opportunity_no}" : null,
            ...array_map(fn ($n) => "Quote {$n}", $num('crm_quotes', 'quote_no', $quoteIds)),
            ...array_map(fn ($n) => "Sales Order {$n}", $num('crm_sales_orders', 'so_no', $soIds)),
            ...array_map(fn ($n) => "Invoice {$n}", $num('crm_invoices', 'invoice_no', $invIds)),
        ]);

        return ['organization_id' => $orgId ? (int) $orgId : null, 'contact_id' => $contactId ? (int) $contactId : null, 'sources' => array_values($sources)];
    }
}
