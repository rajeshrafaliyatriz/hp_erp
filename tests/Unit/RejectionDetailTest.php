<?php

namespace Tests\Unit;

use App\Domain\Signals\Run\RunAccounting;
use PHPUnit\Framework\TestCase;

/** Internal rejection detail: additive, counts only, external reason codes untouched. Pure. */
class RejectionDetailTest extends TestCase
{
    private function src(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
    }

    public function test_detail_is_surfaced_with_counts_and_external_codes_unchanged(): void
    {
        $diag = ['rejection_detail' => ['quote_not_in_source' => ['quote_not_found' => 2, 'institution_not_named_in_source' => 3], 'stale_source' => ['stale_by_publication_date' => 1]]];
        $stats = RunAccounting::build($diag, ['quote_not_in_source' => 5, 'stale_source' => 1], ['sources' => 4, 'candidates' => 6]);

        $this->assertSame(['quote_not_in_source' => ['institution_not_named_in_source' => 3, 'quote_not_found' => 2], 'stale_source' => ['stale_by_publication_date' => 1]], $stats['rejection_detail']);
        $this->assertSame(6, $stats['candidates']['rejected_before_gate']);   // old meaning unchanged
        $codes = array_column($stats['zero_reasons'], 'code');
        $this->assertContains('quote_not_in_source', $codes);
        $this->assertNotContains('institution_not_named_in_source', $codes);
    }

    public function test_detail_is_null_when_not_recorded(): void
    {
        $stats = RunAccounting::build([], [], ['sources' => 0, 'candidates' => 0]);
        $this->assertNull($stats['rejection_detail']);
    }

    public function test_old_keys_are_unchanged(): void
    {
        $stats = RunAccounting::build([], [], ['sources' => 0, 'candidates' => 0]);
        foreach (['queries', 'results', 'pages', 'candidates', 'outcome', 'errors_by_stage', 'registry', 'direct', 'budget', 'name_search', 'zero_reasons', 'next_action'] as $k) {
            $this->assertArrayHasKey($k, $stats);
        }
        $this->assertNull($stats['budget']);   // still null on an ordinary run
    }

    public function test_researcher_keeps_single_external_call_site_and_detail_wiring(): void
    {
        $src = $this->src();
        $this->assertSame(1, substr_count($src, "reject('quote_not_in_source')"));
        foreach (['quote_not_found', 'institution_not_named_in_source', "rejection_detail"] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        // Counts only: the detail counter never stores text.
        $this->assertStringContainsString("\$this->diag['rejection_detail'][\$code][\$detail] = (", $src);
    }

    public function test_per_run_counters_are_reset_at_start_of_run(): void
    {
        $src = $this->src();
        $this->assertMatchesRegularExpression('/\$this->diag = \$this->freshDiagnostics\(\);/', $src);
        $fresh = substr($src, (int) strpos($src, 'function freshDiagnostics'), 900);
        foreach (["'rejection_detail' => []", "'ai' =>", "'page_budget' =>"] as $k) {
            $this->assertStringContainsString($k, $fresh);
        }
        $this->assertStringContainsString('$this->rejectedDetail = [];', $src);
    }
}
