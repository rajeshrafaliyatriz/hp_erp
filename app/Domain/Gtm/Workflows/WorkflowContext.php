<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\GtmEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads the real facts behind one event, for ONE tenant, into the flat `values` map conditions and templates read.
 * Everything is queried with the event's own sub_institute_id; nothing comes from a request. Returns null when the
 * record the event is about no longer exists (the run is then skipped with that reason, not failed).
 *
 * `ids` holds the entity ids actions need (account, contact, deal, ...). Personal contact details (email, phone,
 * notes) are never loaded into `values`.
 */
final class WorkflowContext
{
    /**
     * @param  array<string, mixed>  $payload  the event's payload (event.* facts)
     * @return array{values: array<string, mixed>, ids: array<string, int>}|null
     */
    public static function load(int $tenant, string $type, ?string $entityType, ?int $entityId, array $payload): ?array
    {
        if (! $entityType || ! $entityId) {
            return null;
        }
        $values = [];
        $ids = [];
        foreach ($payload as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $values["event.{$k}"] = $v;
            }
        }
        $accountId = null;

        switch ($entityType) {
            case 'crm_leads':
                return self::loadLead($tenant, $entityId, $values);
            case 'crm_opportunities':
                return self::loadOpportunity($tenant, $entityId, $values);
            case 'crm_quotes':
                return self::loadQuote($tenant, $entityId, $values);
            case 'crm_sales_orders':
                return self::loadSalesOrder($tenant, $entityId, $values);
            case 'crm_invoices':
                return self::loadInvoice($tenant, $entityId, $values);
            case 'gtm_accounts':
                $accountId = $entityId;
                break;
            case 'gtm_contacts':
                $c = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($entityId);
                if (! $c) {
                    return null;
                }
                $ids['contact'] = (int) $c->id;
                $accountId = (int) $c->account_id;
                $values += ['contact.id' => (int) $c->id, 'contact.full_name' => $c->full_name, 'contact.title' => $c->title, 'contact.role_in_deal' => $c->role_in_deal, 'contact.status' => $c->status, 'contact.has_email' => trim((string) $c->email) !== ''];
                break;
            case 'gtm_deals':
                $d = DB::table('gtm_deals')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($entityId);
                if (! $d) {
                    return null;
                }
                $ids['deal'] = (int) $d->id;
                $accountId = (int) $d->account_id;
                $values += ['deal.id' => (int) $d->id, 'deal.name' => $d->name, 'deal.stage' => $d->stage, 'deal.amount' => $d->amount !== null ? (float) $d->amount : null, 'deal.currency' => $d->currency,
                    'deal.days_in_stage' => $d->stage_changed_at ? (int) Carbon::parse($d->stage_changed_at)->diffInDays(now()) : null, 'deal.has_next_step' => trim((string) $d->next_step) !== '', 'deal.forecast_category' => $d->forecast_category];
                break;
            case 'gtm_outreach_messages':
                $m = DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->find($entityId);
                if (! $m) {
                    return null;
                }
                $ids['message'] = (int) $m->id;
                $ids['contact'] = (int) $m->contact_id;
                $accountId = (int) $m->account_id;
                $values += ['message.id' => (int) $m->id, 'message.status' => $m->status, 'message.step' => (int) $m->step, 'message.has_campaign' => $m->campaign_id !== null];
                $c = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->find($m->contact_id);
                if ($c) {
                    $values += ['contact.id' => (int) $c->id, 'contact.full_name' => $c->full_name, 'contact.title' => $c->title, 'contact.role_in_deal' => $c->role_in_deal, 'contact.status' => $c->status, 'contact.has_email' => trim((string) $c->email) !== ''];
                }
                break;
            case 'gtm_renewals':
                $r = DB::table('gtm_renewals')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($entityId);
                if (! $r) {
                    return null;
                }
                $ids['renewal'] = (int) $r->id;
                $accountId = (int) $r->account_id;
                $values += ['renewal.id' => (int) $r->id, 'renewal.name' => $r->name, 'renewal.status' => $r->status, 'renewal.amount' => $r->amount !== null ? (float) $r->amount : null, 'renewal.currency' => $r->currency,
                    'renewal.days_until' => (int) now()->startOfDay()->diffInDays(Carbon::parse($r->renewal_date)->startOfDay(), false)];
                break;
            default:
                return null;
        }

        $a = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($accountId);
        if (! $a) {
            return null;
        }
        $ids['account'] = (int) $a->id;
        $contacts = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->where('account_id', $a->id)->whereNull('deleted_at')->count();
        $values += ['account.id' => (int) $a->id, 'account.name' => $a->name, 'account.stage' => $a->stage, 'account.industry' => $a->industry, 'account.location' => $a->location,
            'account.icp_fit_score' => $a->icp_fit_score !== null ? (int) $a->icp_fit_score : null, 'account.source' => $a->source, 'account.owner_user_id' => $a->owner_user_id,
            'account.contacts_count' => $contacts, 'account.has_contacts' => $contacts > 0];

        return ['values' => $values, 'ids' => $ids];
    }

    /**
     * The facts of one CRM lead. Email, phone and the description are never exposed: a workflow can ask whether a lead HAS
     * an email, not read it. Duplicates are judged the way Marketing -> Leads' own duplicate finder does (same email, or same
     * name + company; converted and removed leads are not candidates), plus an existing Organization or Contact that already
     * matches, which the lead finder does not look at.
     *
     * @param  array<string, mixed>  $values  event.* facts already collected
     * @return array{values: array<string, mixed>, ids: array<string, int>}|null
     */
    private static function loadLead(int $tenant, int $id, array $values): ?array
    {
        $l = DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($id);
        if (! $l) {
            return null;
        }
        $email = strtolower(trim((string) $l->email));
        $company = strtolower(trim((string) $l->company));
        $last = strtolower(trim((string) $l->last_name));
        $others = fn () => DB::table('crm_leads')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('converted', false)->where('id', '!=', $l->id);

        $dupLeads = $others()->where(function ($q) use ($email, $company, $last) {
            $any = false;
            if ($email !== '') {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                $any = true;
            }
            if ($company !== '') {
                $any ? $q->orWhereRaw('(LOWER(TRIM(last_name)) = ? AND LOWER(TRIM(company)) = ?)', [$last, $company]) : $q->whereRaw('(LOWER(TRIM(last_name)) = ? AND LOWER(TRIM(company)) = ?)', [$last, $company]);
                $any = true;
            }
            if (! $any) {
                $q->whereRaw('1 = 0');
            }
        })->count();
        $dupOrgs = $company === '' ? 0 : self::similarOrganizations($tenant, (string) $l->company, null);
        $dupContacts = $email === '' ? 0 : DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count();

        $gaps = \App\Domain\Crm\Leads\LeadCompleteness::missing($l);
        $hasName = ! in_array('a real contact name', $gaps, true);

        $values += [
            'lead.id' => (int) $l->id, 'lead.lead_no' => $l->lead_no, 'lead.company' => $l->company, 'lead.status' => $l->lead_status, 'lead.score' => (int) $l->lead_score,
            'lead.rating' => $l->rating, 'lead.source' => $l->lead_source, 'lead.industry' => $l->industry, 'lead.assigned_to' => (int) $l->assigned_to,
            'lead.from_signal' => $l->source_company_opportunity_id !== null, 'lead.converted' => (bool) $l->converted, 'lead.email_opt_out' => (bool) $l->email_opt_out,
            'lead.has_contact_name' => $hasName, 'lead.has_email' => $email !== '', 'lead.has_phone' => trim((string) $l->phone) !== '' || trim((string) $l->mobile) !== '',
            'lead.has_company' => $company !== '', 'lead.has_website' => trim((string) $l->website) !== '',
            'lead.converted_organization_id' => $l->converted_organization_id !== null ? (int) $l->converted_organization_id : null, 'lead.converted_contact_id' => $l->converted_contact_id !== null ? (int) $l->converted_contact_id : null,
            'lead.converted_opportunity_id' => $l->converted_opportunity_id !== null ? (int) $l->converted_opportunity_id : null,
            'lead.missing_count' => count($gaps), 'lead.missing_fields' => implode(', ', $gaps),
            'lead.duplicate_leads' => $dupLeads, 'lead.duplicate_organizations' => $dupOrgs, 'lead.duplicate_contacts' => $dupContacts,
            'lead.duplicate_count' => $dupLeads + $dupOrgs + $dupContacts,
        ];

        if ($l->converted) {
            $values += self::convertedFacts($tenant, $l);
        }

        return ['values' => $values, 'ids' => ['lead' => (int) $l->id]];
    }

    /** @param array<string, mixed> $values @return array{values: array<string, mixed>, ids: array<string, int>}|null */
    private static function loadQuote(int $tenant, int $id, array $values): ?array
    {
        $q = DB::table('crm_quotes')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($id);
        if (! $q) {
            return null;
        }
        $contactEmail = $q->contact_id ? trim((string) DB::table('crm_contacts')->where('sub_institute_id', $tenant)->where('id', $q->contact_id)->value('email')) : '';
        $values += [
            'quote.id' => (int) $q->id, 'quote.no' => $q->quote_no, 'quote.subject' => $q->subject, 'quote.stage' => $q->quote_stage, 'quote.total' => (float) $q->total, 'quote.currency' => $q->currency,
            'quote.assigned_to' => $q->assigned_to !== null ? (int) $q->assigned_to : null, 'quote.has_contact' => $q->contact_id !== null, 'quote.contact_has_email' => $contactEmail !== '',
            'quote.lines_count' => DB::table('crm_quote_line_items')->where('quote_id', $q->id)->count(),
            'quote.days_to_expiry' => $q->valid_till ? (int) now()->startOfDay()->diffInDays(Carbon::parse($q->valid_till)->startOfDay(), false) : null,
            'quote.sales_orders_count' => DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->where('quote_id', $q->id)->whereNull('deleted_at')->count(),
        ];
        $ids = ['quote' => (int) $q->id];
        if ($q->opportunity_id) {
            $ids['opportunity'] = (int) $q->opportunity_id;
        }

        return ['values' => $values, 'ids' => $ids];
    }

    /** @param array<string, mixed> $values @return array{values: array<string, mixed>, ids: array<string, int>}|null */
    private static function loadSalesOrder(int $tenant, int $id, array $values): ?array
    {
        $o = DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($id);
        if (! $o) {
            return null;
        }
        $values += [
            'sales_order.id' => (int) $o->id, 'sales_order.no' => $o->so_no, 'sales_order.subject' => $o->subject, 'sales_order.stage' => $o->so_stage, 'sales_order.total' => (float) $o->total,
            'sales_order.currency' => $o->currency, 'sales_order.assigned_to' => $o->assigned_to !== null ? (int) $o->assigned_to : null, 'sales_order.from_quote' => $o->quote_id !== null,
            'sales_order.lines_count' => DB::table('crm_sales_order_line_items')->where('sales_order_id', $o->id)->count(),
            'sales_order.invoices_count' => DB::table('crm_invoices')->where('sub_institute_id', $tenant)->where('sales_order_id', $o->id)->whereNull('deleted_at')->count(),
        ];

        return ['values' => $values, 'ids' => ['sales_order' => (int) $o->id]];
    }

    /** @param array<string, mixed> $values @return array{values: array<string, mixed>, ids: array<string, int>}|null */
    private static function loadInvoice(int $tenant, int $id, array $values): ?array
    {
        $i = DB::table('crm_invoices')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($id);
        if (! $i) {
            return null;
        }
        $total = (float) $i->total;
        $paid = (float) $i->amount_paid;
        $balance = round($total - $paid, 2);
        $values += [
            'invoice.id' => (int) $i->id, 'invoice.no' => $i->invoice_no, 'invoice.subject' => $i->subject, 'invoice.stage' => $i->invoice_stage, 'invoice.total' => $total, 'invoice.amount_paid' => $paid,
            'invoice.balance_due' => $balance, 'invoice.currency' => $i->currency, 'invoice.assigned_to' => $i->assigned_to !== null ? (int) $i->assigned_to : null,
            'invoice.payment_status' => match (true) { $total <= 0.0 || $paid <= 0.0 => 'Unpaid', $balance <= 0.004 => 'Paid', default => 'Partially Paid' },
            'invoice.is_overdue' => $balance > 0.004 && $i->due_date !== null && $i->due_date < now()->toDateString(),
            'invoice.days_overdue' => $i->due_date !== null && $i->due_date < now()->toDateString() ? (int) Carbon::parse($i->due_date)->diffInDays(now()) : 0,
            'invoice.has_due_date' => $i->due_date !== null, 'invoice.from_sales_order' => $i->sales_order_id !== null,
        ];
        $ids = ['invoice' => (int) $i->id];
        if ($i->organization_id) {
            $ids['organization'] = (int) $i->organization_id;
        }

        return ['values' => $values, 'ids' => $ids];
    }
    /**
     * Organizations in the tenant whose name is the same once case, punctuation and company-form words (Inc, Ltd, Pvt, Co, ...)
     * are ignored. The database already refuses two Organizations with exactly the same name, so an exact comparison could never
     * find a duplicate; "Acme Ltd." and "Acme Co" are the ones people really create.
     */
    private static function similarOrganizations(int $tenant, string $name, ?int $excludeId): int
    {
        $key = self::normaliseCompany($name);
        if ($key === '') {
            return 0;
        }
        $first = preg_split('/\s+/', trim($name))[0] ?? '';
        $candidates = DB::table('crm_organizations')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('name', 'like', mb_substr($first, 0, 3).'%')->limit(200)->pluck('name');

        return $candidates->filter(fn ($n) => self::normaliseCompany((string) $n) === $key)->count();
    }

    private static function normaliseCompany(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $n) ?? $n;
        $n = preg_replace('/\b(inc|incorporated|ltd|limited|llc|llp|plc|pvt|private|co|corp|corporation|company|gmbh|sa|bv|ag)\b/u', ' ', $n) ?? $n;

        return preg_replace('/\s+/', '', $n) ?? '';
    }
    /**
     * After a conversion: are the three records actually linked, are there look-alikes, and is the buying side known?
     * Facts only - nothing is changed or filled in. `converted.links_ok` needs the Contact under the Organization, the
     * Opportunity under the Organization and the Contact attached to the Opportunity (each only where it was created).
     *
     * @return array<string, mixed>
     */
    private static function convertedFacts(int $tenant, object $l): array
    {
        $org = $l->converted_organization_id ? DB::table('crm_organizations')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($l->converted_organization_id) : null;
        $con = $l->converted_contact_id ? DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($l->converted_contact_id) : null;
        $opp = $l->converted_opportunity_id ? DB::table('crm_opportunities')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($l->converted_opportunity_id) : null;

        $ok = true;
        $ok = $ok && (! $l->converted_organization_id || $org !== null) && (! $l->converted_contact_id || $con !== null) && (! $l->converted_opportunity_id || $opp !== null);
        $ok = $ok && (! $org || ! $con || (int) $con->organization_id === (int) $org->id);
        $ok = $ok && (! $org || ! $opp || (int) $opp->organization_id === (int) $org->id);
        $ok = $ok && (! $opp || ! $con || DB::table('crm_opportunity_contacts')->where('opportunity_id', $opp->id)->where('contact_id', $con->id)->exists());

        $name = $org ? strtolower(trim((string) $org->name)) : '';
        $email = $con ? strtolower(trim((string) $con->email)) : '';

        return [
            'converted.links_ok' => $ok,
            'converted.organization_duplicates' => $org && $name !== '' ? self::similarOrganizations($tenant, (string) $org->name, (int) $org->id) : 0,
            'converted.contact_duplicates' => $con && $email !== '' ? DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('id', '!=', $con->id)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count() : 0,
            'converted.organization_contacts' => $org ? DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('organization_id', $org->id)->count() : 0,
            'converted.contact_has_title' => $con !== null && trim((string) $con->title) !== '',
        ];
    }

    /**
     * The facts of one CRM Opportunity. `days_since_activity` is the same measure the weekly inactivity check uses. Nothing
     * personal (descriptions, next-step text) is exposed.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>, ids: array<string, int>}|null
     */
    private static function loadOpportunity(int $tenant, int $id, array $values): ?array
    {
        $o = DB::table('crm_opportunities')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->find($id);
        if (! $o) {
            return null;
        }
        $lastStage = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $o->id)->where('to_stage', $o->sales_stage)->max('created_at');
        $values += [
            'opportunity.id' => (int) $o->id, 'opportunity.no' => $o->opportunity_no, 'opportunity.name' => $o->name, 'opportunity.stage' => $o->sales_stage,
            'opportunity.is_open' => ! in_array(strtolower(trim((string) $o->sales_stage)), ['closed won', 'closed lost'], true),
            'opportunity.amount' => $o->amount !== null ? (float) $o->amount : null, 'opportunity.currency' => $o->currency, 'opportunity.probability' => $o->probability !== null ? (float) $o->probability : null,
            'opportunity.days_to_close' => $o->closing_date ? (int) now()->startOfDay()->diffInDays(Carbon::parse($o->closing_date)->startOfDay(), false) : null,
            'opportunity.days_in_stage' => (int) Carbon::parse($lastStage ?: $o->created_at)->diffInDays(now()),
            'opportunity.days_since_activity' => (int) \App\Domain\Crm\Sales\OpportunitySweep::lastActivity($tenant, $o)->diffInDays(now()),
            'opportunity.assigned_to' => $o->assigned_to !== null ? (int) $o->assigned_to : null, 'opportunity.organization_id' => $o->organization_id !== null ? (int) $o->organization_id : null,
            'opportunity.lead_source' => $o->lead_source, 'opportunity.has_next_step' => trim((string) $o->next_step) !== '',
            'opportunity.contacts_count' => DB::table('crm_opportunity_contacts')->where('opportunity_id', $o->id)->count(),
            'opportunity.quotes_count' => DB::table('crm_quotes')->where('sub_institute_id', $tenant)->where('opportunity_id', $o->id)->whereNull('deleted_at')->count(),
            'opportunity.from_lead' => DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('converted_opportunity_id', $o->id)->exists(),
        ];

        return ['values' => $values, 'ids' => ['opportunity' => (int) $o->id]];
    }

    /** Load straight from a stored event row. @return array{values: array<string, mixed>, ids: array<string, int>}|null */
    public static function fromEvent(object $event): ?array
    {
        $payload = json_decode((string) ($event->payload ?? '[]'), true);

        return self::load((int) $event->sub_institute_id, (string) $event->type, $event->entity_type, $event->entity_id !== null ? (int) $event->entity_id : null, is_array($payload) ? $payload : []);
    }

    /** True for an event type a workflow may start from. */
    public static function triggerable(string $type): bool
    {
        return isset(GtmEvents::TYPES[$type]) && GtmEvents::TYPES[$type]['triggerable'];
    }
}
