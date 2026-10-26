<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Run\SchoolCheckPolicy;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Sources\SourceSelector;
use App\Domain\Signals\Support\SearchCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests: no application boot, no database, no provider, no network. Tests marked "source text" inspect the researcher's code (it needs a
 * database to run) and prove wiring only; every other test executes the real classes.
 */
class SearchCacheIdentityAndSchoolCheckTest extends TestCase
{
    private function cache(int $hours = 4): SearchCache
    {
        return new SearchCache(new Repository(new ArrayStore()), $hours);
    }

    private function source(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $relative);
    }

    private const RESULTS = [['url' => 'https://a.example/x']];

    // ── Fix 1: cache identity ──────────────────────────────────────────────────────

    public function test_same_query_and_same_domain_reuses_the_entry_even_if_spelled_differently(): void
    {
        $c = $this->cache();
        $a = ['topic' => 'general', 'days' => 45, 'domains' => ['WWW.Tenders.GJ.example'], 'provider' => 'tavily'];
        $b = ['provider' => 'Tavily', 'domains' => ['tenders.gj.example', 'tenders.gj.example'], 'days' => 45, 'topic' => 'general'];

        $this->assertTrue($c->remember(6, 'School ERP  tender Gujarat', self::RESULTS, $a));
        $this->assertTrue($c->seen(6, 'school erp tender gujarat', $b), 'case, spacing, www., duplicate and option order do not matter');
        $this->assertSame($c->key(6, 'x', $a), $c->key(6, 'x', $b));
    }

    public function test_same_query_with_a_different_domain_does_not_collide(): void
    {
        $c = $this->cache();
        $c->remember(6, 'school tender Gujarat', self::RESULTS, ['domains' => ['one.example'], 'days' => 45]);

        $this->assertFalse($c->seen(6, 'school tender Gujarat', ['domains' => ['two.example'], 'days' => 45]));
        $this->assertFalse($c->seen(6, 'school tender Gujarat', ['days' => 45]), 'an unrestricted search is not the restricted one');
        $this->assertTrue($c->seen(6, 'school tender Gujarat', ['domains' => ['one.example'], 'days' => 45]));
    }

    public function test_other_result_affecting_parameters_and_the_tenant_also_separate_entries(): void
    {
        $c = $this->cache();
        $base = ['topic' => 'news', 'days' => 30, 'domains' => ['d.example'], 'provider' => 'tavily'];
        $c->remember(6, 'q', self::RESULTS, $base);

        foreach ([['topic' => 'general'], ['days' => 60], ['provider' => 'brave']] as $change) {
            $this->assertFalse($c->seen(6, 'q', $change + $base), json_encode($change));
        }
        $this->assertFalse($c->seen(7, 'q', $base), 'per organisation');
    }

    public function test_a_caller_with_no_options_still_works(): void
    {
        $c = $this->cache();

        $this->assertTrue($c->remember(6, 'plain query', self::RESULTS));
        $this->assertTrue($c->seen(6, 'plain query'));
        $this->assertFalse($c->seen(6, 'other query'));
    }

    public function test_real_registry_sources_with_the_same_query_text_get_distinct_cache_identities(): void
    {
        $registry = new SourceRegistry(include __DIR__ . '/../../../config/signals_sources.php');
        $specs = (new SourceSelector($registry))->select(Carbon::parse('2026-10-26'), 40, ['can_restrict_domains' => true])['specs'];
        $byText = [];
        foreach ($specs as $s) {
            $byText[$s['q']][] = $s;
        }
        $shared = array_filter($byText, fn ($group) => count($group) > 1);

        // Since the queries carry each source's own name, the real inventory no longer shares query texts. The identity must hold either way, so
        // the collision case is also built explicitly: two sources, one query text, two domains.
        $this->assertSame([], $shared, 'every real registry source now has its own query text');
        $same = fn (string $domain) => ['q' => 'school tender Gujarat', 'topic' => 'general', 'days' => 45, 'domains' => [$domain], 'provider' => 'tavily'];
        $shared = ['school tender Gujarat' => [$same('one.example') + ['id' => 'reg-a'], $same('two.example') + ['id' => 'reg-b']]];
        $c = $this->cache();
        $keys = [];
        foreach ($specs as $s) {
            $keys[] = $c->key(6, $s['q'], ['topic' => $s['topic'], 'days' => $s['days'], 'domains' => $s['domains'], 'provider' => 'tavily']);
        }
        $this->assertCount(count($specs), array_unique($keys), 'every selected source has its own cache key');

        // The audited sequence: the first source of a shared text succeeds; the next one must still run.
        $first = array_values($shared)[0][0];
        $second = array_values($shared)[0][1];
        $opt = fn ($s) => ['topic' => $s['topic'], 'days' => $s['days'], 'domains' => $s['domains'], 'provider' => 'tavily'];
        $c->remember(6, $first['q'], self::RESULTS, $opt($first));
        $this->assertTrue($c->seen(6, $first['q'], $opt($first)));
        $this->assertFalse($c->seen(6, $second['q'], $opt($second)), 'the second source is not skipped as "already searched"');
    }

    public function test_failed_and_empty_searches_are_not_remembered_and_can_be_retried(): void
    {
        $c = $this->cache();
        $o = ['domains' => ['d.example'], 'days' => 45];

        $this->assertFalse($c->remember(6, 'q', [], $o), 'an empty result is not remembered');
        $this->assertFalse($c->seen(6, 'q', $o), 'so it is retried');
        // A failed search never reaches remember() at all; the key stays free.
        $this->assertFalse($c->seen(6, 'q', $o));
        $this->assertTrue($c->remember(6, 'q', self::RESULTS, $o), 'the retry that succeeds is remembered');
        $this->assertTrue($c->seen(6, 'q', $o));
        $this->assertFalse($this->cache(0)->remember(6, 'q', self::RESULTS, $o), 'hours = 0 turns caching off');
    }

    public function test_a_cache_hit_is_accounted_as_skipped_never_as_searched(): void
    {
        $diag = ['planned' => ['reg-a', 'reg-b', 'reg-c'], 'executed' => ['reg-a'], 'failed' => [],
            'skipped' => ['reg-b' => 'searched_within_cache_window', 'reg-c' => 'time_budget'], 'returned' => 3,
            'registry' => ['selected' => ['a', 'b', 'c'], 'eligible' => 50, 'skipped_reason' => null]];
        $s = RunAccounting::build($diag, [], ['sources' => 3, 'candidates' => 0]);

        $this->assertSame(3, $s['registry']['selected']);
        $this->assertSame(1, $s['registry']['attempted'], 'only the executed search');
        $this->assertSame(1, $s['registry']['searched']);
        $this->assertSame(1, $s['registry']['skipped_other'], 'the time-budget skip is not a cache hit');
        $this->assertSame(1, $s['registry']['served_from_cache'], 'a cache hit is counted apart from other skips');
        $this->assertSame(1, $s['queries']['attempted']);
        $this->assertSame(1, $s['queries']['cache_hits']);
        $this->assertSame(1, $s['queries']['skipped']);
        $this->assertSame(['searched_within_cache_window' => 1, 'time_budget' => 1], $s['queries']['skipped_by_reason']);
    }

    public function test_source_text_researcher_keys_the_cache_by_the_whole_search(): void
    {
        $src = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertStringContainsString('$searchCache->seen($tenantId, $query, $cacheOptions)', $src);
        $this->assertStringContainsString('$searchCache->remember($tenantId, $query, $results, $cacheOptions)', $src);
        $this->assertStringContainsString("'domains' => \$options['include_domains'] ?? []", $src);
        $this->assertStringContainsString("'provider' => \$search->name()", $src);
        $this->assertLessThan(strpos($src, '$searchCache->seen('), strpos($src, '$cacheOptions = '), 'the identity is built before the lookup');
        $this->assertStringContainsString('if (! $named) {' . "\n" . '                $searchCache->remember', str_replace("\r\n", "\n", $src), 'still written only after a successful response');
    }

    // ── Fix 2: last_checked_at ─────────────────────────────────────────────────────

    public function test_all_searches_failing_leaves_every_school_unchecked(): void
    {
        $done = SchoolCheckPolicy::completed([1, 2], ['account-1', 'account-name-2-0'], ['account-1', 'account-name-2-0']);

        $this->assertSame([], $done);
    }

    public function test_a_skipped_or_never_attempted_school_is_not_checked(): void
    {
        // School 1's search was skipped (time budget): it is in the plan but never executed. School 3 was never planned.
        $done = SchoolCheckPolicy::completed([1, 2], ['account-2'], []);

        $this->assertSame([2], $done);
        $this->assertNotContains(1, $done);
        $this->assertNotContains(3, $done);
        $this->assertSame([], SchoolCheckPolicy::completed([1], [], []), 'nothing executed, nothing checked');
        $this->assertSame([], SchoolCheckPolicy::completed([], ['account-1'], []), 'a school that was not planned is never touched');
    }

    public function test_a_successful_search_marks_the_school_checked_even_when_it_found_nothing(): void
    {
        $this->assertSame([5], SchoolCheckPolicy::completed([5], ['account-5'], []));
        $this->assertSame([9], SchoolCheckPolicy::completed([9], ['account-name-9-0'], []));
    }

    public function test_partial_failure_is_not_checked_so_the_failed_search_is_retried(): void
    {
        // A school without a website with two name variants: one worked, one failed.
        $done = SchoolCheckPolicy::completed([9, 4], ['account-name-9-0', 'account-name-9-1', 'account-4'], ['account-name-9-1']);

        $this->assertSame([4], $done, 'school 9 stays unchecked; school 4 (all its searches fine) is checked');
    }

    public function test_schools_with_and_without_a_website_follow_the_same_policy_and_ids_do_not_cross(): void
    {
        $executed = ['account-1', 'account-name-1-0', 'account-12', 'account-name-12-0', 'catalogue-x'];
        $failed = ['account-name-12-0'];

        // Id 1 (website 'account-1' and, hypothetically, a name search) both fine; id 12 has a failed name search.
        $this->assertSame([1], SchoolCheckPolicy::completed([1, 12], $executed, $failed));
        // 'account-12' must not be read as belonging to school 1, and 'account-1' not to school 12.
        $this->assertSame([12], SchoolCheckPolicy::completed([12], ['account-12'], []));
        $this->assertSame([], SchoolCheckPolicy::completed([2], ['account-12', 'account-name-12-0'], []));
    }

    public function test_source_text_researcher_no_longer_marks_schools_checked_unconditionally(): void
    {
        $src = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertSame(1, substr_count($src, "update(['last_checked_at' => now()])"), 'exactly one place advances the recheck timestamp');
        $this->assertStringContainsString('SchoolCheckPolicy::completed(', $src);
        $this->assertStringContainsString("array_merge(\$watched, (array) (\$nameSearched ?? []))", $src, 'schools with and without a website go through the same policy');
        $this->assertStringNotContainsString("TargetAccount::whereIn('id', \$watched)", $src);
    }
}
