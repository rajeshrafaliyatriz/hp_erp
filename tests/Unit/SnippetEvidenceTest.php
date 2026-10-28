<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\EvidenceGrader;
use App\Domain\Signals\Market\SignalStrength;
use Tests\TestCase;

/** A quote seen only in a search snippet is discovered, not verified on the page. */
class SnippetEvidenceTest extends TestCase
{
    public function test_the_researcher_marks_a_quote_verified_only_when_the_page_was_read(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringNotContainsString("'quote_verified' => true", $src, 'no hard-coded "verified"');
        $this->assertSame(2, substr_count($src, "'quote_verified' => (bool) \$support['page_fetched']"));
    }

    public function test_an_unverified_quote_from_a_snippet_is_never_confirmed_or_inferred(): void
    {
        $g = new EvidenceGrader();
        $buyer = ['name' => 'Acme Industries', 'website' => 'https://acme.example'];
        $snippet = $g->grade(['quote_verified' => false, 'inference_reasoning' => 'A hiring surge implies payroll strain, which is a need.', 'corroborating_domains' => 3], 'search_snippet', 'https://acme.example/news', $buyer);
        $this->assertSame('hypothesis', $snippet['claim_level']);
        $page = $g->grade(['quote_verified' => true], 'page_text', 'https://acme.example/news', $buyer);
        $this->assertSame('confirmed', $page['claim_level']);
    }

    public function test_an_unverified_quote_keeps_a_signal_from_being_strong(): void
    {
        $base = ['trigger_type' => 'tender', 'intent' => 'stated_requirement', 'event_date' => '2026-10-20', 'source_published_at' => '2026-10-20', 'observed_at' => '2026-10-21', 'expires_at' => '2026-12-30'];
        $now = new \Illuminate\Support\Carbon('2026-10-22');
        $verified = (new SignalStrength())->assess($base + ['quote_verified' => true], $now);
        $unverified = (new SignalStrength())->assess($base + ['quote_verified' => false], $now);
        $rank = ['watch' => 0, 'warm' => 1, 'hot' => 2];
        $this->assertLessThanOrEqual($rank[$verified['strength']], $rank[$unverified['strength']]);
    }
}
