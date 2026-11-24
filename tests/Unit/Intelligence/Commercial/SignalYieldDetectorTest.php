<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Detectors\Commercial\CommercialAccess;
use App\Domain\Intelligence\Detectors\Commercial\SignalYieldDetector;
use App\Domain\Intelligence\Taxonomy;

class SignalYieldDetectorTest extends CommercialTestCase
{
    private function detector(bool $menuRights = false): SignalYieldDetector
    {
        return new SignalYieldDetector($this->db(), $this->thresholds(), new CommercialAccess($menuRights));
    }

    public function test_reports_zero_yield_and_failures_with_counts_only(): void
    {
        $this->addRun(1, ['opportunities_qualified' => 0]);
        $this->addRun(1, ['opportunities_qualified' => 0]);
        $this->addRun(1, ['opportunities_qualified' => null]);
        $this->addRun(1, ['status' => 'failed', 'opportunities_qualified' => 0, 'queries_failed' => 4]);
        $this->addRun(1, ['status' => 'failed', 'opportunities_qualified' => 0, 'queries_failed' => 2]);
        $this->addRun(1, ['opportunities_qualified' => 5]);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(2, $result->insights);
        // Zero-yield is judged over runs that did NOT fail (success/partial): 4 such runs, 3 of them found nothing. The 2 failed runs are not in it.
        $ev = $this->evidenceByLabel($result->insights[0]);
        $this->assertSame(4, $ev['Runs that finished (success or partial)']['value']);
        $this->assertSame(3, $ev['Of these, with no qualified signal']['value']);
        $this->assertSame(5, $ev['Qualified signals found in total']['value']);
        $failures = $this->evidenceByLabel($result->insights[1]);
        $this->assertSame(6, $failures['Finished runs, including failed']['value']);
        $this->assertSame(2, $failures['Failed runs']['value']);
        $this->assertSame(6, $failures['Failed queries']['value']);
        foreach ($result->insights as $insight) {
            $this->assertStringNotContainsString('cost', strtolower(json_encode($insight->toArray())));
        }
    }

    public function test_failed_runs_are_never_claimed_as_zero_yield(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addRun(1, ['status' => 'failed', 'opportunities_qualified' => 0, 'queries_failed' => 1]);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertCount(1, $result->insights, 'only the failure insight, nothing about "no qualified signal"');
        $this->assertStringContainsString('failed', $result->insights[0]->title);
        $this->assertStringNotContainsString('no qualified signal', strtolower(json_encode($result->insights[0]->toArray())) . '');
    }

    public function test_a_successful_run_with_zero_qualified_is_still_a_success_but_counts_as_zero_yield(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addRun(1, ['status' => 'success', 'opportunities_qualified' => 0]);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertCount(1, $result->insights);
        $this->assertStringContainsString('no qualified signal', $result->insights[0]->title);
    }

    public function test_running_and_skipped_runs_and_old_runs_are_not_counted(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addRun(1);
        }
        $this->addRun(1, ['status' => 'running', 'opportunities_qualified' => 0]);
        $this->addRun(1, ['status' => 'skipped', 'opportunities_qualified' => 0]);
        $this->addRun(1, ['report_date' => '2026-01-01', 'opportunities_qualified' => 0]);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(5, $result->coverage->observed);
        $this->assertSame([], $result->insights);
    }

    public function test_insufficient_when_too_few_runs(): void
    {
        $this->addRun(1);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
    }

    public function test_other_tenants_runs_are_never_counted(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->addRun(2, ['opportunities_qualified' => 0, 'status' => 'failed']);
        }
        $this->addRun(1);

        $result = $this->detector()->detect($this->commercialCaller('administrator', [], 1), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(1, $result->coverage->observed);
    }

    public function test_authorization_follows_the_signals_gate(): void
    {
        $this->assertTrue($this->detector()->authorizes($this->commercialCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->commercialCaller('employee')));
        $this->assertTrue($this->detector(true)->authorizes($this->commercialCaller('employee', ['/signals'])));
        $this->assertFalse($this->detector(true)->authorizes($this->commercialCaller('administrator')));
    }
}
