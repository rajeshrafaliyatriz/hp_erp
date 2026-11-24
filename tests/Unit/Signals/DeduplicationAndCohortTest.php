<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\MarketCohort;
use App\Domain\Signals\Market\SignalDeduplicator as D;
use PHPUnit\Framework\TestCase;

/** Company + normalised URL deduplication, and the reporting cohort. Both are pure: nothing is read from or written to a database. */
final class DeduplicationAndCohortTest extends TestCase
{
    private function ids(array $groups): array
    {
        return array_map(fn ($g) => $g['ids'], $groups);
    }

    // ---- URL identity

    public function test_tracking_parameters_www_and_trailing_slash_do_not_make_a_new_url(): void
    {
        $a = D::urlKey('https://www.example.org/news/item/?utm_source=x&utm_medium=y#top');
        $b = D::urlKey('http://example.org/news/item');
        $this->assertSame($a, $b);
    }

    public function test_a_different_post_id_is_a_different_url(): void
    {
        $this->assertNotSame(D::urlKey('https://www.youtube.com/watch?v=A'), D::urlKey('https://www.youtube.com/watch?v=B'));
    }

    public function test_an_empty_or_malformed_url_has_no_key(): void
    {
        $this->assertNull(D::urlKey(''));
        $this->assertNull(D::urlKey(null));
        $this->assertNull(D::urlKey('not a url'));
    }

    // ---- same organisation?

    public function test_name_variants_of_one_organisation_match(): void
    {
        $this->assertTrue(D::sameCompany('KPMG in India', 'KPMG in India'));
        $this->assertTrue(D::sameCompany('CACTUS', 'Cactus Communications'));
        $this->assertTrue(D::sameCompany('Mahatma Phule Renewable Energy & Infrastructure Technology Ltd (MAHAPREIT)', 'MAHAPREIT (Maharashtra Power Research and Development Foundation)'));
        $this->assertTrue(D::sameCompany('Acme Pvt Ltd', 'ACME Private Limited'));
    }

    public function test_different_organisations_do_not_match(): void
    {
        $this->assertFalse(D::sameCompany('Nihilent', 'Exusia'));
        $this->assertFalse(D::sameCompany('SC Cyber Academy', 'Ngee Ann Polytechnic'));
        $this->assertFalse(D::sameCompany('Singapore Land Authority', 'Urban Redevelopment Authority'));
        $this->assertFalse(D::sameCompany('', 'Anything'));
    }

    public function test_a_stub_word_does_not_absorb_a_longer_name(): void
    {
        // "Ace" must not be treated as a duplicate of "Ace Global Services".
        $this->assertFalse(D::sameCompany('Ace', 'Ace Global Services'));
    }

    // ---- the seven live groups (public company names), as the decision rule sees them

    public function test_real_duplicates_are_found_and_one_article_naming_two_companies_is_not(): void
    {
        $rows = [
            ['id' => 108, 'tenant' => 6, 'company' => 'BOB', 'source_url' => 'https://bankofbaroda.bank.in/tenders/corporate-office/x-13-11'],
            ['id' => 109, 'tenant' => 6, 'company' => 'BOB', 'source_url' => 'https://bankofbaroda.bank.in/tenders/corporate-office/x-13-11'],
            ['id' => 105, 'tenant' => 6, 'company' => 'KPMG in India', 'source_url' => 'https://kpmg.com/in/en/media/p.html'],
            ['id' => 146, 'tenant' => 6, 'company' => 'KPMG in India', 'source_url' => 'https://kpmg.com/in/en/media/p.html?utm_source=a'],
            ['id' => 122, 'tenant' => 6, 'company' => 'CACTUS', 'source_url' => 'https://www.instagram.com/p/DbLXTLqCEWt'],
            ['id' => 163, 'tenant' => 6, 'company' => 'Cactus Communications', 'source_url' => 'https://www.instagram.com/p/DbLXTLqCEWt/'],
            ['id' => 112, 'tenant' => 6, 'company' => 'Mahatma Phule Renewable Energy & Infrastructure Technology Ltd (MAHAPREIT)', 'source_url' => 'https://mahapreit.in/a/t.pdf'],
            ['id' => 119, 'tenant' => 6, 'company' => 'MAHAPREIT (Maharashtra Power Research and Development Foundation)', 'source_url' => 'https://mahapreit.in/a/t.pdf'],
            ['id' => 136, 'tenant' => 6, 'company' => 'Nihilent', 'source_url' => 'https://builtinpune.in/articles/bi'],
            ['id' => 137, 'tenant' => 6, 'company' => 'Exusia', 'source_url' => 'https://builtinpune.in/articles/bi'],
            ['id' => 130, 'tenant' => 6, 'company' => 'SC Cyber Academy', 'source_url' => 'https://govinsider.asia/a'],
            ['id' => 131, 'tenant' => 6, 'company' => 'Ngee Ann Polytechnic', 'source_url' => 'https://govinsider.asia/a'],
            ['id' => 154, 'tenant' => 6, 'company' => 'Singapore Land Authority', 'source_url' => 'https://www.linkedin.com/pulse/kg'],
            ['id' => 155, 'tenant' => 6, 'company' => 'Urban Redevelopment Authority', 'source_url' => 'https://www.linkedin.com/pulse/kg'],
        ];
        $g = D::groups($rows);
        $dups = $this->ids($g['duplicates']);
        sort($dups);
        $this->assertSame([[105, 146], [108, 109], [112, 119], [122, 163]], $dups, 'four real duplicate pairs');
        $this->assertCount(3, $g['shared_url_different_company'], 'three URLs shared by different organisations');
        $flat = array_merge(...$dups);
        foreach ([136, 137, 130, 131, 154, 155] as $id) {
            $this->assertNotContains($id, $flat, "id $id belongs to a legitimately shared article");
        }
    }

    public function test_the_same_url_in_two_tenants_is_not_a_duplicate(): void
    {
        $g = D::groups([
            ['id' => 1, 'tenant' => 6, 'company' => 'Acme', 'source_url' => 'https://a.example/x'],
            ['id' => 2, 'tenant' => 7, 'company' => 'Acme', 'source_url' => 'https://a.example/x'],
        ]);
        $this->assertSame([], $g['duplicates']);
    }

    public function test_rows_without_a_url_are_never_grouped(): void
    {
        $g = D::groups([
            ['id' => 1, 'tenant' => 6, 'company' => 'Acme', 'source_url' => ''],
            ['id' => 2, 'tenant' => 6, 'company' => 'Acme', 'source_url' => null],
        ]);
        $this->assertSame([], $g['duplicates']);
    }

    public function test_grouping_changes_nothing_in_its_input(): void
    {
        $rows = [['id' => 1, 'tenant' => 6, 'company' => 'Acme', 'source_url' => 'https://a.example/x'], ['id' => 2, 'tenant' => 6, 'company' => 'Acme', 'source_url' => 'https://a.example/x']];
        $copy = $rows;
        D::groups($rows);
        $this->assertSame($copy, $rows);
    }

    // ---- reporting cohort

    public function test_the_sixteen_notes_are_excluded_by_the_existing_field(): void
    {
        $c = MarketCohort::classify(['import_source' => null, 'signal_kind' => 'public_reporting', 'source_url' => '', 'is_sample' => 0]);
        $this->assertSame(MarketCohort::NOTE, $c['class']);
        $this->assertFalse($c['counts_in_market_metrics']);
    }

    public function test_early_research_without_a_url_is_reported_as_no_source_url_not_a_note(): void
    {
        $c = MarketCohort::classify(['import_source' => null, 'signal_kind' => 'opportunity', 'source_url' => '', 'is_sample' => 0]);
        $this->assertSame(MarketCohort::NO_URL, $c['class']);
        $this->assertFalse($c['counts_in_market_metrics']);
    }

    public function test_a_batch_label_is_kept_as_provenance_and_does_not_exclude_a_record(): void
    {
        $c = MarketCohort::classify(['import_source' => 'demo_fixture_oct13_14_2026', 'signal_kind' => 'opportunity', 'source_url' => 'https://x.example/a', 'is_sample' => 0]);
        $this->assertSame(MarketCohort::MARKET, $c['class']);
        $this->assertTrue($c['counts_in_market_metrics']);
        $this->assertSame('demo_fixture_oct13_14_2026', $c['batch_label'], 'the original import_source is reported, never rewritten');
    }

    public function test_only_is_sample_excludes_a_record_as_a_fixture(): void
    {
        $this->assertSame(MarketCohort::SAMPLE, MarketCohort::classify(['import_source' => 'web-research', 'signal_kind' => 'opportunity', 'source_url' => 'https://x.example/a', 'is_sample' => 1])['class']);
    }

    public function test_web_research_and_manual_origins(): void
    {
        $this->assertSame('web_research', MarketCohort::classify(['import_source' => 'web-research', 'source_url' => 'https://x.example'])['origin']);
        $this->assertSame('manual', MarketCohort::classify(['import_source' => 'manual-entry', 'source_url' => 'https://x.example'])['origin']);
        $this->assertNull(MarketCohort::classify(['import_source' => 'web-research', 'source_url' => 'https://x.example'])['batch_label']);
    }

    public function test_summary_states_its_denominator(): void
    {
        $rows = array_merge(
            array_fill(0, 3, ['import_source' => 'web-research', 'signal_kind' => 'opportunity', 'source_url' => 'https://x.example/1', 'is_sample' => 0]),
            array_fill(0, 2, ['import_source' => null, 'signal_kind' => 'public_reporting', 'source_url' => '', 'is_sample' => 0]),
            [['import_source' => null, 'signal_kind' => 'insight', 'source_url' => '', 'is_sample' => 0]],
        );
        $s = MarketCohort::summarise($rows);
        $this->assertSame(6, $s['total']);
        $this->assertSame(3, $s['market_signals']);
        $this->assertSame([MarketCohort::SAMPLE => 0, MarketCohort::NOTE => 2, MarketCohort::NO_URL => 1, MarketCohort::MARKET => 3], $s['by_class']);
    }
}
