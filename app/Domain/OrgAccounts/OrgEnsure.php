<?php

namespace App\Domain\OrgAccounts;

use App\Support\ColumnExists;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Makes sure a CRM organisation has an Organisation 360 identity (slice 3, folded into the Organizations screen).
 *
 * The backfill gives every row that exists on the day it runs an identity; a CRM organisation made afterwards (or imported, or made from a lead) has
 * none until this runs. It is called when such an organisation is created and when its Account 360 tab is opened, so a CRM organisation ALWAYS has one.
 *
 * It decides exactly what the backfill decides, with the same code: OrgResolver::planFrom() over the organisation's rows (an exact name or an exact
 * domain, one-to-one, the places not contradicting, nothing a person turned down) and OrgBackfill::writeGroup() to write the one group that holds
 * this row. Anything softer is never linked here: it stays a suggestion for a person. If nothing matches, the organisation gets an identity of its own.
 *
 * Idempotent: an organisation that already has an identity is returned as it is, except that a GTM account that was made for it after the identity (the
 * opportunity sync does that) is attached to the identity (adoptGtmAccount). Nothing is written to crm_organizations, gtm_accounts or g2g_companies.
 */
final class OrgEnsure
{
    public function __construct(
        private readonly OrgResolver $resolver = new OrgResolver,
        private readonly OrgBackfill $backfill = new OrgBackfill,
    ) {}

    /**
     * @return array{account: OrgAccount, created: bool}|null null when this organisation has no live CRM organisation with that id (missing, deleted, or
     *                                                        another organisation's) or the identity tables do not exist on this connection
     *
     * @throws UniqueConstraintViolationException when another request keeps winning the race; the caller can try again
     */
    public function forCrmOrganization(int $tenantId, int $crmOrganizationId): ?array
    {
        if ($tenantId <= 0 || $crmOrganizationId <= 0 || ! OrgSources::hasIdentityTables()) {
            return null;
        }

        try {
            return $this->once($tenantId, $crmOrganizationId);
        } catch (UniqueConstraintViolationException) {
            // Two requests made the identity at the same moment: the other one won. Read what it made.
            return $this->once($tenantId, $crmOrganizationId);
        }
    }

    /** @return array{account: OrgAccount, created: bool}|null */
    private function once(int $tenantId, int $crmOrganizationId): ?array
    {
        return DB::transaction(function () use ($tenantId, $crmOrganizationId) {
            $key = OrgSources::key(OrgAccount::SOURCE_CRM, $crmOrganizationId);
            // Locked, so two clicks on the same organisation cannot both make one.
            $existing = OrgAccount::query()->where('sub_institute_id', $tenantId)->where('crm_organization_id', $crmOrganizationId)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['account' => $this->adoptGtmAccount($tenantId, $existing), 'created' => false];
            }

            $rows = OrgSources::rows($tenantId);
            if (! isset($rows[$key])) {
                return null;   // deleted, missing, or another organisation's
            }

            $identities = OrgSources::identities($tenantId);
            $plan = $this->resolver->planFrom($rows, $identities, OrgSources::rejections($tenantId));
            $group = $plan->groups[$plan->groupOf[$key]];

            $byId = [];
            foreach ($identities as $identity) {
                $byId[(int) $identity['id']] = $identity;
            }
            $this->backfill->writeGroup($tenantId, $group, $rows, $byId);

            $account = OrgAccount::query()->where('sub_institute_id', $tenantId)->where('crm_organization_id', $crmOrganizationId)->first();
            if ($account === null) {
                throw new \RuntimeException("The identity of CRM organisation {$crmOrganizationId} was not written.");
            }
            // Having a CRM organisation moves an organisation up to engaged, but never down from customer or archived.
            $raised = OrgIdentity::raise($account->lifecycle, 'engaged');
            if ($raised !== $account->lifecycle) {
                $account->lifecycle = $raised;
                $account->save();
            }

            return ['account' => $account, 'created' => true];
        });
    }

    /**
     * An identity made BEFORE its GTM account existed (Account 360 opened first; the opportunity sync made the GTM account afterwards) used to be returned
     * as it was, with an empty GTM pointer, and Account 360 said "No GTM account found" for ever (E2E finding B2, 4 Nov 2026). So an identity with no GTM
     * account is checked, on every call, for a live GTM account of this organisation that now points at its CRM organisation (gtm_accounts.crm_organization_id,
     * the link the sync itself writes) and that no identity holds yet. Only then is the plan run, with the same code as the first view: the existing link
     * attaches the account (basis existing_link), two such accounts or any conflict attach nothing, and a pointer that is there is never replaced.
     * Bookkeeping: whatever goes wrong here is logged and the identity is returned as it was, so opening the tab never fails because of it.
     */
    private function adoptGtmAccount(int $tenantId, OrgAccount $account): OrgAccount
    {
        if ($account->gtm_account_id !== null || ! $this->hasUnheldGtmAccount($tenantId, (int) $account->crm_organization_id)) {
            return $account;
        }

        try {
            $key = OrgSources::key(OrgAccount::SOURCE_CRM, (int) $account->crm_organization_id);
            $rows = OrgSources::rows($tenantId);
            if (! isset($rows[$key])) {
                return $account;
            }

            $identities = OrgSources::identities($tenantId);
            $plan = $this->resolver->planFrom($rows, $identities, OrgSources::rejections($tenantId));
            $byId = [];
            foreach ($identities as $identity) {
                $byId[(int) $identity['id']] = $identity;
            }
            $this->backfill->writeGroup($tenantId, $plan->groups[$plan->groupOf[$key]], $rows, $byId);

            return $account->fresh() ?? $account;
        } catch (\Throwable $e) {
            Log::warning('Organisation 360: a GTM account of this organisation could not be linked to its identity.', [
                'sub_institute_id' => $tenantId, 'crm_organization_id' => $account->crm_organization_id, 'error' => $e->getMessage(),
            ]);

            return $account;
        }
    }

    /** Whether a live GTM account of this organisation points at this CRM organisation and no identity (deleted ones included, their pointers are unique) holds it. */
    private function hasUnheldGtmAccount(int $tenantId, int $crmOrganizationId): bool
    {
        if (! Schema::hasTable('gtm_accounts')) {
            return false;
        }
        $have = array_flip(ColumnExists::all('gtm_accounts'));
        if (! isset($have['crm_organization_id'])) {
            return false;   // a host that has not run the migration that adds the link
        }

        $query = DB::table('gtm_accounts')->where('sub_institute_id', $tenantId)->where('crm_organization_id', $crmOrganizationId);
        if (isset($have['deleted_at'])) {
            $query->whereNull('deleted_at');
        }

        return $query->whereNotIn('id', OrgAccount::withTrashed()->where('sub_institute_id', $tenantId)->whereNotNull('gtm_account_id')->select('gtm_account_id'))->exists();
    }
}
