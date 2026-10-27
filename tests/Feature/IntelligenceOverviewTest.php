<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /api/intelligence/overview, on the in-memory sqlite database with synthetic rows only.
 *
 * Every figure asserted here is derived by hand from the fixtures below, so a pass means the service computes what a
 * person would compute - not merely that it returns something.
 */
class IntelligenceOverviewTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('reporting_manager_id')->nullable();
            $t->integer('status')->default(1);
            $t->date('terminated_date')->nullable();
            $t->softDeletes();
        });
        Schema::create('hrms_departments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('department')->nullable();
        });
        Schema::create('s_user_jobrole', function ($t) {
            $t->id();
            $t->string('jobrole')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('jobrole_competency_map', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('jobrole_id');
            $t->unsignedBigInteger('competency_id');
        });
        Schema::create('competency_kasba_rating', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('kasba_item_id');
            $t->unsignedTinyInteger('rating');
        });
        Schema::create('s_competency_assessments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('sub_std_map', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->integer('status')->default(1);
            $t->softDeletes();
        });
        Schema::create('course_competency_map', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('competency_id');
        });
        Schema::create('s_competency_certifications', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('name');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('status')->default('valid');
            $t->date('expiry_date')->nullable();
            $t->softDeletes();
        });
        Schema::create('capability_course_requests', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('competency_id')->default(1);
            $t->string('competency_name')->nullable();
            $t->unsignedBigInteger('jobrole_id')->nullable();
            $t->unsignedTinyInteger('required_proficiency')->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->unsignedInteger('affected_count')->default(0);
            $t->string('status')->default('open');
            $t->dateTime('first_raised_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('capability_course_request_subjects', function ($t) {
            $t->id();
            $t->unsignedBigInteger('request_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id');
            $t->decimal('gap', 4, 2)->nullable();
        });

        RoleKey::flushCache();
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->ins('tbluserprofilemaster', [
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private int $callerId = 0;

    /** Multi-row insert with every row given every column (Laravel requires identical keys per row). */
    private function ins(string $table, array $rows): void
    {
        $keys = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        DB::table($table)->insert(array_map(fn ($r) => array_merge(array_fill_keys($keys, null), $r), $rows));
    }

    private function headers(int $tenant, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'caller' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);
        $this->callerId = $id;

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function overview(array $headers, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/intelligence/overview?' . http_build_query($query), $headers);
    }

    private function measure(array $json, string $key): array
    {
        foreach ($json['data']['readiness'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
        $this->fail("measure {$key} missing");
    }

    private function insight(array $json, string $key): ?array
    {
        foreach ($json['data']['insights'] as $i) {
            if ($i['key'] === $key) {
                return $i;
            }
        }

        return null;
    }

    /** Tenant 1: a small organisation whose every number can be counted by hand. */
    private function seedTenantOne(): void
    {
        $t = self::T1;

        // 3 roles; 1 mapped (to 2 competencies - still one role). A deleted role must not count.
        $this->ins('s_user_jobrole', [
            ['id' => 1, 'jobrole' => 'A', 'sub_institute_id' => $t], ['id' => 2, 'jobrole' => 'B', 'sub_institute_id' => $t],
            ['id' => 3, 'jobrole' => 'C', 'sub_institute_id' => $t], ['id' => 4, 'jobrole' => 'gone', 'sub_institute_id' => $t, 'deleted_at' => '2026-01-01'],
        ]);
        $this->ins('jobrole_competency_map', [
            ['sub_institute_id' => $t, 'jobrole_id' => 1, 'competency_id' => 10], ['sub_institute_id' => $t, 'jobrole_id' => 1, 'competency_id' => 11],
        ]);

        // Employees: the 3 callers created per test are excluded by giving them status 0 below; real staff:
        //   u1 active+manager+rated, u2 active+manager, u3 active, u4 inactive, u5 terminated, u6 soft-deleted.
        $this->ins('tbluser', [
            ['id' => 101, 'sub_institute_id' => $t, 'status' => 1, 'reporting_manager_id' => 102, 'department_id' => 1],
            ['id' => 102, 'sub_institute_id' => $t, 'status' => 1, 'reporting_manager_id' => 103, 'department_id' => 1],
            ['id' => 103, 'sub_institute_id' => $t, 'status' => 1, 'department_id' => 2],
            ['id' => 104, 'sub_institute_id' => $t, 'status' => 0],
            ['id' => 105, 'sub_institute_id' => $t, 'status' => 1, 'terminated_date' => '2026-01-01'],
            ['id' => 106, 'sub_institute_id' => $t, 'status' => 1, 'deleted_at' => '2026-01-01'],
        ]);
        $this->ins('competency_kasba_rating', [['sub_institute_id' => $t, 'user_id' => 101, 'kasba_item_id' => 1, 'rating' => 3]]);

        // 3 assessments, 2 with an employee; a deleted one must not count.
        $this->ins('s_competency_assessments', [
            ['sub_institute_id' => $t, 'user_id' => 101], ['sub_institute_id' => $t, 'user_id' => 102], ['sub_institute_id' => $t, 'user_id' => null],
            ['sub_institute_id' => $t, 'user_id' => null, 'deleted_at' => '2026-01-01'],
        ]);

        // Courses: 2 active (1 linked); inactive and deleted ones must not count.
        $this->ins('sub_std_map', [
            ['id' => 1, 'sub_institute_id' => $t, 'status' => 1], ['id' => 2, 'sub_institute_id' => $t, 'status' => 1],
            ['id' => 3, 'sub_institute_id' => $t, 'status' => 0], ['id' => 4, 'sub_institute_id' => $t, 'status' => 1, 'deleted_at' => '2026-01-01'],
        ]);
        $this->ins('course_competency_map', [['sub_institute_id' => $t, 'course_id' => 1, 'competency_id' => 10]]);

        $this->ins('hrms_departments', [['id' => 1, 'sub_institute_id' => $t, 'department' => 'Engineering'], ['id' => 2, 'sub_institute_id' => $t, 'department' => 'Finance']]);

        // Certificates relative to "today" 2026-10-20 (60-day window ends 2026-12-19):
        //   expired by DATE while status still 'valid'  -> non-compliant AND counted as stale status
        //   revoked, far-future expiry                  -> non-compliant (revoked)
        //   expires in 10 days                          -> expiring
        //   expires in 200 days                         -> compliant
        //   soft-deleted expired                        -> ignored
        $this->ins('s_competency_certifications', [
            ['sub_institute_id' => $t, 'name' => 'Fire Safety', 'user_id' => 101, 'department_id' => 1, 'status' => 'valid', 'expiry_date' => '2026-09-01'],
            ['sub_institute_id' => $t, 'name' => 'Forklift', 'user_id' => 102, 'department_id' => 1, 'status' => 'revoked', 'expiry_date' => '2030-01-01'],
            ['sub_institute_id' => $t, 'name' => 'First Aid', 'user_id' => 103, 'department_id' => 2, 'status' => 'valid', 'expiry_date' => '2026-10-30'],
            ['sub_institute_id' => $t, 'name' => 'ISO Lead', 'user_id' => 101, 'department_id' => 1, 'status' => 'valid', 'expiry_date' => '2027-05-08'],
            ['sub_institute_id' => $t, 'name' => 'Old', 'user_id' => 101, 'department_id' => 1, 'status' => 'valid', 'expiry_date' => '2020-01-01', 'deleted_at' => '2026-01-01'],
        ]);

        // Gaps: G1 non-mandatory but 5 affected; G2 mandatory, 3 affected (must rank first); G3 dismissed; G4 deleted.
        $this->ins('capability_course_requests', [
            ['id' => 1, 'sub_institute_id' => $t, 'competency_name' => 'Negotiation', 'is_mandatory' => 0, 'affected_count' => 5, 'status' => 'open', 'first_raised_at' => '2026-10-10 00:00:00'],
            ['id' => 2, 'sub_institute_id' => $t, 'competency_name' => 'Data Privacy', 'is_mandatory' => 1, 'affected_count' => 3, 'status' => 'open', 'first_raised_at' => '2026-10-15 09:00:00', 'required_proficiency' => 3, 'jobrole_id' => 1],
            ['id' => 3, 'sub_institute_id' => $t, 'competency_name' => 'Dismissed', 'is_mandatory' => 1, 'affected_count' => 9, 'status' => 'dismissed', 'first_raised_at' => '2026-10-01 00:00:00'],
            ['id' => 4, 'sub_institute_id' => $t, 'competency_name' => 'Deleted', 'is_mandatory' => 1, 'affected_count' => 9, 'status' => 'open', 'first_raised_at' => '2026-10-01 00:00:00', 'deleted_at' => '2026-10-02'],
        ]);
        // People: 101 and 102 on G2 (gaps 2 and 1 -> avg 1.5); 102 and 103 on G1. Distinct open people = 101,102,103 = 3.
        $this->ins('capability_course_request_subjects', [
            ['request_id' => 2, 'sub_institute_id' => $t, 'user_id' => 101, 'gap' => 2], ['request_id' => 2, 'sub_institute_id' => $t, 'user_id' => 102, 'gap' => 1],
            ['request_id' => 1, 'sub_institute_id' => $t, 'user_id' => 102, 'gap' => 1], ['request_id' => 1, 'sub_institute_id' => $t, 'user_id' => 103, 'gap' => 3],
            ['request_id' => 3, 'sub_institute_id' => $t, 'user_id' => 104, 'gap' => 1],
        ]);
    }

    // ── access control ───────────────────────────────────────────────────────

    public function test_requires_a_token_and_an_admin_or_hr_role(): void
    {
        $this->getJson('/api/intelligence/overview')->assertStatus(401);
        $this->overview($this->headers(self::T1, 2))->assertStatus(403);   // employee
        $this->overview($this->headers(self::T1, 1))->assertOk();          // administrator
    }

    // ── figures, derived by hand from the fixtures ───────────────────────────

    public function test_readiness_figures_match_hand_counts_and_exclude_inactive_deleted_and_other_records(): void
    {
        $this->seedTenantOne();
        // The caller itself is an active tbluser of tenant 1; make that explicit instead of hiding it in the counts.
        $headers = $this->headers(self::T1);
        $callerCount = 1;

        $json = $this->overview($headers)->assertOk()->json();

        $roles = $this->measure($json, 'roles_mapped');
        $this->assertSame([1, 3, 33.3, 'attention'], [$roles['complete'], $roles['total'], $roles['percent'], $roles['state']]);

        $rated = $this->measure($json, 'employees_rated');
        $this->assertSame([1, 3 + $callerCount], [$rated['complete'], $rated['total']]);   // u101 rated; u101-103 + caller active

        $managers = $this->measure($json, 'managers_assigned');
        $this->assertSame([2, 3 + $callerCount], [$managers['complete'], $managers['total']]);

        $assess = $this->measure($json, 'assessments_with_employee');
        $this->assertSame([2, 3], [$assess['complete'], $assess['total']]);

        $courses = $this->measure($json, 'courses_linked');
        $this->assertSame([1, 2], [$courses['complete'], $courses['total']]);

        $certs = $this->measure($json, 'certificate_status_current');
        $this->assertSame([3, 4], [$certs['complete'], $certs['total']]);   // 4 live certs, 1 expired-by-date with status 'valid'
    }

    public function test_certification_verdicts_follow_the_canonical_rule(): void
    {
        $this->seedTenantOne();
        $json = $this->overview($this->headers(self::T1))->assertOk()->json();

        $risk = $this->insight($json, 'certification_risk');
        $this->assertNotNull($risk);
        $this->assertSame('high', $risk['severity']);   // an expired/revoked credential exists
        $this->assertSame('3 of 4 certificates are expired, revoked or expiring within 60 days', $risk['title']);

        $metrics = array_column($risk['evidence']['metrics'], 'value', 'label');
        $this->assertSame(['Expired' => '1', 'Revoked' => '1', 'Expiring within 60 days' => '1', 'Compliant' => '1'], $metrics);

        $this->assertSame('First Aid', $risk['evidence']['records'][0]['certificate']);
        $this->assertSame(10, $risk['evidence']['records'][0]['days']);
        $this->assertSame('Finance', $risk['evidence']['records'][0]['department']);
    }

    public function test_gaps_rank_mandatory_first_and_count_people_once(): void
    {
        $this->seedTenantOne();
        $json = $this->overview($this->headers(self::T1))->assertOk()->json();

        $gaps = $this->insight($json, 'capability_gaps');
        $this->assertNotNull($gaps);
        $this->assertSame('high', $gaps['severity']);   // a mandatory gap is open
        $this->assertSame('2 capability gaps are waiting for a course decision', $gaps['title']);

        $metrics = array_column($gaps['evidence']['metrics'], 'value', 'label');
        // dismissed and deleted requests are excluded; person 102 is on both open gaps but counted once.
        $this->assertSame(['Open gaps' => '2', 'Mandatory' => '1', 'People affected' => '3'], $metrics);

        $rows = $gaps['evidence']['records'];
        $this->assertSame('Data Privacy', $rows[0]['competency']);   // mandatory outranks the larger non-mandatory gap
        $this->assertSame(3, $rows[0]['people_affected']);
        $this->assertSame(1.5, $rows[0]['average_shortfall']);
        $this->assertSame(5, $rows[0]['days_waiting']);              // 15 Oct -> 20 Oct, never negative
        $this->assertSame('Negotiation', $rows[1]['competency']);
        $this->assertSame(10, $rows[1]['days_waiting']);
    }

    public function test_insights_are_ordered_by_severity_and_carry_the_fields_a_reader_needs(): void
    {
        $this->seedTenantOne();
        $json = $this->overview($this->headers(self::T1))->assertOk()->json();

        $ranks = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
        $order = array_map(fn ($i) => $ranks[$i['severity']], $json['data']['insights']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);

        foreach ($json['data']['insights'] as $i) {
            foreach (['key', 'area', 'class', 'severity', 'title', 'detected', 'why_it_matters', 'evidence', 'recommended_action', 'expected_impact'] as $field) {
                $this->assertNotEmpty($i[$field], "insight {$i['key']} is missing {$field}");
            }
            $this->assertContains($i['class'], ['measured', 'calculated']);
        }
    }

    // ── honesty ──────────────────────────────────────────────────────────────

    public function test_an_empty_organisation_is_not_measurable_never_zero_percent_or_all_clear(): void
    {
        $headers = $this->headers(self::T1);
        // The caller is an active employee of the tenant, so complete their two records to isolate "nothing else recorded".
        DB::table('tbluser')->where('id', $this->callerId)->update(['reporting_manager_id' => 999]);
        $this->ins('competency_kasba_rating', [['sub_institute_id' => self::T1, 'user_id' => $this->callerId, 'kasba_item_id' => 1, 'rating' => 3]]);

        $json = $this->overview($headers)->assertOk()->json();

        foreach (['roles_mapped', 'assessments_with_employee', 'courses_linked', 'certificate_status_current'] as $key) {
            $m = $this->measure($json, $key);
            $this->assertSame([0, 0, null, 'not_measurable'], [$m['complete'], $m['total'], $m['percent'], $m['state']], $key);
        }
        $this->assertSame('ok', $this->measure($json, 'employees_rated')['state']);
        $this->assertSame([], $json['data']['insights']);
        $this->assertTrue($json['empty_is_expected']);
        $this->assertStringContainsString('data-readiness', $json['empty_reason']);
    }

    public function test_an_organisation_with_no_gap_or_certificate_records_produces_no_such_insight(): void
    {
        $json = $this->overview($this->headers(self::T1))->assertOk()->json();

        $this->assertNull($this->insight($json, 'capability_gaps'));
        $this->assertNull($this->insight($json, 'certification_risk'));
    }

    public function test_a_failing_source_is_reported_unavailable_and_leaks_nothing(): void
    {
        $this->seedTenantOne();
        Schema::drop('capability_course_request_subjects');
        Schema::drop('capability_course_requests');

        $response = $this->overview($this->headers(self::T1))->assertOk();
        $json = $response->json();

        $this->assertNull($this->insight($json, 'capability_gaps'));
        $this->assertContains('capability gaps', $json['data']['unavailable_sources']);
        $this->assertNotNull($this->insight($json, 'certification_risk'), 'one failing source must not blank the rest');
        $this->assertStringNotContainsStringIgnoringCase('capability_course_request', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $response->getContent());
    }

    public function test_when_the_only_sources_fail_the_empty_message_is_not_an_all_clear(): void
    {
        Schema::drop('capability_course_request_subjects');
        Schema::drop('capability_course_requests');
        Schema::drop('s_competency_certifications');

        $headers = $this->headers(self::T1);
        DB::table('tbluser')->where('id', $this->callerId)->update(['reporting_manager_id' => 999]);
        $this->ins('competency_kasba_rating', [['sub_institute_id' => self::T1, 'user_id' => $this->callerId, 'kasba_item_id' => 1, 'rating' => 3]]);

        $json = $this->overview($headers)->assertOk()->json();

        $this->assertSame([], $json['data']['insights']);
        $this->assertEqualsCanonicalizing(['capability gaps', 'certification risk'], $json['data']['unavailable_sources']);
        $this->assertStringContainsString('not an all-clear', $json['empty_reason']);
    }

    // ── tenancy ──────────────────────────────────────────────────────────────

    public function test_another_organisations_records_never_appear_and_a_requested_tenant_is_ignored(): void
    {
        $this->seedTenantOne();
        // Tenant 2 has its own, very different, data.
        $this->ins('s_user_jobrole', [['id' => 50, 'jobrole' => 'X', 'sub_institute_id' => self::T2]]);
        $this->ins('capability_course_requests', [
            ['id' => 50, 'sub_institute_id' => self::T2, 'competency_name' => 'SECRET-TENANT-2', 'is_mandatory' => 1, 'affected_count' => 99, 'status' => 'open', 'first_raised_at' => '2026-10-01 00:00:00'],
        ]);
        $this->ins('s_competency_certifications', [
            ['sub_institute_id' => self::T2, 'name' => 'SECRET-CERT', 'status' => 'valid', 'expiry_date' => '2026-10-21'],
        ]);

        $t1 = $this->headers(self::T1);

        // Tenant 1 caller names tenant 2 in the query string and in the body of an equivalent request: still tenant 1.
        $response = $this->overview($t1, ['sub_institute_id' => self::T2])->assertOk();
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertSame(3, $this->measure($response->json(), 'roles_mapped')['total']);

        // And tenant 2 sees only its own.
        $t2 = $this->overview($this->headers(self::T2))->assertOk();
        $this->assertSame(1, $this->measure($t2->json(), 'roles_mapped')['total']);
        $this->assertStringContainsString('SECRET-TENANT-2', $t2->getContent());
        $this->assertStringNotContainsString('Data Privacy', $t2->getContent());
    }

    public function test_the_overview_is_read_only(): void
    {
        $this->seedTenantOne();
        $tables = ['capability_course_requests', 's_competency_certifications', 'tbluser', 'competency_kasba_rating'];
        $headers = $this->headers(self::T1);   // creates the caller + token first
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);

        $this->overview($headers)->assertOk();
        $this->overview($headers)->assertOk();

        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables));
    }
}
