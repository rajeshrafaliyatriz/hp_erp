<?php

namespace Tests\Feature;

use App\Domain\Crm\Sales\OpportunityGtmSyncBridge;
use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgEnsure;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * E2E finding B2 (4 Nov 2026): Account 360 said "GTM account: No GTM account found" after the opportunity sync had made the organisation's GTM account,
 * because the identity was made when the tab was first opened (no GTM account then) and was never looked at again. Here the REAL sync (the bridge) makes the
 * GTM account and the REAL endpoint is asked again: the identity must now hold it. The decision rules of the repair (what is attached, what is never taken,
 * what it costs) are pinned in OrgAccountsEnsureTest; this file pins that the two real pieces fit together.
 */
class OrgAccountsGtmSyncLinkTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
    }

    /** @return int the id of the opportunity */
    private function opportunityOf(int $org, string $name = 'Big deal', int $tenant = self::T): int
    {
        return $this->makeOpportunity($tenant, ['name' => $name, 'organization_id' => $org, 'sales_stage' => 'Prospecting', 'amount' => '1000.00', 'currency' => 'INR']);
    }

    public function test_after_the_real_opportunity_sync_the_identity_holds_the_gtm_account_it_made(): void
    {
        $org = $this->makeOrganization(self::T, ['name' => 'Quebec Public School']);
        $identity = (new OrgEnsure)->forCrmOrganization(self::T, $org)['account'];
        $this->assertNull($identity->gtm_account_id, 'the tab was opened before any deal: there is no GTM account yet');

        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $this->opportunityOf($org));
        $gtm = (int) DB::table('gtm_accounts')->where('crm_organization_id', $org)->value('id');
        $this->assertGreaterThan(0, $gtm, 'the sync made the GTM account of the organisation');

        $out = (new OrgEnsure)->forCrmOrganization(self::T, $org);

        $this->assertFalse($out['created']);
        $this->assertSame((int) $identity->id, (int) $out['account']->id);
        $stored = OrgAccount::query()->find($identity->id);
        $this->assertSame([$org, $gtm, 'existing_link'], [$stored->crm_organization_id, $stored->gtm_account_id, $stored->gtm_link_basis]);
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_the_account_360_endpoint_stops_saying_there_is_no_gtm_account_and_shows_its_deal(): void
    {
        $org = $this->makeOrganization(self::T, ['name' => 'Romeo Public School']);
        $viewer = $this->user([self::ORGS_LINK => ['view']]);
        $before = $this->postJson("/api/org-accounts/for-crm/{$org}", [], $viewer['h'])->assertOk();
        $this->assertNull($before->json('data.identity.links.gtm'));
        $this->assertNull($before->json('data.gtm'));

        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $this->opportunityOf($org));
        $gtm = (int) DB::table('gtm_accounts')->where('crm_organization_id', $org)->value('id');
        $after = $this->postJson("/api/org-accounts/for-crm/{$org}", [], $viewer['h'])->assertOk();

        $this->assertSame($before->json('data.identity.id'), $after->json('data.identity.id'), 'the same identity, not a second one');
        $this->assertSame(['id' => $gtm, 'basis' => 'existing_link'], $after->json('data.identity.links.gtm'));
        $this->assertSame([$gtm, 'Romeo Public School', 1], [$after->json('data.gtm.id'), $after->json('data.gtm.name'), $after->json('data.gtm.deals_count')]);
    }

    public function test_a_second_opportunity_of_the_same_organisation_reuses_the_account_and_the_link_stays_as_it_is(): void
    {
        $org = $this->makeOrganization(self::T, ['name' => 'Sierra Public School']);
        (new OrgEnsure)->forCrmOrganization(self::T, $org);
        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $this->opportunityOf($org, 'First'));
        (new OrgEnsure)->forCrmOrganization(self::T, $org);
        $linked = OrgAccount::query()->where('crm_organization_id', $org)->first();

        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $this->opportunityOf($org, 'Second'));
        $again = (new OrgEnsure)->forCrmOrganization(self::T, $org)['account'];

        $this->assertSame(1, DB::table('gtm_accounts')->where('crm_organization_id', $org)->count(), 'one GTM account per organisation');
        $this->assertSame([$linked->gtm_account_id, $linked->gtm_link_basis], [$again->gtm_account_id, $again->gtm_link_basis]);
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_another_organisations_sync_does_not_touch_this_organisations_identity(): void
    {
        $mine = $this->makeOrganization(self::T, ['name' => 'Tango School']);
        $theirs = $this->makeOrganization(self::T2, ['name' => 'Tango School']);
        (new OrgEnsure)->forCrmOrganization(self::T, $mine);
        (new OrgEnsure)->forCrmOrganization(self::T2, $theirs);

        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T2, $this->opportunityOf($theirs, 'Their deal', self::T2));
        $out = (new OrgEnsure)->forCrmOrganization(self::T, $mine);

        $this->assertNull($out['account']->gtm_account_id, 'a GTM account of another organisation is not mine, whatever its name');
        $this->assertNull(OrgAccount::query()->where('crm_organization_id', $mine)->value('gtm_account_id'));
        $this->assertNull(OrgAccount::query()->where('crm_organization_id', $theirs)->value('gtm_account_id'), 'and theirs is only linked when THEY open it');
    }
}
