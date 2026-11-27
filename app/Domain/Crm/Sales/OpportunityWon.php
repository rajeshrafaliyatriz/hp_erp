<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Crm\Leads\ConversionRecords;
use App\Domain\Gtm\GtmAudit;
use App\Domain\OrgAccounts\OrgEnsure;
use App\Domain\OrgAccounts\OrgIdentity;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * THE CUSTOMER CONVERTS (27 Nov 2026; owner: "the converted customer goes into the Organization module"). Until an opportunity is won there is no Organization: a lead becomes an
 * opportunity under the company's name, and a prospect lives in Market Signals > Outreach. When the opportunity moves to Closed Won this makes the company a customer:
 *
 *   1. it finds the Organization with exactly the company's name, or creates it (ConversionRecords::organization, the writer Convert Lead has always used),
 *   2. it sets the opportunity's organization,
 *   3. it files the people on the opportunity (and the lead's contact) under that Organization, where they had none,
 *   4. it stamps the lead with the Organization it became (never replacing one),
 *   5. it makes the organization's identity a customer (OrgEnsure, raised on the ladder, never lowered; an archived one stays archived).
 *
 * Only the TRANSITION into Closed Won acts (afterChange): saving an old won opportunity again never creates anything, and existing organizations and opportunities are untouched.
 * Idempotent (an opportunity that already has its organization only has the people and the identity checked again), one transaction, and it never throws: a failure is logged and the
 * win itself stands, so a person is never blocked from closing a deal. Called AFTER the opportunity row is written at every place a stage can change (the controller's update and
 * stage change, the CSV import, the GTM deal sync, the two conversions that can start at a stage), because the history writer runs before the row does.
 */
final class OpportunityWon
{
    public const WON = 'Closed Won';

    /** Whether going from $from to $to is the win this reacts to. */
    public static function isWin(?string $from, ?string $to): bool
    {
        return $to !== null && trim($to) === self::WON && trim((string) $from) !== self::WON;
    }

    /** The one call every writer makes after the row is saved. Does nothing unless the stage just became Closed Won. @return ?array<string, mixed> what was done, or null */
    public static function afterChange(int $tenant, int $opportunityId, ?string $from, ?string $to, ?int $actorId): ?array
    {
        return self::isWin($from, $to) ? self::apply($tenant, $opportunityId, $actorId) : null;
    }

    /**
     * @return array{organization_id: int, organization_created: bool, people_linked: int}|null null when there was nothing to do or nothing to name the customer by
     */
    public static function apply(int $tenant, int $opportunityId, ?int $actorId): ?array
    {
        try {
            if (! Schema::hasTable('crm_opportunities') || ! Schema::hasTable('crm_organizations')) {
                return null;
            }

            return DB::transaction(function () use ($tenant, $opportunityId, $actorId) {
                $opp = DB::table('crm_opportunities')->where('sub_institute_id', $tenant)->where('id', $opportunityId)->whereNull('deleted_at')->lockForUpdate()->first();
                if (! $opp) {
                    return null;
                }
                $lead = Schema::hasTable('crm_leads')
                    ? DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('converted_opportunity_id', $opportunityId)->whereNull('deleted_at')->first()
                    : null;
                $organizationId = $opp->organization_id ? (int) $opp->organization_id : null;
                $created = false;

                if ($organizationId === null) {
                    $source = self::source($tenant, $opp, $lead);
                    if ($source['name'] === null) {
                        Log::info('crm.opportunity.won_without_a_name', ['opportunity' => $opportunityId]);

                        return null;
                    }
                    $owner = (int) ($opp->assigned_to ?: ($actorId ?: ($opp->created_by ?: 0)));
                    $made = ConversionRecords::organization($tenant, (int) ($actorId ?: 0), $owner, $source['name'], $source['facts']);
                    $organizationId = $made['id'];
                    $created = $made['fresh'];
                    DB::table('crm_opportunities')->where('id', $opportunityId)->update(['organization_id' => $organizationId, 'updated_at' => now()]);
                }

                $linked = self::linkPeople($tenant, $opportunityId, $organizationId, $lead);
                if ($lead && ! $lead->converted_organization_id) {
                    DB::table('crm_leads')->where('id', $lead->id)->update(['converted_organization_id' => $organizationId, 'updated_at' => now()]);
                }
                self::makeCustomer($tenant, $organizationId);

                GtmAudit::record('crm.opportunity.customer_converted', $tenant, 'crm_opportunities', $opportunityId, $actorId,
                    ['organization_id' => $organizationId, 'organization_created' => $created, 'people_linked' => $linked, 'lead_id' => $lead->id ?? null], null, "crm.opportunity.customer_converted:{$tenant}:{$opportunityId}");

                return ['organization_id' => $organizationId, 'organization_created' => $created, 'people_linked' => $linked];
            });
        } catch (\Throwable $e) {
            // The deal is won whatever happens here; the organization can be made by hand.
            Log::warning('crm.opportunity.customer_conversion_failed', ['opportunity' => $opportunityId, 'error' => class_basename($e), 'detail' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * What names the customer and what is known about it: the lead's company, else the signal's company, else the opportunity's own name without its "- Opportunity" tail.
     *
     * @return array{name: ?string, facts: array<string, mixed>}
     */
    private static function source(int $tenant, object $opp, ?object $lead): array
    {
        $facts = [];
        $name = $lead && trim((string) $lead->company) !== '' ? trim((string) $lead->company) : null;
        if ($lead) {
            $facts = array_filter([
                'industry' => $lead->industry ?? null, 'phone' => $lead->phone ?? null, 'fax' => $lead->fax ?? null, 'rating' => $lead->rating ?? null, 'website' => $lead->website ?? null,
                'billing_city' => $lead->city ?? null, 'billing_state' => $lead->state ?? null, 'billing_country' => $lead->country ?? null, 'billing_code' => $lead->postal_code ?? null,
                'billing_street' => $lead->street ?? null, 'billing_po_box' => $lead->po_box ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }
        $signalId = (int) ($opp->source_company_opportunity_id ?? 0);
        if ($signalId > 0 && Schema::hasTable('g2g_company_opportunities')) {
            // `phone` exists only on a host that has run the Outreach migration: read what is there, never assume (a missing column must not cost the customer its organization).
            $present = ColumnExists::all('g2g_companies');
            $company = DB::table('g2g_company_opportunities as o')->join('g2g_companies as c', 'c.id', '=', 'o.company_id')->where('o.sub_institute_id', $tenant)->where('o.id', $signalId)
                ->first(array_map(fn ($c) => "c.{$c}", array_values(array_intersect(['name', 'industry', 'website', 'phone'], $present))));
            if ($company) {
                $name ??= trim((string) ($company->name ?? '')) ?: null;
                $facts += array_filter(['industry' => $company->industry ?? null, 'website' => ($company->website ?? null) ? mb_substr((string) $company->website, 0, 191) : null, 'phone' => $company->phone ?? null], fn ($v) => $v !== null && $v !== '');
            }
        }
        $name ??= trim((string) preg_replace('/\s+-\s+Opportunity$/u', '', (string) $opp->name)) ?: null;

        return ['name' => $name !== null ? mb_substr($name, 0, 191) : null, 'facts' => $facts];
    }

    /** Files the opportunity's people (and the lead's contact) under the organization, where they have no organization yet. Returns how many were filed. */
    private static function linkPeople(int $tenant, int $opportunityId, int $organizationId, ?object $lead): int
    {
        if (! Schema::hasTable('crm_contacts')) {
            return 0;
        }
        $ids = Schema::hasTable('crm_opportunity_contacts') ? DB::table('crm_opportunity_contacts')->where('opportunity_id', $opportunityId)->pluck('contact_id')->map(fn ($i) => (int) $i)->all() : [];
        if ($lead && $lead->converted_contact_id) {
            $ids[] = (int) $lead->converted_contact_id;
        }
        if ($ids === []) {
            return 0;
        }

        return (int) DB::table('crm_contacts')->where('sub_institute_id', $tenant)->whereIn('id', array_unique($ids))->whereNull('organization_id')->whereNull('deleted_at')
            ->update(['organization_id' => $organizationId, 'updated_at' => now()]);
    }

    /** The organization's identity is a customer from now on (never lowered, and an archived one is a person's decision and stays). Bookkeeping: never fails the win. */
    private static function makeCustomer(int $tenant, int $organizationId): void
    {
        try {
            $ensured = app(OrgEnsure::class)->forCrmOrganization($tenant, $organizationId);
            $account = $ensured['account'] ?? null;
            if ($account === null) {
                return;
            }
            $raised = OrgIdentity::raise((string) $account->lifecycle, 'customer');
            if ($raised !== $account->lifecycle) {
                $account->lifecycle = $raised;
                $account->save();
            }
        } catch (\Throwable $e) {
            Log::warning('crm.opportunity.customer_identity_failed', ['organization' => $organizationId, 'error' => class_basename($e)]);
        }
    }
}
