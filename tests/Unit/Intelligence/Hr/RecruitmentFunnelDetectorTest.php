<?php

namespace Tests\Unit\Intelligence\Hr;

use App\Domain\Intelligence\Detectors\Hr\RecruitmentFunnelDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

class RecruitmentFunnelDetectorTest extends IntelligenceTestCase
{
    use HrTables;

    // Fixed today = 2026-11-21; a review older than 14 days means applied before 2026-11-07.
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createHrTables();
    }

    private function detector(array $t = ['recruitment_min_applications' => 4]): RecruitmentFunnelDetector
    {
        return new RecruitmentFunnelDetector($this->db(), Thresholds::with($t));
    }

    private function app(string $status, string $applied = '2026-11-15', int $tenant = 1, array $extra = []): int
    {
        return (int) $this->db()->table('talent_job_applications')->insertGetId(array_merge(
            ['sub_institute_id' => $tenant, 'job_id' => 1, 'status' => $status, 'applied_date' => $applied], $extra,
        ));
    }

    private function byCategory(array $insights): array
    {
        $out = [];
        foreach ($insights as $i) {
            $out[$i->category] = $i->toArray();
        }

        return $out;
    }

    public function test_authorization_is_limited_to_recruitment_and_oversight_roles(): void
    {
        foreach (['hr_manager', 'administrator', 'recruiter', 'executive', 'auditor'] as $role) {
            $this->assertTrue($this->detector()->authorizes($this->callerAs($role)), $role);
        }
        // hr_executive is a DEPARTMENT-scoped role: organisation-wide recruitment data (applicants are not in any department) is not theirs to read.
        foreach (['employee', 'reporting_manager', 'department_head', 'hr_executive'] as $role) {
            $this->assertFalse($this->detector()->authorizes($this->callerAs($role)), $role);
        }
    }

    public function test_stale_reviews_are_found_across_status_spellings_and_the_snapshot_never_claims_conversion(): void
    {
        $stale1 = $this->app('Pending Review', '2026-10-01');
        $stale2 = $this->app('under review', '2026-10-15');
        $this->app('UNDER REVIEW', '2026-11-15');         // under review but recent
        $this->app('Shortlisted', '2026-10-01');           // old but not a review status
        $this->app('Hired', '2026-10-02');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(2, $this->metric($by['recruitment_stale_applications'], 'Applications waiting for review'));
        $this->assertEqualsCanonicalizing([$stale1, $stale2], array_map(fn ($e) => $e['entity_id'], array_filter($by['recruitment_stale_applications']['evidence'], fn ($e) => $e['kind'] === 'entity')));
        $snapshot = $by['recruitment_snapshot'];
        $this->assertSame('info', $snapshot['severity']);
        $this->assertSame(2, $this->metric($snapshot, 'Currently under review'), 'status text is normalised before counting');
        $this->assertSame(1, $this->metric($snapshot, 'Currently hired'));
        $this->assertStringContainsString('not a conversion rate', $snapshot['why_it_matters']);
        $this->assertSame('/module/talent-management/recruitment', $snapshot['deep_link']);
    }

    public function test_offers_sent_and_past_expiry_are_flagged(): void
    {
        foreach (range(1, 4) as $i) {
            $this->app('Applied');
        }
        $this->insertRow('talent_offers', ['application_id' => 1, 'status' => 'sent', 'expires_at' => '2026-11-01', 'sub_institute_id' => 1]);       // lapsed
        $this->insertRow('talent_offers', ['application_id' => 2, 'status' => 'SENT', 'expires_at' => '2026-11-20', 'sub_institute_id' => 1]);       // lapsed (spelling)
        $this->insertRow('talent_offers', ['application_id' => 3, 'status' => 'sent', 'expires_at' => '2026-11-21', 'sub_institute_id' => 1]);       // expires today: not past
        $this->insertRow('talent_offers', ['application_id' => 4, 'status' => 'rejected', 'expires_at' => '2026-11-01', 'sub_institute_id' => 1]);   // decided
        $this->insertRow('talent_offers', ['application_id' => 4, 'status' => 'sent', 'expires_at' => '2026-11-01', 'sub_institute_id' => 2]);       // another tenant

        $by = $this->byCategory($this->detector()->detect($this->callerAs('recruiter'), $this->clock())->insights);

        $this->assertSame(2, $this->metric($by['recruitment_offers_lapsed'], 'Offers sent and past'));
        $this->assertCount(2, $this->entityTypes($by['recruitment_offers_lapsed']));
    }

    public function test_executives_and_auditors_get_counts_but_no_record_references(): void
    {
        foreach (range(1, 4) as $i) {
            $this->app('Pending Review', '2026-10-01');
        }

        foreach (['executive', 'auditor'] as $role) {
            $by = $this->byCategory($this->detector()->detect($this->callerAs($role), $this->clock())->insights);
            $this->assertSame(4, $this->metric($by['recruitment_stale_applications'], 'Applications waiting for review'), $role);
            $this->assertSame([], $this->entityTypes($by['recruitment_stale_applications']), $role);
        }
    }

    public function test_the_population_of_an_insight_is_its_application_count_so_a_tiny_group_is_suppressible(): void
    {
        foreach (range(1, 4) as $i) {
            $this->app($i === 1 ? 'Pending Review' : 'Applied', '2026-10-01');
        }

        $by = $this->byCategory($this->detector()->detect($this->callerAs('executive'), $this->clock())->insights);

        $insight = collect($this->detector()->detect($this->callerAs('executive'), $this->clock())->insights)->firstWhere('category', 'recruitment_stale_applications');
        $this->assertSame(1, $insight->population);
        $this->assertArrayNotHasKey('population', $by['recruitment_stale_applications']);
    }

    public function test_too_few_applications_is_insufficient(): void
    {
        $this->app('Pending Review', '2026-10-01');

        $r = $this->detector(['recruitment_min_applications' => 15])->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(1, $r->coverage->observed);
        $this->assertSame([], $r->insights);
    }

    public function test_applications_outside_the_window_and_other_tenants_never_count(): void
    {
        $this->app('Pending Review', '2024-01-01');                                  // outside the 365-day window
        $this->app('Pending Review', '2026-10-01', tenant: 2);                       // another tenant
        $this->app('Pending Review', '2026-10-01', tenant: 1, extra: ['sub_institute_id' => null]);
        $this->app('Pending Review', '2026-10-01', extra: ['deleted_at' => '2026-11-01 00:00:00']);
        foreach (range(1, 3) as $i) {
            $this->app('Applied', '2026-11-10');
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager', tenant: 1), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame(3, $r->coverage->observed);
    }

    public function test_it_still_works_when_the_installation_has_no_deleted_at_column(): void
    {
        Schema::drop('talent_offers');
        Schema::drop('talent_job_applications');
        $this->createRecruitmentTables(false);
        foreach (range(1, 4) as $i) {
            $this->app('Pending Review', '2026-10-01');
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(4, $this->metric($this->byCategory($r->insights)['recruitment_stale_applications'], 'Applications waiting for review'));
    }

    public function test_a_missing_applications_table_is_data_unavailable_and_a_missing_offers_table_only_skips_offers(): void
    {
        foreach (range(1, 4) as $i) {
            $this->app('Pending Review', '2026-10-01');
        }
        Schema::drop('talent_offers');
        $this->assertSame(Taxonomy::STATE_OK, $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->state);

        Schema::drop('talent_job_applications');
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->state);
    }

    public function test_nothing_waiting_gives_only_the_snapshot_and_never_an_ai_estimate(): void
    {
        foreach (range(1, 5) as $i) {
            $this->app('Applied', '2026-11-18');
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(['recruitment_snapshot'], array_map(fn ($i) => $i->category, $r->insights));
        foreach ($r->insights as $i) {
            $this->assertNotSame(Taxonomy::CONFIDENCE_AI_ESTIMATE, $i->confidence);
            $this->assertSame('organization', $i->audience);
        }
    }

    /** H1: an offer with an outcome is not "past expiry with no outcome". Acceptance is recorded only in talent_offer_acceptances. */
    public function test_an_accepted_or_declined_offer_past_expiry_is_not_flagged_but_a_pending_one_is(): void
    {
        $apps = [];
        for ($i = 0; $i < 12; $i++) {
            $apps[$i] = $this->app('Applied');
        }
        $offer = fn (int $app, int $tenant = 1): int => (int) $this->db()->table('talent_offers')->insertGetId(
            ['application_id' => $app, 'status' => 'sent', 'expires_at' => '2026-11-01', 'sub_institute_id' => $tenant],
        );
        $decide = fn (int $offerId, string $decision, int $tenant = 1, ?string $deleted = null) => $this->insertRow('talent_offer_acceptances',
            ['sub_institute_id' => $tenant, 'offer_id' => $offerId, 'decision' => $decision, 'deleted_at' => $deleted]);

        $accepted = $offer($apps[0]);
        $decide($accepted, 'accepted');
        $declined = $offer($apps[1]);
        $decide($declined, 'DECLINED');                                         // case-insensitive
        $pending = $offer($apps[2]);
        $decide($pending, 'pending');                                           // still no outcome: flagged
        $none = $offer($apps[3]);                                               // no acceptance row at all: flagged
        $acceptedThenDeleted = $offer($apps[4]);
        $decide($acceptedThenDeleted, 'accepted', 1, '2026-11-05 00:00:00');   // a deleted acceptance is no outcome: flagged
        $foreign = $offer($apps[5]);
        $decide($foreign, 'accepted', 2);                                       // another tenant's row with the same offer id: not this tenant's outcome: flagged
        $appDeleted = $offer($apps[6]);
        $this->db()->table('talent_job_applications')->where('id', $apps[6])->update(['deleted_at' => '2026-11-02 00:00:00']);
        $appHired = $offer($apps[7]);
        $this->db()->table('talent_job_applications')->where('id', $apps[7])->update(['status' => 'HIRED']);
        $appRejected = $offer($apps[8]);
        $this->db()->table('talent_job_applications')->where('id', $apps[8])->update(['status' => 'rejected']);

        $by = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights);

        $this->assertSame(4, $this->metric($by['recruitment_offers_lapsed'], 'Offers sent and past'));
        $flagged = array_map(fn ($e) => $e['entity_id'], array_filter($by['recruitment_offers_lapsed']['evidence'], fn ($e) => $e['kind'] === 'entity'));
        $this->assertEqualsCanonicalizing([$pending, $none, $acceptedThenDeleted, $foreign], $flagged);
        $this->assertNotContains($accepted, $flagged);
        $this->assertNotContains($declined, $flagged);
        $this->assertNotContains($appDeleted, $flagged);
        $this->assertNotContains($appHired, $flagged);
        $this->assertNotContains($appRejected, $flagged);
    }

    public function test_offers_are_still_assessed_when_the_acceptances_table_is_absent(): void
    {
        foreach (range(1, 4) as $i) {
            $this->app('Applied');
        }
        Schema::drop('talent_offer_acceptances');
        $this->insertRow('talent_offers', ['application_id' => 1, 'status' => 'sent', 'expires_at' => '2026-11-01', 'sub_institute_id' => 1]);

        $by = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights);

        $this->assertSame(1, $this->metric($by['recruitment_offers_lapsed'], 'Offers sent and past'));
    }
}
