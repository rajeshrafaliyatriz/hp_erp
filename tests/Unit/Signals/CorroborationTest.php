<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\Corroboration;
use PHPUnit\Framework\TestCase;

/** Which independently read pages corroborate a candidate. Pure: no network, no database. */
class CorroborationTest extends TestCase
{
    private const ORG = 'Greenfield Public School';
    private const EVENT = 'Greenfield Public School announced construction of a new science laboratory block for the next academic session.';
    private const ORIGINAL = 'https://news.example.com/story/1';

    private function page(string $url, string $text, string $title = ''): array
    {
        return ['url' => $url, 'title' => $title, 'text' => $text];
    }

    private function good(string $org = self::ORG): string
    {
        return $org . ' has begun construction of a science laboratory block, ready for the academic session in June.';
    }

    private function assess(array $pages, array $over = []): array
    {
        return Corroboration::assess($over + ['organisation' => self::ORG, 'event' => self::EVENT, 'source_url' => self::ORIGINAL], $pages);
    }

    public function test_an_independent_page_naming_the_organisation_and_the_event_corroborates(): void
    {
        $r = $this->assess([$this->page('https://www.cityherald.org/a', $this->good())]);

        $this->assertTrue($r['confirmed']);
        $this->assertSame(2, $r['independent_domains'], 'the original source plus one');
        $this->assertCount(1, $r['corroborating']);
        $this->assertSame('cityherald.org', $r['corroborating'][0]['host']);
        $this->assertGreaterThanOrEqual(3, count($r['corroborating'][0]['matched_words']));
        $this->assertSame([], $r['contradictions']);
    }

    public function test_the_same_registrable_domain_as_the_original_never_corroborates(): void
    {
        foreach (['https://news.example.com/other', 'https://www.example.com/x', 'http://example.com/y'] as $url) {
            $this->assertFalse($this->assess([$this->page($url, $this->good())])['confirmed'], $url);
        }
    }

    public function test_a_two_part_tld_is_compared_by_registrable_domain(): void
    {
        $r = $this->assess([$this->page('https://a.times.co.in/1', $this->good()), $this->page('https://b.times.co.in/2', $this->good())]);
        $this->assertCount(1, $r['corroborating'], 'two pages of one registrable domain count once');
        $this->assertSame(2, $r['independent_domains']);
    }

    public function test_pages_from_each_other_domain_all_count_but_each_domain_once(): void
    {
        $r = $this->assess([$this->page('https://one.org/a', $this->good()), $this->page('https://www.one.org/b', $this->good()), $this->page('https://two.net/a', $this->good())]);
        $this->assertSame(['one.org', 'two.net'], array_column($r['corroborating'], 'host'));
        $this->assertSame(3, $r['independent_domains']);
    }

    public function test_social_and_lead_hint_platforms_never_corroborate(): void
    {
        foreach (['https://www.facebook.com/p/1', 'https://www.linkedin.com/posts/x', 'https://x.com/s/1', 'https://www.instagram.com/p/1', 'https://t.co/abc', 'https://www.reddit.com/r/x/1', 'https://youtu.be/abc'] as $url) {
            $this->assertFalse($this->assess([$this->page($url, $this->good())])['confirmed'], $url);
        }
    }

    public function test_a_page_without_the_organisation_name_does_not_corroborate(): void
    {
        $text = 'A school in the city has begun construction of a science laboratory block, ready for the academic session.';
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', $text)])['confirmed']);
    }

    public function test_the_organisation_must_appear_as_whole_words(): void
    {
        $text = 'Greenfield Public Schools Federation has begun construction of a science laboratory block for the academic session.';
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', $text)])['confirmed'], '"Schools" is not "School"');
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', 'Ungreenfield Public School ' . $this->good(''))])['confirmed']);
    }

    public function test_the_name_is_matched_after_normalising_case_and_punctuation(): void
    {
        $r = $this->assess([$this->page('https://cityherald.org/a', 'GREENFIELD  public-school: construction of the science laboratory block, academic session.')]);
        $this->assertTrue($r['confirmed']);
    }

    public function test_a_parenthetical_label_in_the_name_is_ignored(): void
    {
        $r = $this->assess([$this->page('https://cityherald.org/a', $this->good())], ['organisation' => 'Greenfield Public School (Greenfield Group)']);
        $this->assertTrue($r['confirmed']);
    }

    public function test_fewer_than_three_event_words_on_the_page_does_not_corroborate(): void
    {
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', 'Greenfield Public School won an inter-school chess trophy. The construction crew was late.')])['confirmed']);
        $two = 'Greenfield Public School: construction of a science wing.';
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', $two)])['confirmed'], 'two words are not enough');
    }

    public function test_stop_words_and_the_organisation_s_own_words_do_not_count(): void
    {
        $words = Corroboration::eventWords(self::EVENT, Corroboration::normalise(self::ORG));
        foreach (['greenfield', 'public', 'school', 'announced', 'with', 'the', 'for', 'next', 'new'] as $w) {
            $this->assertNotContains($w, $words, $w);
        }
        $this->assertContains('construction', $words);
        $this->assertContains('laboratory', $words);
        // A page repeating only the name and generic words matches no content word.
        $generic = 'Greenfield Public School announced that the school will have a new plan for the next year, with more news.';
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', $generic)])['confirmed']);
    }

    public function test_the_matching_is_deterministic(): void
    {
        $pages = [$this->page('https://b.org/1', $this->good()), $this->page('https://a.org/1', $this->good())];
        $this->assertSame($this->assess($pages), $this->assess($pages));
        $this->assertSame(['b.org', 'a.org'], array_column($this->assess($pages)['corroborating'], 'host'), 'input order is kept');
    }

    public function test_an_event_with_too_few_distinctive_words_can_never_be_confirmed(): void
    {
        $r = $this->assess([$this->page('https://cityherald.org/a', $this->good())], ['event' => 'Greenfield Public School announced a plan.']);
        $this->assertFalse($r['confirmed']);
    }

    public function test_no_organisation_or_no_pages_confirms_nothing(): void
    {
        $this->assertFalse($this->assess([], [])['confirmed']);
        $this->assertFalse($this->assess([$this->page('https://cityherald.org/a', $this->good())], ['organisation' => ''])['confirmed']);
        $this->assertSame(1, $this->assess([])['independent_domains']);
    }

    public function test_an_unusable_url_is_skipped(): void
    {
        $this->assertFalse($this->assess([$this->page('not a url', $this->good()), $this->page('', $this->good())])['confirmed']);
    }

    public function test_a_page_that_clearly_disputes_it_is_a_contradiction_and_confirms_nothing(): void
    {
        $r = $this->assess([$this->page('https://cityherald.org/a', $this->good() . ' Greenfield Public School denied the report on Monday.')]);
        $this->assertFalse($r['confirmed']);
        $this->assertCount(1, $r['contradictions']);
        $this->assertStringContainsString('denied', $r['contradictions'][0]);
    }

    public function test_the_title_counts_as_page_text(): void
    {
        $r = $this->assess([$this->page('https://cityherald.org/a', 'Construction of a science laboratory block for the academic session.', 'Greenfield Public School expands')]);
        $this->assertTrue($r['confirmed']);
    }
}
