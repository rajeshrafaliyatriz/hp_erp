<?php

namespace Tests\Unit;

use App\Domain\Signals\Run\RunAccounting;
use PHPUnit\Framework\TestCase;

/** Failure and budget visibility plus the `completion` field. Pure: synthetic counters only. */
class RunAccountingFailureTest extends TestCase
{
    private function build(array $diag = [], array $rej = [], int $sources = 3, int $cands = 2): array
    {
        return RunAccounting::build($diag, $rej, ['sources' => $sources, 'candidates' => $cands]);
    }

    private function ok(): array
    {
        return ['planned' => ['a'], 'executed' => ['a'], 'failed' => [], 'skipped' => [], 'returned' => 5,
            'import' => ['accepted' => 1, 'updated' => 0, 'duplicate' => 0, 'rejected_by_gate' => 0]];
    }

    public function test_completed_with_qualified(): void
    {
        $s = $this->build($this->ok());
        $this->assertSame('completed', $s['completion']);
        $this->assertSame([], $s['completion_reasons']);
    }

    public function test_completed_no_qualified(): void
    {
        $d = $this->ok();
        $d['import'] = ['accepted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected_by_gate' => 0];
        $this->assertSame('completed_no_qualified', $this->build($d)['completion']);
    }

    public function test_main_ai_failure_is_incomplete_and_counted(): void
    {
        $d = $this->ok() + ['ai' => ['main_failed' => 1, 'followup_failed' => 0]];
        $s = $this->build($d, [], 0, 0);
        $this->assertSame(['main_failed' => 1, 'followup_failed' => 0], $s['ai']);
        $this->assertSame('incomplete', $s['completion']);
        $this->assertContains('ai_failed', $s['completion_reasons']);
    }

    public function test_followup_ai_failure_is_counted_and_incomplete(): void
    {
        $s = $this->build($this->ok() + ['ai' => ['main_failed' => 0, 'followup_failed' => 2]]);
        $this->assertSame(2, $s['ai']['followup_failed']);
        $this->assertSame('incomplete', $s['completion']);
        $this->assertContains('ai_followup_failed', $s['completion_reasons']);
    }

    public function test_page_cap_and_time_limits(): void
    {
        $s = $this->build($this->ok() + ['page_budget' => ['pages_cap_reached' => 1, 'pages_time_limited' => 0]]);
        $this->assertSame(['pages_cap_reached' => 1, 'pages_time_limited' => 0, 'time_limited' => false], $s['limits']);
        $this->assertSame([], $s['completion_reasons'], 'the page cap is how an ordinary run ends, not an incomplete run');
        $this->assertNotSame('incomplete', $s['completion']);

        $t = $this->build($this->ok() + ['page_budget' => ['pages_cap_reached' => 0, 'pages_time_limited' => 1], 'time_limited' => true]);
        $this->assertSame('incomplete', $t['completion']);
        $this->assertSame(['time_limited'], $t['completion_reasons']);
        $this->assertTrue($t['limits']['time_limited']);
    }

    public function test_search_time_budget_skip_is_incomplete(): void
    {
        $d = $this->ok();
        $d['skipped'] = ['b' => 'time_budget'];
        $this->assertContains('searches_skipped_time_budget', $this->build($d)['completion_reasons']);
    }

    public function test_all_searches_failed_is_incomplete(): void
    {
        $d = ['planned' => ['a'], 'executed' => ['a'], 'failed' => ['a' => 'http_500']];
        $this->assertSame('incomplete', $this->build($d, [], 0, 0)['completion']);
    }

    public function test_unrecorded_fields_are_null_and_old_keys_unchanged(): void
    {
        $s = $this->build($this->ok());
        $this->assertNull($s['ai']);
        $this->assertNull($s['limits']);
        $this->assertNull($s['budget']);
        $this->assertSame(['new' => 1, 'updated' => 0, 'unchanged_existing' => 0, 'qualified_total' => 1], $s['outcome']);
        $this->assertSame(['planned' => 1, 'attempted' => 1, 'succeeded' => 1], array_intersect_key($s['queries'], array_flip(['planned', 'attempted', 'succeeded'])));
    }

    public function test_researcher_wiring_for_failures(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("\$this->diag['ai']['main_failed'] = 1;", $src);
        $this->assertStringContainsString("\$this->diag['ai']['followup_failed']", $src);
        $this->assertStringContainsString("['page_budget']['pages_cap_reached'] = 1;", $src);
        $this->assertStringContainsString("['page_budget']['pages_time_limited'] = 1;", $src);
        // A failed run keeps its stage stats.
        $this->assertMatchesRegularExpression("/'failed', \['stage' => 'failed', 'stage_message' => \\\$message, 'stage_stats' => \\\$this->stageStats\(0, 0\)\]/", $src);
    }
}
