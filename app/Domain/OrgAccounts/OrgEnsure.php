<?php

namespace App\Domain\OrgAccounts;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
 * Idempotent: an organisation that already has an identity is returned as it is. Nothing is written to crm_organizations, gtm_accounts or g2g_companies.
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
                return ['account' => $existing, 'created' => false];
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
}
