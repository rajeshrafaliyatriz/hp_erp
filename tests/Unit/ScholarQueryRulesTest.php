<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Scholar K-12 searches follow the rules for finding BUYERS: described in the buyer's situation and words, with the
 * right topic, official sites and freshness window for each kind of source, and rotated so the whole set is covered
 * within 7 days. Pure; reads the template in config/signals_icp.php.
 */
class ScholarQueryRulesTest extends TestCase
{
    private const OFFICIAL_TENDER_SITES = ['gem.gov.in', 'eprocure.gov.in', 'nprocure.com', 'mahatenders.gov.in'];
    private const FORBIDDEN_PRODUCT_WORDS = '/\b(AI solutions?|enterprise AI|artificial intelligence|generative AI|business intelligence|machine learning|analytics|intelligent school|digital transformation)\b/i';
    /** Job boards and LinkedIn are reached ONLY through the search API's index of public pages, always with an explicit site list. */
    private const INDEXED_SITES = ['linkedin.com', 'naukri.com', 'indeed.com', 'shine.com', 'foundit.in', 'quora.com', 'reddit.com'];

    /** @return list<array<string, mixed>> */
    private function queries(): array
    {
        return config('signals_icp.scholar_k12.queries');
    }

    private function ofSource(string $source): array
    {
        return array_values(array_filter($this->queries(), fn ($q) => $q['source'] === $source));
    }

    public function test_there_are_twenty_five_unique_queries(): void
    {
        $ids = array_column($this->queries(), 'id');

        $this->assertCount(25, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_tender_and_eoi_queries_use_the_general_topic_official_portals_and_ninety_days(): void
    {
        $tenders = $this->ofSource('tender');
        $this->assertNotEmpty($tenders);
        foreach ($tenders as $q) {
            $this->assertSame('general', $q['topic'], $q['id']);
            $this->assertSame(self::OFFICIAL_TENDER_SITES, $q['domains'], $q['id']);
            $this->assertSame(90, $q['days'], $q['id']);
        }
        $this->assertNotEmpty(array_filter($tenders, fn ($q) => str_contains($q['q'], 'EOI')), 'expressions of interest are searched too');
    }

    public function test_job_post_queries_are_general_and_recent_and_social_queries_name_their_sites(): void
    {
        $jobs = $this->ofSource('job');
        $this->assertGreaterThanOrEqual(4, count($jobs));
        foreach ($jobs as $q) {
            $this->assertSame('general', $q['topic'], $q['id']);
            $this->assertLessThanOrEqual(60, $q['days'], $q['id']);
        }
        foreach (array_merge($jobs, $this->ofSource('social')) as $q) {
            foreach ($q['domains'] ?? [] as $site) {
                $this->assertContains($site, self::INDEXED_SITES, $q['id']);
            }
        }
        $this->assertNotEmpty(array_filter($this->queries(), fn ($q) => in_array('linkedin.com', $q['domains'] ?? [], true)), 'LinkedIn is searched');
        $this->assertNotEmpty(array_filter($this->queries(), fn ($q) => in_array('naukri.com', $q['domains'] ?? [], true)), 'job boards are searched');
    }

    public function test_the_new_school_and_affiliation_query_is_general_and_searches_the_cbse_affiliation_portal(): void
    {
        $aff = array_values(array_filter($this->queries(), fn ($q) => in_array('saras.cbse.gov.in', $q['domains'] ?? [], true)));

        $this->assertCount(1, $aff);
        $this->assertSame('general', $aff[0]['topic']);
        $this->assertStringContainsString('affiliation', $aff[0]['q']);
    }

    public function test_news_leadership_and_regulation_queries_use_the_news_topic_and_thirty_days(): void
    {
        foreach (['news', 'regulation'] as $source) {
            foreach ($this->ofSource($source) as $q) {
                $this->assertSame('news', $q['topic'], $q['id']);
                $this->assertSame(30, $q['days'], $q['id']);
            }
        }
    }

    public function test_every_query_describes_the_buyers_situation_never_our_product_words(): void
    {
        foreach ($this->queries() as $q) {
            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_PRODUCT_WORDS, $q['q'], "{$q['id']}: {$q['q']}");
        }
    }

    public function test_the_example_buyer_situations_are_in_the_catalogue(): void
    {
        $all = implode("\n", array_column($this->queries(), 'q'));

        $this->assertMatchesRegularExpression('/school group "new campus" OR "new branch" admissions 2027 Gujarat/', $all);
        $this->assertMatchesRegularExpression('/school hiring "ERP administrator"/', $all);
        $this->assertMatchesRegularExpression('/"appointed principal".*school Ahmedabad/', $all);
        $this->assertMatchesRegularExpression('/tender "school management system".*"learning management system"/', $all);
        $this->assertMatchesRegularExpression('/"career counsellor"/', $all);
    }

    public function test_no_query_or_site_points_outside_the_target_regions(): void
    {
        $text = implode("\n", array_merge(array_column($this->queries(), 'q'), ...array_map(fn ($q) => $q['domains'] ?? [], $this->queries())));

        $this->assertStringNotContainsStringIgnoringCase('rajasthan', $text);
    }

    public function test_the_whole_set_is_covered_within_five_days_whatever_day_it_starts(): void
    {
        $pack = IcpPack::fromTemplate('scholar_k12');
        $all = array_column($this->queries(), 'id');

        for ($start = 0; $start < 40; $start++) {
            $seen = [];
            for ($d = 0; $d < 7; $d++) {
                foreach ($pack->catalogueForRun(Carbon::create(2026, 10, 1)->addDays($start + $d)) as $q) {
                    $seen[$q['id']] = true;
                }
            }
            $this->assertEqualsCanonicalizing($all, array_keys($seen), "starting {$start} days after 1 Oct");
        }
    }

    public function test_one_days_run_mixes_kinds_of_evidence(): void
    {
        $pack = IcpPack::fromTemplate('scholar_k12');
        for ($d = 0; $d < 8; $d++) {
            $sources = array_column($pack->catalogueForRun(Carbon::create(2026, 10, 1)->addDays($d)), 'source');
            $this->assertGreaterThan(1, count(array_unique($sources)), "day {$d}: " . implode(',', $sources));
        }
    }
}
