<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\EvidenceGrader;
use App\Domain\Signals\Market\EvidenceItems;
use App\Domain\Signals\Market\EvidenceMaturity;
use App\Domain\Signals\Run\RunAccounting;
use Tests\TestCase;

/**
 * The existing Signals frontend (g2gf) is frozen. These tests pin the backend shapes it reads, so engine changes cannot break it.
 *  - lib/signal-run-notice.ts reads stats.queries.{succeeded,attempted,with_results,empty,cache_hits}, stats.results.returned,
 *    stats.pages.fetched and stats.outcome.{updated,unchanged_existing} WITHOUT optional chaining.
 *  - The Review screen shows claim_level as fixed words for confirmed / inference / hypothesis and prints anything else raw.
 *  - assessment keys the UI reads must keep their meaning; new keys must be ADDITIVE.
 */
final class FrontendContractTest extends TestCase
{
    private const REQUIRED = [
        'queries' => ['planned', 'attempted', 'succeeded', 'with_results', 'empty', 'failed', 'cache_hits', 'skipped', 'skipped_by_reason'],
        'results' => ['returned', 'dropped_too_old', 'screened_out', 'kept'],
        'pages' => ['fetched', 'failed', 'blocked_by_robots', 'skipped', 'skipped_by_reason', 'failed_by_category', 'failed_by_host_class'],
        'candidates' => ['proposed', 'rejected_before_gate', 'rejected_by_gate'],
        'outcome' => ['new', 'updated', 'unchanged_existing', 'qualified_total'],
    ];

    private function assertShape(array $stats): void
    {
        foreach (self::REQUIRED as $group => $keys) {
            $this->assertArrayHasKey($group, $stats, "stage_stats.$group is read unguarded by the run notice");
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $stats[$group], "stage_stats.$group.$k must stay");
            }
        }
        foreach (['errors_by_stage', 'registry', 'direct'] as $k) {
            $this->assertArrayHasKey($k, $stats);
        }
    }

    public function test_a_run_with_no_diagnostics_still_has_every_key_the_run_notice_reads(): void
    {
        $this->assertShape(RunAccounting::build([], [], []));
    }

    public function test_the_new_fetch_diagnostics_only_add_keys_and_remove_none(): void
    {
        $plain = RunAccounting::build(['pages_fetched' => 3], [], ['sources' => 5]);
        $rich = RunAccounting::build([
            'pages_fetched' => 3,
            'fetch_by_host' => ['example.org' => ['ok' => 2, 'robots_disallowed' => 1]],
            'cited_pages' => ['attempted' => 2, 'read' => 1],
        ], [], ['sources' => 5]);

        $this->assertShape($plain);
        $this->assertShape($rich);
        $this->assertArrayNotHasKey('fetch_by_host', $plain['pages'], 'absent when nothing was recorded: stats of an older kind are unchanged');
        $this->assertArrayHasKey('fetch_by_host', $rich['pages']);
        foreach (array_keys($plain['pages']) as $k) {
            $this->assertSame($plain['pages'][$k], $rich['pages'][$k] ?? null, "pages.$k keeps its value and meaning");
        }
    }

    public function test_counts_the_notice_prints_are_numbers_never_null(): void
    {
        $s = RunAccounting::build([], [], []);
        foreach ([['queries', 'succeeded'], ['queries', 'attempted'], ['queries', 'cache_hits'], ['results', 'returned'], ['pages', 'fetched'], ['outcome', 'updated'], ['outcome', 'unchanged_existing']] as [$g, $k]) {
            $this->assertIsInt($s[$g][$k], "$g.$k is printed directly by the UI");
        }
    }

    public function test_claim_level_stays_inside_the_three_values_the_ui_words(): void
    {
        $grader = new EvidenceGrader();
        $buyer = ['name' => 'Podar World School', 'website' => 'https://podarworld.com'];
        $cases = [
            [['quote_verified' => false], 'search_snippet', 'https://news.example/x'],
            [['quote_verified' => true], 'page_text', 'https://podarworld.com/x'],
            [['quote_verified' => true, 'corroborating_domains' => 3, 'inference_reasoning' => 'Reasoned from a verified fact.'], 'page_text', 'https://news.example/x'],
            [[], 'blocked', 'https://news.example/x'],
        ];
        foreach ($cases as [$evidence, $fetch, $url]) {
            $this->assertContains($grader->grade($evidence, $fetch, $url, $buyer)['claim_level'], ['confirmed', 'inference', 'hypothesis']);
        }
    }

    public function test_the_annotations_are_additive_json_and_use_none_of_the_keys_the_ui_reads(): void
    {
        // Keys the review and feed screens read from `assessment` (read from g2gf): they must not be reused for something else.
        $uiReads = ['provenance', 'evidence', 'fit', 'evidence_class', 'region', 'source_reliability', 'buying_intent', 'sales_pull', 'product_fit', 'identity', 'unverified'];
        foreach (['evidence_maturity', 'evidence_items', 'independent_sources', 'why_now'] as $new) {
            $this->assertNotContains($new, $uiReads);
        }
        // And what they hold is plain, encodable data.
        $m = EvidenceMaturity::assess(['fetch_level' => 'search_snippet']);
        $this->assertSame('E0', $m['level']);
        $this->assertNotFalse(json_encode($m));
        $items = EvidenceItems::build([['url' => 'https://a.example/x', 'title' => 'T', 'snippet' => 's', 'page_fetched' => false]], 'claim', $m['level'], 'https://a.example/x');
        $this->assertNotFalse(json_encode($items));
    }

    public function test_maturity_for_stored_rows_is_a_known_label(): void
    {
        foreach ([['confirmed', 'page_text'], ['inference', 'page_text'], ['hypothesis', 'search_snippet'], [null, null]] as [$claim, $fetch]) {
            $this->assertContains(EvidenceMaturity::fromStored($claim, $fetch), ['E0', 'E1', 'E2', 'E3', 'not_assessed']);
        }
    }
}
