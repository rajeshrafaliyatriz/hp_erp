<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Products\CapabilityMapping;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Run\RunBudget;
use App\Domain\Signals\Run\VerifyLimits;
use App\Domain\Signals\Scholar\SchoolNameFallback;
use App\Support\EnvFileFallback;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests: no application boot, no database, no provider, no network, no .env read. Tests named "source text" inspect code that needs a
 * database or a provider to run; they prove wiring only. Everything else executes the real classes.
 */
class VerifyIsolationAndAccountingTest extends TestCase
{
    protected function tearDown(): void
    {
        RunBudget::disarm();
    }

    private function source(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../../' . $relative));
    }

    private const GOOD = ['max_search_calls' => 24, 'max_page_requests' => 60, 'max_ai_requests' => 6, 'ai_failover_attempts' => 2];
    private const PRODUCTS = ['scholarclone', 'enterprise_brain', 'g2g'];

    // ── 1. credential isolation ────────────────────────────────────────────────────

    public function test_the_main_env_file_is_never_a_fallback_in_a_verification_run(): void
    {
        $this->assertFalse(EnvFileFallback::allowed(['verify', 'verify'], false), 'verify: no fallback');
        $this->assertFalse(EnvFileFallback::allowed(['local', 'Verify'], false), 'either name saying verify is enough, any case');
        $this->assertFalse(EnvFileFallback::allowed([' VERIFY '], false));
    }

    public function test_ordinary_and_test_environments_keep_their_existing_behaviour(): void
    {
        $this->assertTrue(EnvFileFallback::allowed(['local', 'local'], false), 'normal use is unchanged');
        $this->assertTrue(EnvFileFallback::allowed(['production', 'production'], false));
        $this->assertFalse(EnvFileFallback::allowed(['local'], true), 'unit tests still never read the file');
    }

    public function test_source_text_both_provider_fallbacks_go_through_the_same_gate(): void
    {
        $tavily = $this->source('app/Domain/Signals/Opportunities/TavilySearchProvider.php');
        $ai = $this->source('app/Domain/AI/Configuration/ProviderCatalog.php');

        $this->assertStringContainsString('EnvFileFallback::allowedHere() && ($key', $tavily);
        $this->assertStringContainsString('EnvFileFallback::allowedHere() && ($value', $ai);
        $this->assertStringNotContainsString('app()->runningUnitTests() && ($key', $tavily);
        $this->assertStringNotContainsString('app()->runningUnitTests() && ($value', $ai);
        // Without the fallback a missing key stays missing and the provider reports itself unconfigured (isConfigured -> usableKey).
        $this->assertStringContainsString('return SearchProviderFactory::usableKey($this->key());', $tavily);
    }

    // ── 2. the verification plan ───────────────────────────────────────────────────

    private function plan(array $options = [], array $config = self::GOOD, array $runtime = []): array
    {
        return VerifyLimits::plan($options + ['tenant' => '7', 'product' => 'scholarclone', 'batches' => 1, 'trigger' => 'manual'], $config, self::PRODUCTS, $runtime);
    }

    public function test_exactly_one_tenant_product_and_batch_is_accepted(): void
    {
        $p = $this->plan();

        $this->assertTrue($p['ok'], implode('; ', $p['errors']));
        $this->assertSame(7, $p['tenant']);
        $this->assertSame('scholarclone', $p['product']);
        $this->assertSame(self::GOOD, $p['limits']);
    }

    public function test_ambiguous_or_missing_tenant_and_product_are_refused(): void
    {
        foreach ([['tenant' => null], ['tenant' => ''], ['tenant' => '0'], ['tenant' => '7,8'], ['tenant' => 'all'], ['tenant' => '-3'],
            ['product' => null], ['product' => ''], ['product' => 'scholarclone,g2g'], ['product' => 'nonsense']] as $bad) {
            $p = $this->plan($bad);
            $this->assertFalse($p['ok'], json_encode($bad));
            $this->assertNull($p['tenant']);
            $this->assertSame([], $p['limits'], 'a refused plan hands out no limits');
        }
    }

    public function test_more_than_one_batch_a_scheduled_trigger_or_scheduled_research_is_refused(): void
    {
        $this->assertFalse($this->plan(['batches' => 2])['ok']);
        $this->assertFalse($this->plan(['batches' => 6])['ok']);
        $this->assertFalse($this->plan(['trigger' => 'scheduled'])['ok']);
        $this->assertFalse($this->plan([], self::GOOD, ['scheduled_research' => true])['ok']);
    }

    public function test_a_missing_zero_or_oversized_ceiling_refuses_the_run_before_anything_is_called(): void
    {
        foreach (array_keys(self::GOOD) as $key) {
            $missing = self::GOOD;
            unset($missing[$key]);
            $this->assertFalse($this->plan([], $missing)['ok'], "missing {$key}");
            $this->assertFalse($this->plan([], [$key => 0] + self::GOOD)['ok'], "zero {$key}");
            $this->assertFalse($this->plan([], [$key => VerifyLimits::CEILINGS[$key] + 1] + self::GOOD)['ok'], "above the absolute ceiling: {$key}");
            $this->assertTrue($this->plan([], [$key => VerifyLimits::CEILINGS[$key]] + self::GOOD)['ok'], "at the ceiling is allowed: {$key}");
        }
        $this->assertFalse($this->plan([], [])['ok'], 'no configuration at all');
        $this->assertFalse($this->plan([], ['max_search_calls' => 'many'] + self::GOOD)['ok']);
    }

    public function test_hard_maxima_are_the_configured_ceilings_and_their_product(): void
    {
        $m = VerifyLimits::maxima(self::GOOD);

        $this->assertSame(24, $m['search_calls']);
        $this->assertSame(60, $m['page_requests']);
        $this->assertSame(6, $m['ai_requests']);
        $this->assertSame(12, $m['ai_provider_requests'], '6 AI requests x 2 credential attempts');
        $this->assertSame(44, VerifyLimits::CEILINGS['max_search_calls'], 'the code ceiling is 22 searches, each retried once');
        $o = VerifyLimits::configOverrides(self::GOOD);
        $this->assertSame(2, $o['ai.failover.max_attempts']);
        $this->assertSame(2, $o['ai.failover.max_transient_attempts']);
        $this->assertSame(1, $o['signals.official_site.max_schools_per_run']);
    }

    public function test_the_budget_refuses_the_call_after_the_ceiling_and_is_off_when_not_armed(): void
    {
        $this->assertTrue(RunBudget::take(RunBudget::SEARCH), 'not armed: everything is allowed (ordinary runs)');
        RunBudget::arm(VerifyLimits::toBudget(['max_search_calls' => 2, 'max_page_requests' => 1, 'max_ai_requests' => 1, 'ai_failover_attempts' => 1]));

        $this->assertTrue(RunBudget::take(RunBudget::SEARCH));
        $this->assertTrue(RunBudget::take(RunBudget::SEARCH));
        $this->assertFalse(RunBudget::take(RunBudget::SEARCH), 'the third search is refused');
        $this->assertTrue(RunBudget::take(RunBudget::PAGE));
        $this->assertFalse(RunBudget::take(RunBudget::PAGE));
        $this->assertTrue(RunBudget::take(RunBudget::AI));
        $this->assertFalse(RunBudget::take(RunBudget::AI));
        $r = RunBudget::report();
        $this->assertSame(['search_calls' => 2, 'page_requests' => 1, 'ai_requests' => 1], $r['used']);
        $this->assertSame(['search_calls' => 1, 'page_requests' => 1, 'ai_requests' => 1], $r['refused']);
        RunBudget::disarm();
        $this->assertTrue(RunBudget::take(RunBudget::SEARCH));
        $this->assertFalse(RunBudget::armed());
    }

    public function test_source_text_every_external_call_site_takes_from_the_budget_and_the_command_arms_it_first(): void
    {
        $r = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertSame(2, substr_count($r, 'RunBudget::available(RunBudget::SEARCH)'), 'the loop checks before the search and before its 429 retry; the provider spends');
        $this->assertStringNotContainsString('RunBudget::take(RunBudget::SEARCH)', $r, 'the researcher no longer spends on behalf of the provider');
        $fetcher = $this->source('app/Domain/Signals/Support/SafeUrlFetcher.php');
        $this->assertStringContainsString('Run\RunBudget::take(', $fetcher);
        $this->assertStringContainsString('RunBudget::PAGE', $fetcher);
        $this->assertLessThan(strpos($fetcher, '->get($url)'), strpos($fetcher, 'RunBudget::take('), 'the budget is taken before the request, inside the redirect loop');
        $this->assertStringContainsString('RunBudget::take(RunBudget::AI)', $this->source('app/Domain/Signals/Support/StructuredAi.php'));
        $this->assertStringContainsString('RunBudgetExceeded $e) {', $this->source('app/Domain/Signals/Support/StructuredAi.php'), 'a budget stop is never retried');

        $c = $this->source('app/Console/Commands/RunOpportunityResearch.php');
        $this->assertLessThan(strpos($c, 'app(OpportunityResearcher::class)'), strpos($c, 'VerifyLimits::plan('), 'the plan is checked before the researcher is built');
        $this->assertLessThan(strpos($c, 'app(OpportunityResearcher::class)'), strpos($c, 'RunBudget::arm('));
        $this->assertStringContainsString('RunBudget::disarm()', $c);
        $this->assertLessThan(strpos($c, 'VerifyLimits::plan('), strpos($c, 'SignalsVerifyPreflight::check()'), 'the environment guard still comes first');
    }

    // ── 3. accounting distinctions ─────────────────────────────────────────────────

    private function diag(): array
    {
        return [
            'planned' => ['a', 'b', 'c', 'd', 'e', 'f'], 'executed' => ['a', 'b', 'c', 'd'], 'failed' => ['d' => 'http_500'],
            'productive' => ['a', 'b'], 'empty' => ['c'],
            'skipped' => ['e' => 'searched_within_cache_window', 'f' => 'verify_budget'], 'returned' => 8,
        ];
    }

    public function test_query_counts_separate_results_empty_failed_cache_hits_and_other_skips(): void
    {
        $q = RunAccounting::build($this->diag(), [], ['sources' => 4, 'candidates' => 0])['queries'];

        $this->assertSame(6, $q['planned']);
        $this->assertSame(4, $q['attempted']);
        $this->assertSame(3, $q['succeeded'], 'attempted and not failed');
        $this->assertSame(2, $q['with_results']);
        $this->assertSame(1, $q['empty'], 'a search that worked but found nothing');
        $this->assertSame(1, $q['failed']);
        $this->assertSame(1, $q['cache_hits']);
        $this->assertSame(1, $q['skipped'], 'skipped excludes cache hits');
        $this->assertSame(['searched_within_cache_window' => 1, 'verify_budget' => 1], $q['skipped_by_reason'], 'the reasons are in the stats, not only the log');
        $this->assertSame($q['succeeded'], $q['with_results'] + $q['empty']);
        $this->assertSame($q['planned'], $q['attempted'] + $q['cache_hits'] + $q['skipped'], 'every planned search is accounted for exactly once');
    }

    public function test_a_run_that_did_not_record_result_kinds_says_unavailable_not_zero(): void
    {
        $q = RunAccounting::build(['planned' => ['a'], 'executed' => ['a'], 'returned' => 3], [], ['sources' => 3, 'candidates' => 0])['queries'];

        $this->assertNull($q['with_results']);
        $this->assertNull($q['empty']);
        $this->assertSame(1, $q['succeeded']);
    }

    public function test_registry_counts_separate_attempted_searched_results_empty_failed_cache_and_other_skips(): void
    {
        $diag = ['planned' => ['reg-1', 'reg-2', 'reg-3', 'reg-4', 'reg-5'], 'executed' => ['reg-1', 'reg-2', 'reg-3'], 'failed' => ['reg-3' => 'http_429'],
            'productive' => ['reg-1'], 'empty' => ['reg-2'],
            'skipped' => ['reg-4' => 'searched_within_cache_window', 'reg-5' => 'time_budget'],
            'registry' => ['selected' => ['1', '2', '3', '4', '5'], 'eligible' => 80, 'skipped_reason' => null]];
        $r = RunAccounting::build($diag, [], ['sources' => 1, 'candidates' => 0])['registry'];

        $this->assertSame(5, $r['selected']);
        $this->assertSame(3, $r['attempted']);
        $this->assertSame(2, $r['searched'], 'searched means ran and did not fail');
        $this->assertSame(1, $r['succeeded_with_results']);
        $this->assertSame(1, $r['succeeded_empty']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(1, $r['served_from_cache']);
        $this->assertSame(1, $r['skipped_other']);
        $this->assertFalse($r['verified']);
    }

    public function test_verification_budget_is_reported_only_when_present(): void
    {
        $with = RunAccounting::build(['budget' => ['limits' => ['search_calls' => 24], 'used' => ['search_calls' => 5], 'refused' => []]], [], ['sources' => 0, 'candidates' => 0]);

        $this->assertSame(5, $with['budget']['used']['search_calls']);
        $this->assertNull(RunAccounting::build([], [], ['sources' => 0, 'candidates' => 0])['budget']);
    }

    public function test_name_search_counts_schools_left_out_for_missing_context(): void
    {
        $s = RunAccounting::build(['name_search' => ['accounts' => [4], 'results' => [], 'missing_context' => 5]], [], ['sources' => 0, 'candidates' => 0])['name_search'];

        $this->assertSame(5, $s['skipped_missing_context']);
        $this->assertSame(1, $s['schools_selected']);
    }

    // ── name-only schools: no invented place ───────────────────────────────────────

    public function test_a_school_with_no_place_is_not_given_one(): void
    {
        $this->assertFalse(SchoolNameFallback::hasPlace(['name' => 'Shree Vidyalaya']));
        $this->assertFalse(SchoolNameFallback::hasPlace(['name' => 'X', 'city' => ' ', 'state' => '', 'attributes' => []]));
        $this->assertTrue(SchoolNameFallback::hasPlace(['name' => 'X', 'city' => 'Surat']));
        $this->assertTrue(SchoolNameFallback::hasPlace(['name' => 'X', 'state' => 'Gujarat']));
        $this->assertTrue(SchoolNameFallback::hasPlace(['name' => 'X', 'attributes' => ['district' => 'Anand']]));

        $q = SchoolNameFallback::queries(['id' => 2, 'name' => 'Shree Vidyalaya'], 60, 2);
        $this->assertSame('Shree Vidyalaya school', $q[0]['q'], 'if it is searched at all (policy off), the query holds only the name');
    }

    public function test_source_text_planner_applies_the_place_policy_in_the_selection_not_after_it(): void
    {
        $p = $this->source('app/Domain/Signals/Icp/IcpResearchPlanner.php');

        $this->assertStringContainsString("config('signals.icp.name_search_require_place', true)", $p);
        $this->assertStringContainsString('$hasPlace($q)', $p);
        $this->assertLessThan(strpos($p, '->limit($nameSlots)'), strpos($p, '$hasPlace = '), 'schools without a place are filtered before the slots are filled, so they never block them');
        $this->assertStringContainsString("'name_search_missing_context' => \$nameMissingContext", $p);
    }

    // ── 4. missing product mapping never qualifies anything ─────────────────────────

    public function test_missing_mapping_is_reported_and_cannot_be_used_for_a_decision(): void
    {
        $cfg = include __DIR__ . '/../../../config/signals_capability_map.php';
        $e = CapabilityMapping::explain(['core', 'learn'], ['off_1' => ['name' => 'ERP']], $cfg);

        $this->assertSame(CapabilityMapping::UNMAPPED, $e['status']);
        $this->assertFalse(CapabilityMapping::usableForDecisions($e));
        $this->assertStringContainsString('does not decide qualification', $e['note']);
        // The annotation is attached to the assessment only; nothing in the researcher reads the mapping status to accept or reject.
        $r = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertSame(0, substr_count($r, 'usableForDecisions'));
        $this->assertStringContainsString("\$assessment['capability_signal']['mapping'] = CapabilityMapping::explain(", $r);
    }

    // ── hard AI / search caps at the request boundary ──────────────────────────────

    public function test_the_seventh_logical_ai_call_and_thirteenth_provider_request_are_refused(): void
    {
        RunBudget::arm(VerifyLimits::toBudget(self::GOOD));

        for ($i = 1; $i <= 6; $i++) {
            $this->assertTrue(RunBudget::take(RunBudget::AI), "logical AI call {$i}");
        }
        $this->assertFalse(RunBudget::take(RunBudget::AI), 'the 7th logical AI call is refused');
        for ($i = 1; $i <= 12; $i++) {
            $this->assertTrue(RunBudget::take(RunBudget::AI_PROVIDER), "provider request {$i}");
        }
        $this->assertFalse(RunBudget::take(RunBudget::AI_PROVIDER), 'the 13th provider request is refused');
        $this->expectException(\App\Domain\Signals\Run\RunBudgetExceeded::class);
        RunBudget::takeOrFail(RunBudget::AI_PROVIDER);
    }

    public function test_the_provider_ceiling_is_fixed_when_armed_and_does_not_follow_later_config_changes(): void
    {
        $budget = VerifyLimits::toBudget(self::GOOD);
        RunBudget::arm($budget);
        // A default that changes, or a failover setting that is missing or malformed, cannot reach the armed budget.
        $this->assertSame(12, $budget[RunBudget::AI_PROVIDER]);
        $this->assertFalse(VerifyLimits::plan(['tenant' => '7', 'product' => 'scholarclone', 'batches' => 1, 'trigger' => 'manual'], ['ai_failover_attempts' => 'abc'] + self::GOOD, self::PRODUCTS)['ok']);
        $this->assertFalse(VerifyLimits::plan(['tenant' => '7', 'product' => 'scholarclone', 'batches' => 1, 'trigger' => 'manual'], array_diff_key(self::GOOD, ['ai_failover_attempts' => 1]), self::PRODUCTS)['ok']);
        for ($i = 0; $i < 12; $i++) {
            RunBudget::takeOrFail(RunBudget::AI_PROVIDER);
        }
        $this->assertFalse(RunBudget::available(RunBudget::AI_PROVIDER), 'available() reports the ceiling without spending');
        $this->assertSame(12, RunBudget::report()['used'][RunBudget::AI_PROVIDER]);
    }

    public function test_source_text_every_ai_and_search_http_request_spends_first_and_a_ceiling_is_never_failed_over(): void
    {
        $ai = $this->source('app/Domain/AI/Support/AiModelClient.php');

        $this->assertSame(2, substr_count($ai, 'RunBudget::takeOrFail(RunBudget::AI_PROVIDER)'), 'one at each of the two HTTP call sites');
        $this->assertSame(2, substr_count($ai, 'Http::timeout('), 'there are exactly two provider HTTP call sites');
        // Gemini: the spend is inside the $send closure, so its "ask again without thinking" request is counted too.
        $closure = strpos($ai, '$send = function (array $body)');
        $this->assertNotFalse($closure);
        $this->assertLessThan(strpos($ai, 'Http::timeout(', $closure), strpos($ai, 'takeOrFail', $closure));
        $this->assertSame(2, substr_count($ai, '$send($payload)'), 'both Gemini requests go through the same closure');
        // OpenAI-compatible: the spend comes before the request.
        $compat = strpos($ai, 'private function callOpenAiCompatible');
        $this->assertLessThan(strpos($ai, 'Http::timeout(', $compat), strpos($ai, 'takeOrFail', $compat));
        // A ceiling stops the loop; it is rethrown before metering and before the classifier can pick "rotate".
        $this->assertLessThan(strpos($ai, '$verdict = $this->failures->classify'), strpos($ai, 'instanceof RunBudgetExceeded'));

        foreach (['Tavily', 'Brave'] as $name) {
            $p = $this->source("app/Domain/Signals/Opportunities/{$name}SearchProvider.php");
            $this->assertSame(1, substr_count($p, 'Http::timeout('));
            $this->assertLessThan(strpos($p, 'Http::timeout('), strpos($p, 'RunBudget::takeOrFail('), "{$name}: spend precedes the request");
        }
        $logical = $this->source('app/Domain/Signals/Support/StructuredAi.php');
        $this->assertLessThan(strpos($logical, '$this->client->complete('), strpos($logical, 'RunBudget::take(RunBudget::AI)'));
    }

    public function test_source_text_no_other_http_call_site_exists_in_the_research_path(): void
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../app/Domain/Signals', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && preg_match('/Http::|curl_init|get_headers/', (string) file_get_contents($f->getPathname()))) {
                $found[] = $f->getFilename();
            }
        }
        sort($found);

        $this->assertSame(['BraveSearchProvider.php', 'SafeUrlFetcher.php', 'TavilySearchProvider.php'], $found, 'every outbound HTTP call in Signals is one of the three budgeted sites');
    }

    // ── port and account pinned ────────────────────────────────────────────────────

    public function test_the_guard_pins_the_verification_port_and_account(): void
    {
        $conn = fn (array $x = []) => ['mysql' => array_merge(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3308', 'username' => 'g2g_verify', 'database' => 'g2g_signals_verify'], $x)];
        $check = fn (array $x = []) => \App\Domain\Signals\Run\VerifyEnvironmentGuard::effective('verify', 'mysql', $conn($x));

        \App\Domain\Signals\Run\VerifyEnvironmentGuard::assert($check());
        foreach ([['port' => '3306'], ['port' => '3307'], ['port' => ''], ['username' => 'root'], ['username' => '']] as $bad) {
            try {
                \App\Domain\Signals\Run\VerifyEnvironmentGuard::assert($check($bad));
                $this->fail('accepted ' . json_encode(array_keys($bad)));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Verification preflight failed', $e->getMessage());
                $this->assertStringNotContainsString('root', str_replace("'root'", '', $e->getMessage()) . 'x', 'no credentials in the message');
            }
        }
        $noPort = \App\Domain\Signals\Run\VerifyEnvironmentGuard::effective('verify', 'mysql', ['mysql' => ['host' => '127.0.0.1', 'database' => 'g2g_signals_verify', 'username' => 'g2g_verify']]);
        $this->expectException(\RuntimeException::class);
        \App\Domain\Signals\Run\VerifyEnvironmentGuard::assert($noPort);
    }
}
