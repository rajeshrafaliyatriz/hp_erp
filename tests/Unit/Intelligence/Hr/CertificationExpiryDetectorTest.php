<?php

namespace Tests\Unit\Intelligence\Hr;

use App\Domain\Intelligence\Detectors\Hr\CertificationExpiryDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;
use Tests\Intelligence\Fixtures\HrTables;
use Tests\Intelligence\IntelligenceTestCase;

class CertificationExpiryDetectorTest extends IntelligenceTestCase
{
    use HrTables;

    // Fixed today = 2026-11-21. due window: to 2026-12-21 (30 d). soon window: to 2027-02-19 (90 d).
    protected function setUp(): void
    {
        parent::setUp();
        $this->createPeopleTables();
        $this->createHrTables();
    }

    private function detector(array $t = ['certification_min_records' => 3]): CertificationExpiryDetector
    {
        return new CertificationExpiryDetector($this->db(), Thresholds::with($t));
    }

    private function cert(?int $user, ?string $expiry, string $status = 'valid', int $tenant = 1, array $extra = []): int
    {
        return (int) $this->db()->table('s_competency_certifications')->insertGetId(array_merge(
            ['sub_institute_id' => $tenant, 'user_id' => $user, 'status' => $status, 'expiry_date' => $expiry], $extra,
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

    public function test_buckets_use_the_expiry_date_and_ignore_a_stale_status(): void
    {
        $this->people(6);
        $this->cert(100, '2026-10-01', 'valid');          // lapsed, but the status still says valid (the known stale case)
        $this->cert(101, '2026-11-20', 'valid');          // lapsed yesterday
        $this->cert(102, '2026-11-21', 'valid');          // expires today: due, not lapsed
        $this->cert(103, '2026-12-21', 'valid');          // last day of the due window
        $this->cert(104, '2026-12-22', 'valid');          // first day of the soon window
        $this->cert(105, '2027-02-19', 'valid');          // last day of the soon window
        $this->cert(100, '2027-02-20', 'valid');          // beyond 90 days: counted for coverage only
        $this->cert(101, null, 'valid');                  // no expiry date

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(2, $this->metric($by['certification_lapsed'], 'Expired certifications'));
        $this->assertSame(2, $this->metric($by['certification_due'], 'Certifications expire within 30 days'));
        $this->assertSame(2, $this->metric($by['certification_soon'], 'Certifications expire within 90 days'));
        $this->assertSame('high', $by['certification_lapsed']['severity']);
        $this->assertSame('attention', $by['certification_due']['severity']);
        $this->assertSame('info', $by['certification_soon']['severity']);
        $this->assertSame(7, $r->coverage->observed, 'certifications with an expiry date');
        $this->assertSame(8, $r->coverage->expected, 'certifications with a holder in scope');
        $this->assertSame('measured', $by['certification_lapsed']['confidence']);
    }

    public function test_revoked_no_holder_deleted_and_inactive_holders_are_excluded(): void
    {
        $this->people(4);
        $this->insertUser(150, 1, ['status' => 0, 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->insertUser(151, 1, ['terminated_date' => '2026-03-01', 'reporting_manager_id' => 10, 'department_id' => 3]);
        $this->cert(100, '2026-10-01');                                           // counted
        $this->cert(101, '2026-10-01', 'REVOKED');                                // withdrawn, not lapsing (case-insensitive)
        $this->cert(null, '2026-10-01');                                          // nobody to tell
        $this->cert(102, '2026-10-01', extra: ['deleted_at' => '2026-11-01 00:00:00']);
        $this->cert(150, '2026-10-01');                                           // holder inactive
        $this->cert(151, '2026-10-01');                                           // holder terminated
        $this->cert(103, '2027-06-01');                                           // valid, only for coverage
        $this->cert(103, '2027-07-01');

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(1, $this->metric($by['certification_lapsed'], 'Expired certifications'));
        $this->assertSame(3, $r->coverage->expected);
    }

    public function test_lms_certificates_count_but_a_reissued_one_does_not(): void
    {
        $this->people(4);
        $this->cert(100, '2027-06-01');
        $this->cert(101, '2027-06-01');
        $this->insertRow('lms_certificates', ['user_id' => 102, 'status' => 'active', 'expires_at' => '2026-10-05 00:00:00', 'sub_institute_id' => 1]);                    // lapsed
        $this->insertRow('lms_certificates', ['user_id' => 102, 'status' => 'active', 'expires_at' => '2026-10-06 00:00:00', 'superseded_by' => 99, 'sub_institute_id' => 1]); // re-issued: ignored
        $this->insertRow('lms_certificates', ['user_id' => 103, 'status' => 'revoked', 'expires_at' => '2026-10-06 00:00:00', 'sub_institute_id' => 1]);                    // revoked: ignored
        $this->insertRow('lms_certificates', ['user_id' => 103, 'status' => 'active', 'expires_at' => '2026-12-21 18:00:00', 'sub_institute_id' => 1]);                    // due (last day, afternoon)
        $this->insertRow('lms_certificates', ['user_id' => 103, 'status' => 'active', 'expires_at' => null, 'sub_institute_id' => 1]);                                     // never expires

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(1, $this->metric($by['certification_lapsed'], 'Expired certifications'));
        $this->assertSame(1, $this->metric($by['certification_due'], 'Certifications expire within 30 days'));
        $this->assertContains('lms_certificate', $this->entityTypes($by['certification_lapsed']));
    }

    public function test_an_employee_sees_only_their_own_and_a_manager_only_direct_reports(): void
    {
        $this->people(2, firstId: 100, manager: 10);
        $this->people(2, firstId: 120, manager: 88);
        $this->cert(100, '2026-10-01');
        $this->cert(101, '2026-10-02');
        $this->cert(120, '2026-10-03');
        $this->cert(121, '2026-10-04');

        $self = $this->detector()->detect($this->callerAs('employee', user: 100), $this->clock());
        $team = $this->detector(['certification_min_records' => 1])->detect($this->callerAs('reporting_manager', user: 10), $this->clock());

        $this->assertSame(1, $this->metric($this->byCategory($self->insights)['certification_lapsed'], 'Expired certifications'));
        $this->assertStringContainsString('of yours', $this->byCategory($self->insights)['certification_lapsed']['title']);
        $this->assertSame(2, $this->metric($this->byCategory($team->insights)['certification_lapsed'], 'Expired certifications'));
        $this->assertSame('team', $this->byCategory($team->insights)['certification_lapsed']['audience']);
    }

    public function test_self_scope_with_no_certifications_is_not_applicable_not_a_clean_bill(): void
    {
        $this->people(2);

        $r = $this->detector()->detect($this->callerAs('employee', user: 100), $this->clock());

        $this->assertSame(Taxonomy::STATE_NOT_APPLICABLE, $r->state);
    }

    public function test_too_few_records_is_insufficient_at_organisation_scope(): void
    {
        $this->people(3);
        $this->cert(100, '2026-10-01');
        $this->cert(101, '2026-10-01');

        $r = $this->detector(['certification_min_records' => 10])->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $r->state);
        $this->assertSame([], $r->insights);
        $this->assertSame(2, $r->coverage->observed);
    }

    public function test_executive_gets_counts_without_entity_references_hr_gets_them(): void
    {
        $this->people(4);
        foreach ([100, 101, 102, 103] as $u) {
            $this->cert($u, '2026-10-01');
        }

        $exec = $this->byCategory($this->detector()->detect($this->callerAs('executive'), $this->clock())->insights)['certification_lapsed'];
        $hr = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights)['certification_lapsed'];

        $this->assertSame([], $this->entityTypes($exec));
        $this->assertCount(4, $this->entityTypes($hr));
        $this->assertSame(4, $this->metric($exec, 'Expired certifications'));
    }

    public function test_entity_references_are_capped_at_ten(): void
    {
        $this->people(14);
        foreach (range(100, 113) as $u) {
            $this->cert($u, '2026-10-01');
        }

        $i = $this->byCategory($this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->insights)['certification_lapsed'];

        $this->assertCount(10, $this->entityTypes($i));
        $this->assertSame(14, $this->metric($i, 'Expired certifications'));
    }

    public function test_another_tenants_and_null_tenant_certifications_never_count(): void
    {
        $this->people(3);
        $this->people(3, tenant: 2, firstId: 200, manager: 50);
        $this->cert(100, '2026-10-01');
        $this->cert(101, '2026-10-02');
        $this->cert(102, '2027-09-01');
        $this->cert(200, '2026-10-01', tenant: 2);
        $this->cert(100, '2026-10-01', tenant: 2);                                // tenant 2 stamp on a tenant 1 person
        $this->insertRow('lms_certificates', ['user_id' => 100, 'status' => 'active', 'expires_at' => '2026-10-05 00:00:00', 'sub_institute_id' => null]);

        $r = $this->detector()->detect($this->callerAs('hr_manager', tenant: 1), $this->clock());
        $by = $this->byCategory($r->insights);

        $this->assertSame(2, $this->metric($by['certification_lapsed'], 'Expired certifications'));
        $this->assertSame(3, $r->coverage->expected);
    }

    public function test_works_with_only_the_lms_table_and_is_data_unavailable_with_neither(): void
    {
        $this->people(3);
        Schema::drop('s_competency_certifications');
        foreach ([100, 101, 102] as $u) {
            $this->insertRow('lms_certificates', ['user_id' => $u, 'status' => 'active', 'expires_at' => '2026-10-05 00:00:00', 'sub_institute_id' => 1]);
        }
        $this->assertSame(Taxonomy::STATE_OK, $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->state);

        Schema::drop('lms_certificates');
        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $this->detector()->detect($this->callerAs('hr_manager'), $this->clock())->state);
    }

    public function test_a_missing_column_in_one_source_makes_that_source_unavailable_not_an_error(): void
    {
        $this->people(3);
        Schema::drop('lms_certificates');
        Schema::create('lms_certificates', function ($t) {          // a re-issue column that this installation lacks
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->dateTime('expires_at')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
        });
        foreach ([100, 101, 102] as $u) {
            $this->cert($u, '2026-10-01');
        }

        $r = $this->detector()->detect($this->callerAs('hr_manager'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $r->state);
        $this->assertSame(3, $this->metric($this->byCategory($r->insights)['certification_lapsed'], 'Expired certifications'));
    }
}
