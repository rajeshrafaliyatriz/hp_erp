<?php

namespace App\Domain\Intelligence\Detectors\Cross;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Detectors\Commercial\CommercialAccess;
use App\Domain\Intelligence\Detectors\Commercial\CustomerRenewalDetector;
use App\Domain\Intelligence\Detectors\Commercial\InvoiceAttentionDetector;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * CUSTOMER RENEWAL ATTENTION AND OVERDUE INVOICES (cross.renewal_invoice_overlap).
 *
 * THE RELATIONSHIP (every key read from a migration):
 *   gtm_accounts.crm_organization_id = crm_organizations.id          (2026_11_24_081100: "the one deliberate GTM <-> CRM connection point", nullable, foreign key)
 *   crm_invoices.organization_id     = crm_organizations.id          (2026_11_24_082800, nullable, foreign key)
 * so an account and an invoice are linked when they point at the SAME organisation record. THE ORGANISATION IS ENFORCED ON ALL FOUR TABLES: the account, the
 * organisation, the invoice (and the renewal or snapshot that put the account in the first set) must each carry the caller's `sub_institute_id`. An account
 * whose `crm_organization_id` names an organisation of another tenant is NOT linked; neither is an invoice of another tenant that names the same organisation id.
 *
 * THE TWO SETS (definitions are the source detectors', reused, not copied):
 *   NEEDS RENEWAL ATTENTION: a live account (not deleted) that has an open renewal (upcoming or in progress) dated on or before today + renewal_alert_days
 *     (CustomerRenewalDetector::applyNeedsActionRule), OR is in the at-risk band on the latest health snapshot day (the same live-account snapshot join,
 *     CustomerRenewalDetector::liveSnapshots, and the same staleness limit, SNAPSHOT_STALE_DAYS: a snapshot older than that is not current health and is not used).
 *   OVERDUE INVOICE: InvoiceAttentionDetector::applyOverdueRule, the one shared overdue rule (like the source, no stage filter).
 *
 * ELIGIBLE / LINKED: eligible accounts are those needing renewal attention; LINKED ones also have a verified live CRM organisation of the same tenant. An
 * account with no organisation link cannot be compared with invoices and is not assessed (the coverage basis says so). The overlap is the linked accounts whose
 * organisation has at least one overdue invoice.
 *
 * AUTHORISATION: view on BOTH /gtm/customers and /module/crm/sales/invoices (the intersection of the two source detectors).
 *
 * OUTPUT: counts of accounts and of invoices. NO amounts: invoice money is per currency in the source detector and is never added up here. No names.
 * Organisation data, not people data: `population` is null and the small-group rule does not apply; executives and auditors get no record references.
 */
final class RenewalInvoiceOverlapDetector extends AbstractCrossDetector
{
    private const LINK = CommercialAccess::LINK_GTM_CUSTOMERS;

    public function id(): string
    {
        return 'cross.renewal_invoice_overlap';
    }

    public function title(): string
    {
        return 'Customer renewal attention and overdue invoices';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_GTM_CUSTOMERS, Taxonomy::SURFACE_CRM_INVOICES];
    }

    protected function sources(): array
    {
        return [new CustomerRenewalDetector($this->db, $this->thresholds), new InvoiceAttentionDetector($this->db, $this->thresholds)];
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('gtm_accounts', ['id', 'sub_institute_id', 'crm_organization_id', 'deleted_at'])
            || ! $this->canRead('gtm_renewals', ['id', 'account_id', 'status', 'renewal_date', 'sub_institute_id', 'deleted_at'])
            || ! $this->canRead('gtm_health_snapshots', ['id', 'account_id', 'snapshot_date', 'band', 'sub_institute_id'])
            || ! $this->canRead('crm_invoices', ['id', 'organization_id', 'due_date', 'total', 'amount_paid', 'sub_institute_id', 'deleted_at'])
            || ! $this->canRead('crm_organizations', ['id', 'sub_institute_id', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Customer accounts, renewals, invoices or their organisation link are not available for this organisation.');
        }

        $tenant = $caller->tenantId;
        $today = $clock->today();

        // The invoice side must itself be assessable (the same gate as the invoice detector): otherwise "no overlap" would rest on a handful of invoices.
        $withDue = (int) $this->db->table('crm_invoices')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereNotNull('due_date')->count();
        $invoiceGate = Coverage::of($withDue, null, 'invoices that have a due date', $this->thresholds->int('invoices_min'));
        if (! $invoiceGate->sufficient) {
            return DetectorResult::insufficient($this, $invoiceGate, $asOf);
        }

        $dueUntil = $clock->daysAhead($this->thresholds->int('renewal_alert_days'));
        $renewals = new CustomerRenewalDetector($this->db, $this->thresholds);
        $latestDay = $renewals->liveSnapshots($tenant)->max('s.snapshot_date');
        $healthUsable = $latestDay !== null && (string) $latestDay >= $clock->daysAgo(CustomerRenewalDetector::SNAPSHOT_STALE_DAYS);
        $staleNote = null;
        if ($latestDay !== null && ! $healthUsable) {
            $age = (int) CarbonImmutable::parse((string) $latestDay, 'UTC')->startOfDay()->diffInDays($clock->instant()->startOfDay(), true);
            $staleNote = sprintf('The latest health snapshot is %d days old, so the health band was not used.', $age);
        }

        $attention = fn (): Builder => $this->db->table('gtm_accounts as a')
            ->where('a.sub_institute_id', $tenant)
            ->whereNull('a.deleted_at')
            ->where(function ($q) use ($tenant, $dueUntil, $healthUsable, $latestDay): void {
                $q->whereExists(function ($sub) use ($tenant, $dueUntil): void {
                    $sub->selectRaw('1')->from('gtm_renewals as r')
                        ->whereColumn('r.account_id', 'a.id')
                        ->where('r.sub_institute_id', $tenant)
                        ->whereNull('r.deleted_at');
                    CustomerRenewalDetector::applyNeedsActionRule($sub, $dueUntil, 'r.');
                });
                if ($healthUsable) {
                    $q->orWhereExists(function ($sub) use ($tenant, $latestDay): void {
                        $sub->selectRaw('1')->from('gtm_health_snapshots as s')
                            ->whereColumn('s.account_id', 'a.id')
                            ->where('s.sub_institute_id', $tenant)
                            ->where('s.snapshot_date', $latestDay)
                            ->where('s.band', 'at_risk');
                    });
                }
            });

        // THE LINK, part one: a live organisation of the same tenant. A NULL link never matches.
        $linked = fn (): Builder => $attention()->whereExists(function ($sub) use ($tenant): void {
            $sub->selectRaw('1')->from('crm_organizations as o')
                ->whereColumn('o.id', 'a.crm_organization_id')
                ->where('o.sub_institute_id', $tenant)
                ->whereNull('o.deleted_at');
        });

        $attentionN = (int) $attention()->count();
        $linkedN = (int) $linked()->count();
        $minimum = $this->thresholds->int('cross_renewal_min_linked');
        $basis = 'customer accounts needing renewal attention that are linked to a CRM organisation (an account with no organisation link cannot be compared with invoices and is not assessed)';
        $sufficient = $linkedN >= $minimum;
        $reason = null;
        if (! $sufficient) {
            $reason = $attentionN === 0
                ? 'No customer account currently needs renewal attention, so there is nothing to compare with invoices.'
                : sprintf('Only %d account(s) needing renewal attention are linked to a CRM organisation; at least %d are needed before they are compared with invoices.', $linkedN, $minimum);
        } elseif ($staleNote !== null) {
            $reason = $staleNote;
        }
        $coverage = new Coverage($linkedN, $attentionN, $basis, $sufficient, $reason);
        if (! $sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        // THE LINK, part two: an overdue invoice of the same tenant on the same organisation record.
        $overlap = function () use ($linked, $tenant, $today): Builder {
            $query = $linked()->join('crm_invoices as i', function ($join) use ($tenant): void {
                $join->on('i.organization_id', '=', 'a.crm_organization_id')->where('i.sub_institute_id', '=', $tenant);
            })->whereNull('i.deleted_at');

            return InvoiceAttentionDetector::applyOverdueRule($query, $today, 'i.');
        };

        $row = $overlap()->selectRaw('COUNT(DISTINCT a.id) AS accounts, COUNT(DISTINCT i.id) AS invoices')->first();
        $overlapN = (int) ($row->accounts ?? 0);
        $invoicesN = (int) ($row->invoices ?? 0);
        if ($overlapN === 0) {
            return DetectorResult::ok($this, [], $coverage, $asOf);
        }

        $alertDays = $this->thresholds->int('renewal_alert_days');
        $evidence = [
            Evidence::metric('Accounts needing renewal attention', $attentionN, 'accounts', 'gtm_accounts'),
            Evidence::metric('Of these, linked to a CRM organisation', $linkedN, 'accounts', 'crm_organizations'),
            Evidence::metric('Not assessed: no CRM organisation link', $attentionN - $linkedN, 'accounts'),
            Evidence::metric('Linked accounts whose organisation also has an overdue invoice', $overlapN, 'accounts', 'crm_invoices'),
            Evidence::metric('Overdue invoices on those organisations (a count only; no amounts are combined)', $invoicesN, 'invoices'),
            Evidence::metric('Renewal attention rule', sprintf('an open renewal dated on or before %s (%d days ahead)%s', $dueUntil, $alertDays, $healthUsable ? ', or the at-risk band on the latest health snapshot' : '')),
            Evidence::metric('Link used', 'the CRM organisation recorded on the account is the organisation on the invoice, with the organisation matched on every table'),
            Evidence::window('Renewal dates up to', $today, $dueUntil),
        ];
        if ($staleNote !== null) {
            $evidence[] = Evidence::metric('Health band', $staleNote);
        }
        if ($caller->mayReferenceIndividuals()) {
            foreach ($overlap()->select('a.id')->distinct()->orderBy('a.id')->limit(10)->pluck('a.id') as $id) {
                $evidence[] = Evidence::entity('Account in the overlap', 'gtm_account', (int) $id);
            }
        }

        $share = $this->pct($overlapN, $linkedN);

        return DetectorResult::ok($this, [new Insight(
            key: $this->keyFor('renewal_invoice', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'cross_renewal_invoice',
            severity: $share >= $this->thresholds->float('cross_attention_pct') ? 'attention' : 'info',
            title: 'Accounts needing renewal attention and accounts with overdue invoices overlap',
            whatHappened: sprintf('%d of the %d customer account(s) that need renewal attention and are linked to a CRM organisation also have at least one overdue invoice on that organisation (%d overdue invoice(s) in all).', $overlapN, $linkedN, $invoicesN),
            whyItMatters: 'This is a possible relationship requiring verification, not a finding. The two sets are joined only by the CRM organisation recorded on the account. ' . self::NO_CAUSE,
            evidence: $evidence, coverage: $coverage, asOf: $asOf,
            suggestedAction: 'Open Customers, take the accounts that appear in both lists, and review their renewal record and their invoices on the Invoices screen side by side before drawing any conclusion.',
            deepLink: self::LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: 'organization',
            surfaces: $this->surfaces(), population: null,
        )], $coverage, $asOf);
    }
}
