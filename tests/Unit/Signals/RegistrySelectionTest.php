<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Products\CapabilityMapping;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Scholar\SchoolNameFallback;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Sources\SourceSelector;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests: no application boot, no database, no provider, no network. Every test that is a SOURCE-TEXT assertion says so in its name or comment;
 * all the others execute the real classes with fixtures.
 */
class RegistrySelectionTest extends TestCase
{
    private function config(): array
    {
        return include __DIR__ . '/../../../config/signals_sources.php';
    }

    private function registry(?array $config = null): SourceRegistry
    {
        return new SourceRegistry($config ?? $this->config());
    }

    private function source(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $relative);
    }

    // ── 1. registry validity (executes SourceRegistry on the real inventory) ───────────────

    public function test_inventory_has_no_problems_duplicates_or_false_verification(): void
    {
        $r = $this->registry();

        $this->assertSame([], $r->problems());
        $this->assertCount(count($this->config()['candidates']), $r->all(), 'no raw row was merged away as a duplicate');
        $this->assertCount(count($r->all()), array_unique(array_column($r->all(), 'id')), 'ids are unique');
        $urls = array_filter(array_column($r->all(), 'url'));
        $this->assertCount(count($urls), array_unique(array_map([$r, 'canonical'], $urls)), 'no address appears twice');
        $this->assertSame([], $r->operational(), 'nothing is operational without a recorded verification');
    }

    public function test_inventory_has_no_duplicate_representations_of_same_source(): void
    {
        $r = $this->registry();
        $byHost = [];
        foreach ($r->all() as $s) {
            if ($s['url'] === null) {
                continue;
            }
            $p = parse_url($s['url']);
            $host = preg_replace('/^www\./', '', mb_strtolower((string) ($p['host'] ?? '')));
            $path = rtrim((string) ($p['path'] ?? ''), '/');
            $byHost[$host][] = ['name' => $s['name'], 'path' => $path, 'category' => $s['category'], 'url' => $s['url']];
        }

        // 1. No general news home pages when a focused section or city page exists for that site
        $newsRootDupes = [];
        foreach ($byHost as $host => $rows) {
            $news = array_values(array_filter($rows, fn ($x) => $x['category'] === 'news'));
            $roots = array_filter($news, fn ($x) => $x['path'] === '');
            $deeper = array_filter($news, fn ($x) => $x['path'] !== '');
            if ($roots && $deeper) {
                $newsRootDupes[$host] = array_column($news, 'name');
            }
        }
        $this->assertSame([], $newsRootDupes, 'general news home pages must not duplicate focused sections of the same site');

        // 2. No site root and deeper path in the same non-news category (except pre-existing DIKSHA platform entries)
        $sameCatDupes = [];
        foreach ($byHost as $host => $rows) {
            $byCat = [];
            foreach ($rows as $x) {
                $byCat[$x['category']][] = $x;
            }
            foreach ($byCat as $cat => $xs) {
                if ($cat === 'news' || $host === 'diksha.gov.in') {
                    continue;
                }
                $roots = array_filter($xs, fn ($x) => $x['path'] === '');
                $deeper = array_filter($xs, fn ($x) => $x['path'] !== '');
                if ($roots && $deeper) {
                    $sameCatDupes["$host [$cat]"] = array_column($xs, 'name');
                }
            }
        }
        $this->assertSame([], $sameCatDupes, 'a site root and deeper path in the same category must not duplicate each other');

        // 3. Known unresolved duplicate host pairs are named explicitly: GSEB hosts website.gseb.org and gseb.org
        // stay until a live verification run settles which host responds.
        $unresolvedHostPairs = [
            'gseb' => ['gseb.org', 'website.gseb.org'],
        ];
        $allHosts = array_keys($byHost);
        foreach ($unresolvedHostPairs['gseb'] as $expectedHost) {
            $this->assertContains($expectedHost, $allHosts, "unresolved GSEB host $expectedHost is retained");
        }
    }

    public function test_duplicate_representations_rule_catches_synthetic_duplicates(): void
    {
        $check = function (array $candidates): array {
            $r = $this->registry(['candidates' => $candidates]);
            $byHost = [];
            foreach ($r->all() as $s) {
                if ($s['url'] === null) {
                    continue;
                }
                $p = parse_url($s['url']);
                $host = preg_replace('/^www\./', '', mb_strtolower((string) ($p['host'] ?? '')));
                $path = rtrim((string) ($p['path'] ?? ''), '/');
                $byHost[$host][] = ['name' => $s['name'], 'path' => $path, 'category' => $s['category']];
            }
            $errors = [];
            foreach ($byHost as $host => $rows) {
                $news = array_values(array_filter($rows, fn ($x) => $x['category'] === 'news'));
                if (array_filter($news, fn ($x) => $x['path'] === '') && array_filter($news, fn ($x) => $x['path'] !== '')) {
                    $errors[] = "news_root_dupe:$host";
                }
                $byCat = [];
                foreach ($rows as $x) {
                    $byCat[$x['category']][] = $x;
                }
                foreach ($byCat as $cat => $xs) {
                    if ($cat !== 'news' && array_filter($xs, fn ($x) => $x['path'] === '') && array_filter($xs, fn ($x) => $x['path'] !== '')) {
                        $errors[] = "same_cat_dupe:$host:$cat";
                    }
                }
            }

            return $errors;
        };

        // News root dupe
        $badNews = [
            ['News Root', 'https://example.com/', 'india', 'news', false, 'core', 'news', 'news_search'],
            ['News Section', 'https://example.com/education', 'india', 'news', false, 'core', 'news', 'news_search'],
        ];
        $this->assertSame(['news_root_dupe:example.com'], $check($badNews));

        // Tender root and deeper path dupe
        $badTender = [
            ['Tender Root', 'https://tenders.example.gov.in/', 'india', 'tender_portal', true, 'core', 'tender', 'tender_portal'],
            ['Tender App Path', 'https://tenders.example.gov.in/nicgep/app', 'india', 'tender_portal', true, 'core', 'tender', 'tender_portal'],
        ];
        $this->assertSame(['same_cat_dupe:tenders.example.gov.in:tender_portal'], $check($badTender));
    }

    public function test_statuses_are_valid_and_a_missing_address_is_needs_url(): void
    {
        foreach ($this->registry()->all() as $s) {
            $this->assertContains($s['status'], [SourceRegistry::PENDING, SourceRegistry::NEEDS_URL], $s['name']);
            $this->assertSame($s['url'] === null, $s['status'] === SourceRegistry::NEEDS_URL, $s['name'] . ': needs_url exactly when there is no address');
            $this->assertNull($s['last_verified_at'], $s['name'] . ': nothing claims to have been checked');
        }
    }

    public function test_problems_detects_duplicates_bad_urls_and_unknown_fields(): void
    {
        $bad = $this->registry(['candidates' => [
            ['A', 'https://www.a.example/x/', 'india', 'news', false, 'core', 'news', 'news_search'],
            ['A twice', 'http://a.example/x', 'india', 'news', false, 'core', 'news', 'news_search'],
            ['Broken', 'not a url', 'india', 'news', false, 'core', 'news', 'news_search'],
            ['Nowhere', 'https://b.example', 'mars', 'news', false, 'core', 'news', 'news_search'],
            ['No product', 'https://c.example', 'india', 'news', false, 'widgets', 'news', 'news_search'],
        ], 'verified' => ['src-0000000000' => ['verified_at' => '2026-01-01']]]);

        $text = implode("\n", $bad->problems());
        $this->assertStringContainsString('duplicate of', $text);
        $this->assertStringContainsString('not a valid http(s) URL', $text);
        $this->assertStringContainsString('unknown region', $text);
        $this->assertStringContainsString('missing or unknown product', $text);
        $this->assertStringContainsString('matches no candidate', $text);
    }

    public function test_counts_by_category_region_and_status_add_up(): void
    {
        $r = $this->registry();
        $n = count($r->all());

        foreach (['category', 'region', 'status'] as $field) {
            $this->assertSame($n, array_sum($r->countsBy($field)), $field);
        }
        $this->assertGreaterThanOrEqual(20, $r->countsBy('region')['gujarat'] ?? 0, 'Gujarat has real coverage');
    }

    // ── 2. selection and query construction (executes SourceSelector) ───────────────────

    private function small(): SourceRegistry
    {
        return $this->registry(['candidates' => [
            ['Gujarat tender portal', 'https://tenders.gj.example/', 'gujarat', 'tender_portal', true, 'core,learn', 'tender', 'tender_portal'],
            ['Gujarat circulars', 'https://circ.gj.example/', 'gujarat', 'circular', true, 'core', 'circular', 'official_page'],
            ['Gujarat news', 'https://news.gj.example/', 'gujarat', 'news', false, 'core', 'news,expansion', 'news_search'],
            ['India board', 'https://board.in.example/', 'india', 'board', true, 'core', 'policy', 'official_page'],
            ['Abroad news', 'https://edu.abroad.example/', 'international', 'news', false, 'learn', 'news', 'news_search'],
            ['School directory', 'https://dir.in.example/', 'india', 'directory', false, 'core', 'directory', 'directory'],
            ['Job board', 'https://jobs.in.example/', 'india', 'job_board', false, 'ccb', 'hiring', 'job_search'],
            ['Category: district offices', null, 'gujarat', 'district', true, 'core', 'circular', 'search_only'],
            ['Category: state tenders', null, 'state', 'tender_portal', true, 'core', 'tender', 'tender_portal'],
        ]]);
    }

    public function test_selection_is_deterministic_bounded_and_rotates_by_day(): void
    {
        $sel = new SourceSelector($this->small());
        $d1 = Carbon::parse('2026-10-26');

        $a = $sel->select($d1, 2);
        $this->assertSame($a, $sel->select($d1, 2), 'the same day gives the same sources');
        $this->assertCount(2, $a['specs']);
        $this->assertLessThanOrEqual(2, count($a['selected']));
        $this->assertNotSame($a['selected'], $sel->select($d1->copy()->addDay(), 2)['selected'], 'the next day moves on');
        $this->assertNotSame($a['selected'], $sel->select($d1, 2, ['shift' => 1])['selected'], 'a repeat run on the same day moves on');
        $this->assertSame([], $sel->select($d1, 0)['specs'], 'zero slots, zero searches');
    }

    public function test_rotation_reaches_every_eligible_source_over_time(): void
    {
        $sel = new SourceSelector($this->small());
        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $seen = array_merge($seen, $sel->select(Carbon::parse('2026-10-01')->addDays($i), 2)['selected']);
        }
        $eligible = $sel->select(Carbon::parse('2026-10-01'), 1)['eligible'];

        $this->assertSame($eligible, count(array_unique($seen)), 'no eligible source is starved');
        $this->assertSame(5, $eligible, 'a directory, a job board and address-less rows are not eligible for a domain search');
    }

    public function test_registry_specs_are_domain_restricted_and_never_use_needs_url_rows(): void
    {
        $r = $this->small();
        $pick = (new SourceSelector($r))->select(Carbon::parse('2026-10-26'), 4);
        $needsUrl = array_column(array_filter($r->all(), fn ($s) => $s['status'] === SourceRegistry::NEEDS_URL), 'id');

        foreach ($pick['specs'] as $spec) {
            $this->assertCount(1, $spec['domains']);
            $this->assertStringNotContainsString('http', $spec['q'], 'the query carries words; the domain restriction carries the address');
            $this->assertStringStartsWith('reg-', $spec['id']);
            $this->assertSame('pending_verification', $spec['registry_status'], 'a candidate stays unverified even when it is searched');
            $this->assertNotContains($spec['registry_id'], $needsUrl);
        }
        $this->assertSame('needs_url', $pick['skipped'][$needsUrl[0]]);
        $this->assertSame('method_not_searchable:directory', array_values(array_filter($pick['skipped'], fn ($v) => str_starts_with($v, 'method_')))[0]);
    }

    public function test_a_provider_that_cannot_restrict_domains_gets_no_registry_search(): void
    {
        $pick = (new SourceSelector($this->small()))->select(Carbon::parse('2026-10-26'), 3, ['can_restrict_domains' => false]);

        $this->assertSame([], $pick['specs']);
        $this->assertSame([], $pick['selected']);
        $this->assertContains('provider_cannot_restrict_domain', $pick['skipped']);
    }

    public function test_gujarat_tender_sources_outrank_foreign_news_and_queries_name_the_place(): void
    {
        $rows = $this->small()->all();
        $by = fn (string $name) => array_values(array_filter($rows, fn ($s) => $s['name'] === $name))[0];

        $this->assertGreaterThan(SourceSelector::score($by('Abroad news')), SourceSelector::score($by('Gujarat tender portal')));
        $this->assertGreaterThan(SourceSelector::score($by('India board')), SourceSelector::score($by('Gujarat circulars')));
        $this->assertStringContainsString('Gujarat', SourceSelector::query($by('Gujarat tender portal')));
        $this->assertStringContainsString('tender', SourceSelector::query($by('Gujarat tender portal')));
        $this->assertStringContainsString('international school group', SourceSelector::query($by('Abroad news')));
        $this->assertGreaterThan(SourceSelector::score($by('India board')), SourceSelector::score($by('India board'), ['core']), 'ICP product overlap raises the score');
    }

    public function test_category_rows_give_only_unrestricted_deduplicated_sector_queries_and_only_when_asked(): void
    {
        $sel = new SourceSelector($this->small());
        $off = $sel->select(Carbon::parse('2026-10-26'), 1);
        $on = $sel->select(Carbon::parse('2026-10-26'), 1, ['category_slots' => 5]);

        $this->assertEmpty(array_filter($off['specs'], fn ($s) => str_starts_with($s['id'], 'regcat-')));
        $cat = array_values(array_filter($on['specs'], fn ($s) => str_starts_with($s['id'], 'regcat-')));
        $this->assertNotEmpty($cat);
        foreach ($cat as $s) {
            $this->assertArrayNotHasKey('domains', $s, 'a row with no address is never domain-restricted');
            $this->assertSame('needs_url', $s['registry_status']);
        }
        $this->assertSame(count($on['specs']), count(array_unique(array_column($on['specs'], 'q'))), 'no two searches are the same text');
    }

    // ── 3 + 9. school-name fallback (executes SchoolNameFallback) ───────────────────────

    public function test_a_school_without_a_website_still_gets_name_and_place_searches(): void
    {
        $q = SchoolNameFallback::queries(['id' => 7, 'name' => 'Shree Sarvodaya "Vidyalaya"', 'city' => 'Surat', 'state' => 'Gujarat', 'attributes' => ['district' => 'Surat', 'trust' => 'Sarvodaya Trust']], 60, 3);

        $this->assertNotEmpty($q, 'no website is needed to be searched');
        $this->assertSame('Shree Sarvodaya Vidyalaya Surat school', $q[0]['q'], 'the quote marks are stripped, the place is added');
        $this->assertSame('account-name-7-0', $q[0]['id']);
        $this->assertSame(7, $q[0]['account_id']);
        $this->assertSame('account_name', $q[0]['source']);
        $this->assertCount(2, $q, 'a trust adds a second, different variant');
        $this->assertStringContainsString('Sarvodaya Trust', $q[1]['q']);
        $this->assertCount(count(array_unique(array_column($q, 'q'))), $q, 'identical queries are never produced twice');
    }

    public function test_name_search_is_bounded_and_skips_nameless_or_aliased_duplicates(): void
    {
        $acct = ['id' => 3, 'name' => 'Podar World School', 'city' => 'Vadodara', 'attributes' => ['aliases' => ['Podar World School', 'Podar Vadodara'], 'district' => 'Vadodara', 'trust' => 'Podar Education']];

        $this->assertCount(1, SchoolNameFallback::queries($acct, 60, 1));
        $this->assertCount(3, SchoolNameFallback::queries($acct, 60, 9), 'the alias equal to the name adds nothing');
        $this->assertSame([], SchoolNameFallback::queries(['id' => 1, 'name' => '  ', 'city' => 'X'], 60, 2));
        $this->assertSame([], SchoolNameFallback::queries($acct, 60, 0));
        $this->assertSame(['name', 'city_or_district'], SchoolNameFallback::missingFields(['name' => '']));
        $this->assertSame([], SchoolNameFallback::missingFields(['name' => 'X', 'city' => 'Surat']));
    }

    public function test_a_found_host_is_a_candidate_only_and_third_party_pages_are_kept_apart(): void
    {
        $own = SchoolNameFallback::classify('https://www.sarvodayavidyalaya.edu.in/admissions', 'Sarvodaya Vidyalaya');
        $social = SchoolNameFallback::classify('https://www.linkedin.com/company/sarvodaya-vidyalaya', 'Sarvodaya Vidyalaya');
        $part = SchoolNameFallback::classify('https://sarvodaya-news.example/story', 'Sarvodaya Vidyalaya Surat');
        $other = SchoolNameFallback::classify('https://unrelated.example/', 'Sarvodaya Vidyalaya');

        $this->assertSame(SchoolNameFallback::CANDIDATE_OFFICIAL, $own['class']);
        $this->assertFalse($own['verified'], 'a name search never verifies a site');
        $this->assertSame(SchoolNameFallback::THIRD_PARTY, $social['class']);
        $this->assertSame(SchoolNameFallback::AMBIGUOUS, $part['class'], 'a partial name match waits for a person');
        $this->assertSame(SchoolNameFallback::THIRD_PARTY, $other['class']);
    }

    // ── 8. source, page and candidate counts stay separate (executes RunAccounting) ──────

    public function test_registry_selected_is_not_registry_searched(): void
    {
        $diag = ['planned' => ['a', 'reg-1', 'reg-2', 'account-name-4-0'], 'executed' => ['a', 'reg-1', 'account-name-4-0'],
            'failed' => ['reg-1' => 'http_429'], 'skipped' => ['reg-2' => 'searched_within_cache_window'], 'returned' => 9, 'pages_fetched' => 3,
            'registry' => ['selected' => ['s1', 's2'], 'eligible' => 80, 'skipped_reason' => null],
            'name_search' => ['accounts' => [4], 'results' => [4 => ['candidate_official_hosts' => ['sarvodaya.edu.in' => true], 'third_party' => 2, 'ambiguous' => 0]]]];
        $s = RunAccounting::build($diag, [], ['sources' => 7, 'candidates' => 1]);

        $this->assertSame(2, $s['registry']['selected']);
        $this->assertSame(1, $s['registry']['attempted'], 'one search was attempted');
        $this->assertSame(0, $s['registry']['searched'], 'it failed, so nothing was searched successfully');
        $this->assertSame(1, $s['registry']['failed']);
        $this->assertSame(1, $s['registry']['served_from_cache']);
        $this->assertSame(0, $s['registry']['skipped_other']);
        $this->assertFalse($s['registry']['verified']);
        $this->assertSame(1, $s['name_search']['schools_selected']);
        $this->assertSame(1, $s['name_search']['searches_run']);
        $this->assertSame(1, $s['name_search']['candidate_sites']);
        $this->assertSame(0, $s['name_search']['candidate_sites_verified']);
        $this->assertSame(7, $s['results']['kept'], 'sources kept');
        $this->assertSame(3, $s['pages']['fetched'], 'pages are a different number from sources');
        $this->assertSame(1, $s['candidates']['proposed']);
    }

    public function test_a_run_with_no_registry_record_is_unavailable_not_zero(): void
    {
        $s = RunAccounting::build(['planned' => ['a'], 'executed' => ['a'], 'returned' => 4], [], ['sources' => 4, 'candidates' => 0]);

        $this->assertNull($s['registry']);
        $this->assertNull($s['name_search']);
    }

    // ── 10. product mapping missing or ambiguous (executes CapabilityMapping) ──────────

    public function test_capabilities_are_unmapped_until_a_validated_mapping_exists(): void
    {
        $parts = ['off_1' => ['name' => 'School ERP']];

        $none = CapabilityMapping::explain(['core', 'learn'], $parts, ['validated' => false, 'map' => []]);
        $this->assertSame(CapabilityMapping::UNMAPPED, $none['status']);
        $this->assertFalse(CapabilityMapping::usableForDecisions($none));

        $unvalidated = CapabilityMapping::explain(['core'], $parts, ['validated' => false, 'map' => ['core' => ['off_1']]]);
        $this->assertSame(CapabilityMapping::UNMAPPED, $unvalidated['status'], 'a guessed mapping is not used until a person validates it');

        $partial = CapabilityMapping::explain(['core', 'learn'], $parts, ['validated' => true, 'map' => ['core' => ['off_1']]]);
        $this->assertSame(CapabilityMapping::UNMAPPED, $partial['status'], 'one unmapped capability leaves the whole thing unmapped');

        $stale = CapabilityMapping::explain(['core'], $parts, ['validated' => true, 'map' => ['core' => ['gone']]]);
        $this->assertSame(CapabilityMapping::STALE, $stale['status']);

        $ok = CapabilityMapping::explain(['core'], $parts, ['validated' => true, 'map' => ['core' => ['off_1']]]);
        $this->assertTrue(CapabilityMapping::usableForDecisions($ok));
        $this->assertSame(['off_1'], $ok['capabilities']['core']['offers']);
    }

    public function test_shipped_capability_map_is_empty_and_unvalidated(): void
    {
        $cfg = include __DIR__ . '/../../../config/signals_capability_map.php';

        $this->assertFalse($cfg['validated']);
        $this->assertSame([], $cfg['map'], 'no mapping is invented in the repository');
    }

    // ── wiring: SOURCE-TEXT assertions (they prove the code is connected, not that a run succeeds) ─────

    public function test_source_text_researcher_uses_the_selector_and_never_fetches_registry_addresses(): void
    {
        $src = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertStringContainsString('new SourceSelector(new SourceRegistry())', $src);
        $this->assertStringContainsString("'can_restrict_domains' => \$restricts", $src);
        // Only sources that a real verification fetch recorded as readable (operational) are ever read directly; unverified candidates are only searched.
        $this->assertSame(1, substr_count($src, '->operational()'), 'one place reads operational sources: directSources()');
        $this->assertLessThan(strpos($src, '->operational()'), strpos($src, 'SourceRegistry::fromStorage($store)'), 'from the recorded verification results');
        $this->assertStringContainsString('SchoolCheckPolicy::completed', $src, 'a school is marked checked only by the shared policy');
        $this->assertStringNotContainsString("TargetAccount::whereIn('id', \$watched)", $src, 'schools with a website are no longer marked checked unconditionally');
        $this->assertStringContainsString('readCounts(count($sources))', $src, 'the run message separates sources from pages read');
        $this->assertStringNotContainsString("count(\$sources) . ' pages", $src, 'a search result is not called a page');
    }

    public function test_source_text_planner_searches_schools_without_a_website_within_a_bound(): void
    {
        $src = $this->source('app/Domain/Signals/Icp/IcpResearchPlanner.php');

        $this->assertStringContainsString('SchoolNameFallback::queries', $src);
        $this->assertStringContainsString("config('signals.icp.name_search_slots'", $src);
        $this->assertStringContainsString("->limit(\$nameSlots)", $src);
    }

    public function test_source_text_api_tells_new_from_rediscovered(): void
    {
        $src = $this->source('app/Http/Controllers/Api/Signals/OpportunityController.php');

        $this->assertStringContainsString("'discovered_on_report_date'", $src);
    }
}
