<?php

namespace Tests\Unit;

use App\Console\Commands\SignalsVerifySources;
use PHPUnit\Framework\TestCase;

/** Source-level guards for the pipeline fixes (no database, no network). */
class PipelineHardeningTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_a_passing_network_problem_is_never_stored_as_a_source_verification_result(): void
    {
        foreach (['dns', 'timeout', 'connection', 'http_429', 'http_5xx'] as $transient) {
            $this->assertContains($transient, SignalsVerifySources::TRANSIENT_FAILURES);
        }
        foreach (['robots_disallowed', 'http_404', 'no_announcement_links', 'non_html'] as $definite) {
            $this->assertNotContains($definite, SignalsVerifySources::TRANSIENT_FAILURES, "{$definite} is a real answer about the source");
        }
        $this->assertMatchesRegularExpression('/TRANSIENT_FAILURES, true\)\) \{.*?continue;.*?\$store->record/s', $this->src('app/Console/Commands/SignalsVerifySources.php'), 'a transient failure skips record()');
    }

    public function test_rejection_counts_are_reset_for_every_run(): void
    {
        $this->assertMatchesRegularExpression('/\$this->diag = \$this->freshDiagnostics\(\);.*?\$this->rejections = \[\];/s', $this->src('app/Domain/Signals/Opportunities/OpportunityResearcher.php'));
    }

    public function test_date_correction_expiry_uses_the_resolved_icp_not_only_the_built_in_one(): void
    {
        $src = $this->src('app/Domain/Signals/Scholar/SignalDateCorrection.php');
        $this->assertStringContainsString('$this->packFor($o)->decayDays(', $src);
        $this->assertStringNotContainsString('IcpPack::forRun()->decayDays(', $src);
        $this->assertStringContainsString('return IcpPack::forRun();', $src, 'it still falls back to the built-in definition');
    }

    public function test_the_qualification_gate_and_scoring_files_are_untouched_by_this_work(): void
    {
        // The gate's hard-coded list is documented, not changed: custom products stay blocked from generation until it is extended deliberately.
        $this->assertStringContainsString("public const BUSINESS_FIT = ['eb', 'scholar', 'g2g', 'multiple'];", $this->src('app/Domain/Signals/Market/QualificationGate.php'));
    }

    public function test_registry_source_selection_moves_on_with_todays_earlier_runs_like_the_query_catalogue(): void
    {
        $r = $this->src('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("'shift' => (int) config('signals.icp.batch_shift', 0) + $this->registryShift", $r);
        $this->assertStringContainsString('IcpResearchPlanner::attemptsToday($tenantId, $this->ctx->key)', $r);
        $this->assertStringContainsString('public static function attemptsToday(', $this->src('app/Domain/Signals/Icp/IcpResearchPlanner.php'));
    }

    public function test_the_per_host_interval_only_waits_and_is_off_in_tests(): void
    {
        $f = $this->src('app/Domain/Signals/Support/SafeUrlFetcher.php');
        $this->assertStringContainsString('self::limiter()->wait($host);', $f);
        $this->assertLessThan(strpos($f, 'Http::withOptions('), strpos($f, 'self::limiter()->wait($host);'), 'waits BEFORE the request');
        $this->assertStringContainsString("'min_host_interval_ms' => (int) env('SIGNALS_MIN_HOST_INTERVAL_MS', 300)", $this->src('config/signals.php'));
        $this->assertStringContainsString('SIGNALS_MIN_HOST_INTERVAL_MS" value="0"', $this->src('phpunit.xml'));
    }
}
