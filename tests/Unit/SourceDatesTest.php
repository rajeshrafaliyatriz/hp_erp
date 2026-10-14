<?php

namespace Tests\Unit;

use App\Domain\Signals\Support\SourceDates;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Publication dates come from the page, never from when it was fetched; unknown stays unknown. Pure. */
class SourceDatesTest extends TestCase
{
    private function at(): Carbon
    {
        return Carbon::parse('2026-10-14 08:00:00', 'UTC');   // 13:30 in Asia/Kolkata, same day
    }

    private function x(string $html, string $text = '', bool $job = false): array
    {
        return SourceDates::extract($html, $text, $this->at(), 'Asia/Kolkata', $job);
    }

    public function test_json_ld_date_published_is_high_confidence(): void
    {
        $html = '<html><head><script type="application/ld+json">{"@type":"NewsArticle","headline":"x","datePublished":"2026-10-02T09:30:00+05:30"}</script></head><body>x</body></html>';
        $this->assertSame(['date' => '2026-10-02', 'provenance' => 'structured_data', 'confidence' => 'high'], $this->x($html));
    }

    public function test_a_job_posting_date_posted_in_nested_json_ld_is_found(): void
    {
        $html = '<script type="application/ld+json">{"@graph":[{"@type":"Organization"},{"@type":"JobPosting","title":"ERP Administrator","datePosted":"2026-10-09"}]}</script>';
        $this->assertSame('2026-10-09', $this->x($html)['date']);
        $this->assertSame('structured_data', $this->x($html)['provenance']);
    }

    public function test_open_graph_and_meta_tags_and_time_elements(): void
    {
        $this->assertSame(['date' => '2026-09-28', 'provenance' => 'meta_tag', 'confidence' => 'high'],
            $this->x('<head><meta property="article:published_time" content="2026-09-28T04:00:00Z"></head>'));
        $this->assertSame('meta_tag', $this->x('<span itemprop="datePublished" content="2026-09-20"></span>')['provenance']);
        $this->assertSame(['date' => '2026-10-01', 'provenance' => 'time_element', 'confidence' => 'high'], $this->x('<body><time datetime="2026-10-01">1 Oct</time></body>'));
    }

    public function test_structured_data_beats_visible_text(): void
    {
        $r = $this->x('<meta property="article:published_time" content="2026-09-01">', 'Posted 2 days ago');
        $this->assertSame('2026-09-01', $r['date']);
    }

    public function test_a_labelled_visible_date_is_medium_and_a_bare_date_is_ignored(): void
    {
        $this->assertSame(['date' => '2026-10-12', 'provenance' => 'visible_absolute', 'confidence' => 'medium'], $this->x('', 'Vacancy for ERP Coordinator. Posted on 12 Oct 2026. Apply now.'));
        $this->assertSame('2026-10-05', $this->x('', 'Published: October 5, 2026 by staff')['date']);
        $this->assertNull($this->x('', 'The school was founded on 12 October 2001 and has 40 teachers.')['date'], 'a date in running text is not a publication date');
    }

    public function test_relative_dates_are_resolved_from_the_retrieval_time_in_the_stated_timezone(): void
    {
        $this->assertSame(['date' => '2026-10-11', 'provenance' => 'visible_relative', 'confidence' => 'medium'], $this->x('', 'Posted 3 days ago'));
        $this->assertSame('2026-09-30', $this->x('', 'Job posted 2 weeks ago in Surat')['date']);
        $this->assertSame('2026-10-14', $this->x('', 'Posted today')['date']);
        $this->assertSame('2026-10-13', $this->x('', 'Posted yesterday')['date']);
        // The retrieval clock is read in the audience's timezone: 20:00 UTC on the 14th is already the 15th in India.
        $late = SourceDates::extract('', 'Posted today', Carbon::parse('2026-10-14 20:00:00', 'UTC'), 'Asia/Kolkata');
        $this->assertSame('2026-10-15', $late['date']);
    }

    public function test_a_bare_relative_date_counts_only_on_a_job_post(): void
    {
        $this->assertNull($this->x('', 'The principal said so 3 days ago at the meeting')['date']);
        $this->assertSame('2026-10-11', $this->x('', 'Mumbai, Maharashtra · 3 days ago · 25 applicants', true)['date']);
    }

    public function test_months_future_dates_ambiguous_numeric_dates_and_nonsense_stay_unknown(): void
    {
        $this->assertNull($this->x('', 'Posted 2 months ago')['date'], 'months are too coarse to be a day');
        $this->assertNull($this->x('<meta property="article:published_time" content="2027-03-01">')['date'], 'future');
        $this->assertNull($this->x('<meta property="article:published_time" content="1999-01-01">')['date'], 'implausibly old');
        $this->assertNull($this->x('<meta name="date" content="03/04/2026">')['date'], 'day/month order is ambiguous');
        $this->assertNull($this->x('<meta name="date" content="not a date">')['date']);
        $this->assertNull($this->x('', 'Last login 3 hours ago')['date']);
        $this->assertSame(['date' => null, 'provenance' => null, 'confidence' => null], $this->x('<html><body>Nothing here</body></html>', 'Nothing here'));
    }

    public function test_the_retrieval_time_is_never_returned_as_the_publication_date(): void
    {
        $this->assertNull($this->x('<html><body>An ordinary page</body></html>', 'An ordinary page with no date at all')['date']);
    }

    public function test_nested_dates_of_comments_and_related_items_are_ignored_and_a_page_of_many_posts_has_no_single_date(): void
    {
        $post = json_encode(['@type' => 'VideoObject', 'datePublished' => '2026-02-20T10:14:52Z',
            'comment' => [['@type' => 'Comment', 'dateCreated' => '2026-03-24T10:35:14Z'], ['@type' => 'Comment', 'dateCreated' => '2026-03-16T05:12:32Z']]]);
        $r = $this->x('<script type="application/ld+json">' . $post . '</script>');
        $this->assertSame('2026-02-20', $r['date'], 'the posts own date, not the dates of its comments');

        $company = json_encode(['@graph' => [
            ['@type' => 'SocialMediaPosting', 'datePublished' => '2026-10-10T04:16:46Z'],
            ['@type' => 'SocialMediaPosting', 'datePublished' => '2026-03-02T08:00:00Z'],
            ['@type' => 'Organization', 'name' => 'A School'],
        ]]);
        $this->assertNull($this->x('<script type="application/ld+json">' . $company . '</script>')['date'], 'a company page of several posts is undated');
    }

    public function test_a_modification_date_is_never_returned_as_a_publication_date(): void
    {
        // "Updated" and dateModified are when the page last CHANGED.
        $this->assertNull($this->x('', 'Last updated: 12 Oct 2026. Welcome to our school.')['date']);
        $this->assertNull($this->x('', 'Updated on 12 October 2026')['date']);
        $this->assertNull($this->x('<meta property="article:modified_time" content="2026-10-12T08:00:00Z">')['date']);
        $this->assertNull($this->x('<script type="application/ld+json">{"@type":"WebPage","dateModified":"2026-10-12"}</script>')['date']);
        // A labelled publication date alongside it is still the publication date.
        $this->assertSame('2026-09-01', $this->x('', 'Published: 1 Sep 2026. Updated: 12 Oct 2026.')['date']);
    }

    public function test_the_modification_date_is_found_separately_with_its_own_provenance(): void
    {
        $m = fn (string $html, string $text = '', ?string $header = null) => SourceDates::modified($html, $text, $this->at(), 'Asia/Kolkata', $header);

        $this->assertSame(['date' => '2026-10-12', 'provenance' => 'structured_data_modified', 'confidence' => 'high'],
            $m('<script type="application/ld+json">{"@type":"WebPage","datePublished":"2026-01-01","dateModified":"2026-10-12T08:00:00Z"}</script>'));
        $this->assertSame(['date' => '2026-09-20', 'provenance' => 'meta_modified', 'confidence' => 'high'],
            $m('<meta property="article:modified_time" content="2026-09-20T04:00:00Z">'));
        $this->assertSame(['date' => '2026-08-15', 'provenance' => 'visible_modified', 'confidence' => 'medium'], $m('', 'Home. Last updated: 15 August 2026.'));
        $this->assertSame(['date' => '2026-07-01', 'provenance' => 'http_last_modified', 'confidence' => 'low'], $m('', '', 'Wed, 01 Jul 2026 10:00:00 GMT'));

        // A dynamic page's Last-Modified is the time of the request: ignored.
        $this->assertNull($m('', '', 'Wed, 14 Oct 2026 08:00:00 GMT')['date']);
        // Several posts with different modification dates: no single date.
        $this->assertNull($m('<script type="application/ld+json">{"@graph":[{"dateModified":"2026-10-10"},{"dateModified":"2026-03-02"}]}</script>')['date']);
        // Future and nonsense stay unknown.
        $this->assertNull($m('<meta property="article:modified_time" content="2027-05-01">')['date']);
        $this->assertNull($m('', 'The school was last modified... never')['date']);
    }
}
