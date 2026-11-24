<?php

namespace Tests\Unit\Intelligence\Commercial;

use App\Domain\Intelligence\Detectors\Commercial\CommercialAccess;
use App\Domain\Intelligence\Detectors\Commercial\SignalEvidenceQualityDetector;
use App\Domain\Intelligence\Taxonomy;

class SignalEvidenceQualityDetectorTest extends CommercialTestCase
{
    private function detector(bool $menuRights = false): SignalEvidenceQualityDetector
    {
        return new SignalEvidenceQualityDetector($this->db(), $this->thresholds(), new CommercialAccess($menuRights));
    }

    private function seedSignals(int $tenant, int $snippet, int $page, array $extra = []): void
    {
        for ($i = 0; $i < $snippet; $i++) {
            $this->addSignal($tenant, ['fetch_level' => 'search_snippet'] + $extra);
        }
        for ($i = 0; $i < $page; $i++) {
            $this->addSignal($tenant, ['fetch_level' => 'page_text'] + $extra);
        }
    }

    public function test_reports_snippet_only_share_with_evidence_and_coverage(): void
    {
        $this->seedSignals(1, 12, 8);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('rule_based', $insight->confidence);
        $this->assertSame('commercial', $insight->domain);
        $this->assertNull($insight->population);
        $this->assertStringContainsString('60%', $insight->title);
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(12, $ev['Snippet only']['value']);
        $this->assertSame(8, $ev['Page or document read']['value']);
        $this->assertSame(20, $result->coverage->observed);
        $this->assertTrue($result->coverage->sufficient);
        $this->assertSame('/signal-review', $insight->deepLink);
    }

    public function test_insufficient_below_the_provisional_minimum(): void
    {
        $this->seedSignals(1, 5, 5);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame([], $result->insights);
        $this->assertFalse($result->coverage->sufficient);
    }

    public function test_ok_but_empty_when_everything_is_page_read_dated_and_reviewed(): void
    {
        $this->seedSignals(1, 0, 20);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_undated_and_unreviewed_insights(): void
    {
        $this->seedSignals(1, 0, 20, ['event_date' => null, 'source_published_at' => null, 'review_status' => 'New']);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $titles = implode('|', array_map(fn ($i) => $i->title, $result->insights));
        $this->assertCount(2, $result->insights);
        $this->assertStringContainsString('no date', $titles);
        $this->assertStringContainsString('not been reviewed', $titles);
    }

    public function test_missing_fetch_level_is_reported_separately_and_never_counted_as_snippet_only(): void
    {
        $this->seedSignals(1, 0, 10);
        for ($i = 0; $i < 10; $i++) {
            $this->addSignal(1, ['fetch_level' => null]);
        }

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame([], $result->insights, 'an unknown evidence level must not be turned into a verdict');
    }

    public function test_other_tenants_rows_are_never_counted(): void
    {
        $this->seedSignals(1, 0, 20);
        $this->seedSignals(2, 40, 0);

        $result = $this->detector()->detect($this->commercialCaller('administrator', [], 1), $this->clock());

        $this->assertSame(20, $result->coverage->observed);
        $this->assertSame([], $result->insights);
    }

    public function test_rows_with_a_null_import_source_are_real_and_demo_or_sample_rows_are_excluded(): void
    {
        $this->seedSignals(1, 0, 20, ['import_source' => null, 'is_sample' => 0]);
        $this->seedSignals(1, 5, 0, ['import_source' => 'demo_fixture_x']);
        $this->seedSignals(1, 5, 0, ['import_source' => null, 'is_sample' => 1]);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(20, $result->coverage->observed, 'NULL import_source rows count; demo and sample rows do not');
    }

    public function test_signals_older_than_the_window_are_ignored(): void
    {
        $this->seedSignals(1, 0, 20, ['first_discovered_at' => '2026-01-01 00:00:00']);

        $result = $this->detector()->detect($this->commercialCaller('administrator'), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
    }

    public function test_authorization_follows_the_signals_gate(): void
    {
        $this->assertTrue($this->detector(false)->authorizes($this->commercialCaller('administrator')));
        $this->assertTrue($this->detector(false)->authorizes($this->commercialCaller('hr_manager')));
        $this->assertFalse($this->detector(false)->authorizes($this->commercialCaller('employee')));
        $this->assertFalse($this->detector(false)->authorizes($this->commercialCaller('executive')));
        $this->assertFalse($this->detector(true)->authorizes($this->commercialCaller('administrator')), 'with menu rights on, a role alone is not enough');
        $this->assertTrue($this->detector(true)->authorizes($this->commercialCaller('employee', ['/signals'])));
    }
}
