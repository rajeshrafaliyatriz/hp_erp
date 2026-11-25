<?php

namespace Tests\Unit\Intelligence\Adversarial;

use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 7. DATA-INTEGRITY EDGE CASES. Real data is empty, sparse, duplicated, half-NULL, soft-deleted, mixed-case and sometimes missing a whole table. None of that may
 * turn into an exception, a verdict made of nothing, a leaked table name, or a figure that silently absorbs rows it should not.
 */
class DataIntegrityEdgeCasesTest extends AdversarialTestCase
{
    private function admin()
    {
        return $this->callerFor('administrator', self::T1, self::B1);
    }

    /** Every detector called DIRECTLY (not through the service), exceptions captured rather than thrown. @return array<string, DetectorResult|\Throwable> */
    private function direct($caller): array
    {
        $out = [];
        foreach ($this->detectors() as $d) {
            try {
                $out[$d->id()] = $d->authorizes($caller) ? $d->detect($caller, $this->fixedClock()) : null;
            } catch (\Throwable $e) {
                $out[$d->id()] = $e;
            }
        }

        return array_filter($out, static fn ($v) => $v !== null);
    }

    private function metricOf(array $snapshot, string $category, string $labelPrefix): mixed
    {
        foreach ($snapshot['insights'] as $i) {
            if ($i['category'] !== $category) {
                continue;
            }
            foreach ($i['evidence'] as $e) {
                if ($e['kind'] === 'metric' && str_starts_with($e['label'], $labelPrefix)) {
                    return $e['value'];
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------
    // empty and sparse
    // ---------------------------------------------------------------------------------------------------------------------------------------------------

    public function test_on_empty_tables_no_detector_throws_and_none_claims_to_have_checked_something(): void
    {
        $results = $this->direct($this->admin());

        $this->assertCount(23, $results,'every detector must authorise the all-rights administrator');
        foreach ($results as $id => $r) {
            $this->assertInstanceOf(DetectorResult::class, $r, "$id threw: " . ($r instanceof \Throwable ? get_class($r) : ''));
            $this->assertSame([], $r->insights, "$id produced an insight out of nothing");
            // CONTRACT CHANGE: a source that is absent on this installation (here: the central tables this fixture does not create) is now data_unavailable, not not_applicable.
            $this->assertContains($r->state, [Taxonomy::STATE_INSUFFICIENT, Taxonomy::STATE_NOT_APPLICABLE, Taxonomy::STATE_DATA_UNAVAILABLE],
                "$id answered '{$r->state}' on EMPTY tables: that says 'checked, nothing to report' about data that does not exist");
            if ($r->state === Taxonomy::STATE_INSUFFICIENT) {
                $this->assertFalse($r->coverage->sufficient, $id);
                $this->assertSame(0, $r->coverage->observed, "$id");
                $this->assertNotNull($r->reason, $id);
            }
        }
    }

    public function test_a_single_record_in_every_source_is_never_enough_for_an_insight(): void
    {
        $t = self::T1;
        $this->insertUser(1010, $t, ['department_id' => 3, 'user_profile_id' => 7]);
        $this->insertUser(1100, $t, ['reporting_manager_id' => 1010, 'department_id' => 3]);
        $this->insertRow('hrms_attendances', ['user_id' => 1100, 'day' => '2026-11-10', 'punchin_time' => '2026-11-10 09:00:00', 'punchout_time' => null, 'status' => 1, 'sub_institute_id' => $t]);
        $l = $this->leave($t, 1100, '2026-11-10 00:00:00');
        $this->step($t, $l, ['status' => 'pending', 'pending_since' => '2026-11-10 10:00:00']);
        $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => 1100, 'status' => 'valid', 'expiry_date' => '2026-10-01']);
        $this->insertRow('talent_job_applications', ['sub_institute_id' => $t, 'job_id' => 1, 'status' => 'Pending Review', 'applied_date' => '2026-10-01']);
        $this->insertRow('talent_onboarding_journeys', ['sub_institute_id' => $t, 'employee_id' => 1100, 'manager_id' => 1010, 'probation_end' => '2026-11-01', 'confirmation_status' => 'pending', 'status' => 'x']);
        $this->addSignal($t);
        $this->addRun($t);
        $this->addDeal($t, ['next_step' => '']);
        $this->addCrmOpp($t, ['updated_at' => '2026-01-01 00:00:00']);
        $this->addInvoice($t, ['due_date' => '2026-01-01']);
        $this->insertRow('gtm_renewals', ['sub_institute_id' => $t, 'account_id' => 1, 'renewal_date' => '2026-11-01', 'status' => 'upcoming']);

        foreach ($this->direct($this->admin()) as $id => $r) {
            $this->assertInstanceOf(DetectorResult::class, $r, $id);
            foreach ($r->insights as $insight) {
                $this->assertTrue($insight->coverage->sufficient, "$id published an insight whose own coverage says the data is insufficient");
            }
            if ($r->state === Taxonomy::STATE_OK) {
                $this->assertTrue($r->coverage->sufficient, "$id: ok with insufficient coverage");
            }
        }
    }

    public function test_every_insight_of_the_rich_world_rests_on_sufficient_coverage(): void
    {
        $this->seedWorld(self::T1, self::B1);
        foreach (self::ROLES as $role) {
            foreach ($this->service()->run($this->callerFor($role, self::T1, self::B1))['insights'] as $insight) {
                $this->assertTrue($insight->coverage->sufficient, "$role / {$insight->key}: an insight must never rest on coverage that is itself insufficient");
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------
    // duplicates, mixed case, currencies, nulls
    // ---------------------------------------------------------------------------------------------------------------------------------------------------

    public function test_duplicate_attendance_rows_are_measured_exactly(): void
    {
        $this->seedWorld(self::T1, self::B1);
        $s = $this->snapshot($this->callerFor('hr_manager', self::T1, self::B1, []));

        $this->assertSame(3, $this->metricOf($s, 'attendance_duplicates', 'Person-days with duplicate rows'));
        $this->assertSame(3, $this->metricOf($s, 'attendance_duplicates', 'Surplus rows'));
    }

    public function test_mixed_case_status_text_is_one_status(): void
    {
        $this->seedWorld(self::T1, self::B1);
        $s = $this->snapshot($this->callerFor('hr_manager', self::T1, self::B1, []));

        // 'Pending Review', 'pending review', 'PENDING REVIEW' and 'Under Review' older than 14 days: 6 rows; the soft-deleted one is not counted.
        $this->assertSame(6, $this->metricOf($s, 'recruitment_stale_applications', 'Applications waiting for review'));
        // 'Hired' and 'hired' are one status in the snapshot, not two.
        $statusLabels = [];
        foreach ($s['insights'] as $i) {
            if ($i['category'] === 'recruitment_snapshot') {
                foreach ($i['evidence'] as $e) {
                    if ($e['kind'] === 'metric' && str_starts_with($e['label'], 'Currently ')) {
                        $statusLabels[] = $e['label'];
                    }
                }
            }
        }
        $this->assertContains('Currently hired', $statusLabels);
        $this->assertNotContains('Currently Hired', $statusLabels);
        $this->assertSame(count($statusLabels), count(array_unique($statusLabels)), 'a status appears once whatever its case');
    }

    public function test_currencies_are_never_added_together(): void
    {
        $this->seedWorld(self::T1, self::B1);   // 8 overdue USD invoices of 100 and 4 EUR invoices of 100: balances 800 and 400
        $s = $this->snapshot($this->callerFor('administrator', self::T1, self::B1));

        $this->assertSame(800.0, (float) $this->metricOf($s, 'invoices', 'Overdue balance in USD'));
        $this->assertSame(400.0, (float) $this->metricOf($s, 'invoices', 'Overdue balance in EUR'));
        foreach ($s['insights'] as $i) {
            foreach ($i['evidence'] as $e) {
                if ($e['kind'] === 'metric' && is_numeric($e['value'])) {
                    $this->assertNotEquals(1200.0, (float) $e['value'], $i['key'] . ' / ' . $e['label'] . ' looks like a sum across currencies');
                }
            }
        }
        // Deals without a currency are counted, never valued: the pipeline insight carries counts only.
        foreach ($s['insights'] as $i) {
            if ($i['detector'] === 'commercial.pipeline_attention') {
                foreach ($i['evidence'] as $e) {
                    $this->assertNotSame('USD', $e['unit'] ?? null, 'the pipeline detector must not value deals');
                }
            }
        }
    }

    public function test_a_blank_status_certificate_is_counted_but_a_revoked_one_is_not(): void
    {
        $this->seedWorld(self::T1, self::B1);
        $caller = $this->callerFor('hr_manager', self::T1, self::B1, []);
        $before = $this->metricOf($this->snapshot($caller), 'certification_lapsed', 'Expired certifications');
        $this->assertSame(4, $before);

        $this->insertRow('s_competency_certifications', ['sub_institute_id' => self::T1, 'user_id' => self::B1 + 105, 'status' => '', 'expiry_date' => '2026-10-01']);
        $this->insertRow('s_competency_certifications', ['sub_institute_id' => self::T1, 'user_id' => self::B1 + 106, 'status' => 'REVOKED', 'expiry_date' => '2026-10-01']);

        $this->assertSame(5, $this->metricOf($this->snapshot($caller), 'certification_lapsed', 'Expired certifications'));
    }

    public function test_rows_with_null_dates_never_throw_and_never_count_as_overdue(): void
    {
        $t = self::T1;
        $this->seedWorld($t, self::B1);
        // Rows whose every date is NULL, in every table that has dates.
        for ($i = 0; $i < 12; $i++) {
            $this->insertRow('hrms_attendances', ['user_id' => self::B1 + 100 + $i, 'day' => '2026-11-15', 'punchin_time' => null, 'punchout_time' => null, 'status' => 1, 'sub_institute_id' => $t]);
            $l = $this->leave($t, self::B1 + 100 + $i, '2026-11-17 08:00:00', ['from_date' => null, 'to_date' => null]);
            $this->step($t, $l, ['status' => 'pending', 'pending_since' => null]);
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => self::B1 + 100 + $i, 'status' => 'valid', 'expiry_date' => null]);
            $this->insertRow('talent_job_applications', ['sub_institute_id' => $t, 'job_id' => 1, 'status' => 'Pending Review', 'applied_date' => null]);
            $this->insertRow('talent_onboarding_journeys', ['sub_institute_id' => $t, 'employee_id' => self::B1 + 100 + $i, 'manager_id' => null, 'probation_end' => null, 'confirmation_status' => 'pending', 'status' => 'x']);
            $this->addSignal($t, ['event_date' => null, 'source_published_at' => null, 'first_discovered_at' => null]);
            $this->addDeal($t, ['expected_close_date' => null, 'next_step_due' => null, 'stage_changed_at' => null, 'created_at' => null]);
            $this->addCrmOpp($t, ['closing_date' => null, 'updated_at' => null]);
            $this->addInvoice($t, ['due_date' => null]);
        }
        $with = $this->snapshot($this->callerFor('administrator', $t, self::B1));

        foreach ($with['results'] as $r) {
            $this->assertNotSame('error', $r['state'], $r['detector'] . ' failed on NULL dates');
        }
        // Undated invoices are not overdue; the count of overdue invoices is still the 12 dated ones of the world.
        $this->assertSame(12, $this->metricOf($with, 'invoices', 'Overdue and not fully paid'));
    }

    public function test_huge_numbers_are_handled_and_encode_to_json(): void
    {
        $this->seedWorld(self::T1, self::B1);
        $this->addInvoice(self::T1, ['due_date' => '2026-01-01', 'total' => 9999999999999.99, 'amount_paid' => 0, 'currency' => 'USD']);
        $this->addInvoice(self::T1, ['due_date' => '2026-01-01', 'total' => 9999999999999.99, 'amount_paid' => 0.01, 'currency' => 'USD']);
        $this->addInvoice(self::T1, ['due_date' => '2026-01-01', 'total' => 50, 'amount_paid' => 999999999.00, 'currency' => 'USD']);   // overpaid: not overdue
        $this->addDeal(self::T1, ['amount' => 99999999999999.99, 'currency' => 'USD']);

        $s = $this->snapshot($this->callerFor('administrator', self::T1, self::B1));
        $json = json_encode($s, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($json);
        $this->assertDoesNotMatchRegularExpression('/\b(INF|NAN)\b/', $json);
        $balance = (float) $this->metricOf($s, 'invoices', 'Overdue balance in USD');
        $this->assertGreaterThan(1.9e13, $balance);
        $this->assertTrue(is_finite($balance));
        $this->assertSame(14, $this->metricOf($s, 'invoices', 'Overdue and not fully paid'), 'the 12 overdue invoices of the world plus the two huge ones; the overpaid one is not overdue');
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------
    // rows that must not count
    // ---------------------------------------------------------------------------------------------------------------------------------------------------

    public function test_rows_with_no_tenant_do_not_change_anything(): void
    {
        $world = $this->seedWorld(self::T1, self::B1);
        $callers = array_map(fn ($r) => $this->callerFor($r, self::T1, self::B1), self::ROLES);
        $before = array_map(fn ($c) => $this->snapshot($c), $callers);

        $nullable = ['hrms_attendances', 'hrms_emp_leaves', 'lms_certificates', 'talent_job_applications', 'talent_offers', 'tbluser'];
        foreach (['tbluser', 'hrms_attendances', 'hrms_emp_leaves', 'hrms_leave_approval_steps', 's_competency_certifications', 'lms_certificates', 'talent_job_applications', 'talent_offers',
            'talent_onboarding_journeys', 'talent_onboarding_tasks', 'g2g_company_opportunities', 'g2g_research_runs', 'gtm_deals', 'gtm_activities', 'gtm_contacts', 'gtm_renewals',
            'gtm_health_snapshots', 'crm_opportunities', 'crm_invoices'] as $table) {
            foreach ($this->db()->table($table)->where('sub_institute_id', self::T1)->get() as $row) {
                $copy = (array) $row;
                unset($copy['id']);
                if ($table === 'tbluser') {
                    $copy['id'] = 90000 + (int) $row->id;
                }
                foreach ([0, in_array($table, $nullable, true) ? null : 0] as $tenant) {
                    $copy['sub_institute_id'] = $tenant;
                    if ($table === 'tbluser') {
                        $copy['id'] += 100000;
                    }
                    $this->db()->table($table)->insert($copy);
                }
            }
        }

        $this->assertNotEmpty($world['people']);
        foreach ($callers as $i => $c) {
            $this->assertSame($before[$i], $this->snapshot($c), self::ROLES[$i] . ': rows without a tenant changed an answer');
        }
    }

    public function test_soft_deleted_rows_do_not_change_anything(): void
    {
        $this->seedWorld(self::T1, self::B1);
        $callers = array_map(fn ($r) => $this->callerFor($r, self::T1, self::B1), self::ROLES);
        $before = array_map(fn ($c) => $this->snapshot($c), $callers);

        foreach (['hrms_attendances', 'hrms_emp_leaves', 's_competency_certifications', 'lms_certificates', 'talent_job_applications', 'talent_onboarding_journeys', 'talent_onboarding_tasks',
            'gtm_deals', 'gtm_contacts', 'gtm_renewals', 'crm_opportunities', 'crm_invoices'] as $table) {
            foreach ($this->db()->table($table)->where('sub_institute_id', self::T1)->whereNull('deleted_at')->get() as $row) {
                $copy = (array) $row;
                unset($copy['id']);
                $copy['deleted_at'] = '2026-11-01 00:00:00';
                $this->db()->table($table)->insert($copy);
            }
        }

        foreach ($callers as $i => $c) {
            $this->assertSame($before[$i], $this->snapshot($c), self::ROLES[$i] . ': soft-deleted rows changed an answer');
        }
    }

    public function test_people_who_are_inactive_terminated_deleted_or_have_no_status_contribute_nothing(): void
    {
        $world = $this->seedWorld(self::T1, self::B1);
        $callers = array_map(fn ($r) => $this->callerFor($r, self::T1, self::B1), self::ROLES);
        $with = array_map(fn ($c) => $this->snapshot($c), $callers);

        // Remove every record that belongs to the excluded people; nothing the callers are told may change.
        $ex = $world['excluded'];
        $leaveIds = $this->db()->table('hrms_emp_leaves')->whereIn('user_id', $ex)->pluck('id')->all();
        $this->db()->table('hrms_leave_approval_steps')->whereIn('leave_id', $leaveIds)->delete();
        $this->db()->table('hrms_emp_leaves')->whereIn('user_id', $ex)->delete();
        $this->db()->table('hrms_attendances')->whereIn('user_id', $ex)->delete();
        $this->db()->table('s_competency_certifications')->whereIn('user_id', $ex)->delete();

        foreach ($callers as $i => $c) {
            $this->assertSame($with[$i], $this->snapshot($c), self::ROLES[$i] . ': people who are not active employees influenced an answer');
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------
    // a missing optional table or column
    // ---------------------------------------------------------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string, string|null}> */
    public static function missingSources(): iterable
    {
        foreach (['hrms_attendances', 'hrms_emp_leaves', 'hrms_leave_approval_steps', 's_competency_certifications', 'lms_certificates', 'talent_job_applications', 'talent_offers',
            'talent_onboarding_journeys', 'talent_onboarding_tasks', 'g2g_company_opportunities', 'g2g_research_runs', 'gtm_deals', 'gtm_activities', 'gtm_contacts', 'gtm_renewals',
            'gtm_health_snapshots', 'crm_opportunities', 'crm_invoices'] as $table) {
            yield "table $table" => [$table, null];
        }
        foreach ([['hrms_attendances', 'punchout_time'], ['hrms_leave_approval_steps', 'pending_since'], ['talent_onboarding_journeys', 'probation_end'], ['talent_job_applications', 'deleted_at'],
            ['lms_certificates', 'superseded_by'], ['talent_onboarding_tasks', 'completed_at'], ['g2g_company_opportunities', 'fetch_level'], ['gtm_deals', 'next_step_due']] as [$table, $column]) {
            yield "column $table.$column" => [$table, $column];
        }
    }

    #[DataProvider('missingSources')]
    public function test_a_missing_optional_source_degrades_without_exception_or_leak(string $table, ?string $column): void
    {
        $this->seedWorld(self::T1, self::B1);
        if ($column === null) {
            Schema::drop($table);
        } else {
            Schema::table($table, fn (Blueprint $b) => $b->dropColumn($column));
        }

        foreach (['administrator', 'hr_manager', 'reporting_manager', 'employee', 'recruiter'] as $role) {
            $run = $this->service()->run($this->callerFor($role, self::T1, self::B1));

            foreach ($run['results'] as $r) {
                $this->assertContains($r->state, Taxonomy::STATES, "$role / {$r->detector}");
                $this->assertNotNull($r->asOf);
                $this->assertDoesNotMatchRegularExpression('/(hrms_|talent_|gtm_|crm_|g2g_|lms_|s_competency|tbluser|sqlstate|no such|select |insert |from |where |exception|stack|\.php|#\d)/i',
                    (string) $r->reason, "$role / {$r->detector}: the reason leaks internals");
            }
            $json = json_encode(['r' => array_map(fn ($r) => $r->summary(), $run['results']), 'i' => array_map(fn ($i) => $i->toArray(), $run['insights']), 'm' => $run['meta']]);
            $this->assertDoesNotMatchRegularExpression('/(sqlstate|no such (table|column)|syntax error|QueryException|PDOException)/i', $json, "$role: a database error reached the response");
        }
    }

    public function test_commercial_detectors_throw_on_a_missing_table_when_called_directly_and_the_service_turns_that_into_a_generic_error(): void
    {
        // OBSERVATION (not a defect: the service owns failure isolation, contract rule in InsightService): the HR detectors catch their own failures; the
        // commercial ones let a QueryException out of detect(). Their callers must therefore go through the service.
        $this->seedWorld(self::T1, self::B1);
        Schema::drop('crm_invoices');
        $invoice = array_values(array_filter($this->detectors(), fn ($d) => $d->id() === 'commercial.invoice_attention'))[0];

        $threw = false;
        try {
            $invoice->detect($this->admin(), $this->fixedClock());
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'direct call: the commercial detector does not guard a missing table');

        $run = $this->service()->run($this->admin());
        $result = array_values(array_filter($run['results'], fn ($r) => $r->detector === 'commercial.invoice_attention'))[0];
        $this->assertSame(Taxonomy::STATE_ERROR, $result->state);
        $this->assertSame('This check could not be completed right now.', $result->reason);
        $this->assertGreaterThan(5, count($run['insights']), 'the other detectors still answered');
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------
    // future-dated records
    // ---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * DEFECT CANDIDATE (see the report): a punch dated AFTER today cannot have happened, yet the attendance window has a lower bound only (`day >= start`), so
     * future-dated rows are counted as recorded days and as participation. Fifteen future-dated rows for twelve people make a tenant with NO real attendance
     * look assessable ("enough days", "enough people").
     */
    public function test_defect_future_dated_attendance_rows_must_not_count_as_recorded_attendance(): void
    {
        $t = self::T1;
        $this->insertUser(1010, $t, ['department_id' => 3, 'user_profile_id' => 7]);
        $people = $this->people(12, $t, 1100, 1010, 3);
        for ($d = 0; $d < 15; $d++) {
            $day = sprintf('2026-12-%02d', 1 + $d);           // all of them after the fixed "today" (2026-11-21)
            foreach ($people as $p) {
                $this->insertRow('hrms_attendances', ['user_id' => $p, 'day' => $day, 'punchin_time' => "$day 09:00:00", 'punchout_time' => null, 'status' => 1, 'sub_institute_id' => $t]);
            }
        }
        $attendance = array_values(array_filter($this->detectors(), fn ($d) => $d->id() === 'hr.attendance_health'))[0];

        $result = $attendance->detect($this->callerFor('hr_manager', $t, 1000, []), $this->fixedClock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state, 'attendance recorded only in the FUTURE is not attendance: expected insufficient data, got ' . $result->state);
    }

    public function test_future_dated_certificates_and_applications_are_handled_without_error(): void
    {
        $this->seedWorld(self::T1, self::B1);
        $this->insertRow('s_competency_certifications', ['sub_institute_id' => self::T1, 'user_id' => self::B1 + 105, 'status' => 'valid', 'expiry_date' => '2099-01-01']);
        $this->insertRow('talent_job_applications', ['sub_institute_id' => self::T1, 'job_id' => 1, 'status' => 'Pending Review', 'applied_date' => '2099-01-01']);
        $this->addSignal(self::T1, ['first_discovered_at' => '2099-01-01 00:00:00']);

        $s = $this->snapshot($this->callerFor('administrator', self::T1, self::B1));
        foreach ($s['results'] as $r) {
            $this->assertNotSame('error', $r['state'], $r['detector']);
        }
        // A far-future expiry is not "expiring": the lapsed/due counts are those of the world.
        $this->assertSame(4, $this->metricOf($s, 'certification_lapsed', 'Expired certifications'));
    }
}
