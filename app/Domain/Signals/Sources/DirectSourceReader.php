<?php

namespace App\Domain\Signals\Sources;

use App\Domain\Signals\Ingestion\DocumentExtractor;
use App\Domain\Signals\Support\FetchFailure;
use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\SourceDates;
use Illuminate\Support\Carbon;

/**
 * Reads the newest items of ONE verified source directly: its feed if it has one, else the announcement links of its listing page. Each item is a
 * normal research source (title, date, text, `page_fetched` = true) that goes through exactly the same screening, AI reading and qualification gate as
 * a search result. HTML and PDF items are both read.
 *
 * Bounded and polite: every request goes through SafeUrlFetcher (SSRF checks, run request ceiling) and RobotsPolicy; items already read on an earlier
 * run are skipped; at most `$maxItems` items per source; the caller can stop it at any time (`$stop`). It never logs in and never leaves the source's host.
 */
final class DirectSourceReader
{
    public function __construct(private readonly SafeUrlFetcher $fetcher, private readonly RobotsPolicy $robots, private readonly DocumentExtractor $documents)
    {
    }

    /**
     * @param  array<string, mixed>  $source  the registry row (id, url, name) with its verification record under 'verification' (method, feed_url)
     * @param  callable(string): bool  $isSeen  has this page been read on an earlier run?
     * @param  callable(): bool  $stop  true when the run must stop reading (time or request ceiling)
     * @return array{items: list<array<string, mixed>>, report: array<string, mixed>}
     */
    public function read(array $source, int $maxItems, ?Carbon $notBefore, callable $isSeen, callable $stop, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $report = ['source' => (string) $source['id'], 'method' => null, 'listing_read' => false, 'candidates' => 0, 'read' => 0, 'skipped_seen' => 0, 'refused_robots' => 0, 'failed' => []];
        $verification = (array) ($source['verification'] ?? []);
        $feed = (string) ($verification['feed_url'] ?? '');
        $candidates = [];

        try {
            if ($feed !== '') {
                $report['method'] = 'feed';
                if (! $this->robots->allows($feed)) {
                    $report['refused_robots']++;
                } else {
                    $page = $this->fetcher->fetch($feed, 1500000, 10, true, SourceReader::FEED_TYPES);
                    $report['listing_read'] = true;
                    foreach (SourceReader::parseFeed($page['body']) as $i) {
                        $candidates[] = ['url' => $i['url'], 'title' => $i['title'], 'date' => $i['date']];
                    }
                }
            } else {
                $report['method'] = 'listing';
                $url = (string) $source['url'];
                if (! $this->robots->allows($url)) {
                    $report['refused_robots']++;
                } else {
                    $page = $this->fetcher->fetch($url, 1500000, 10);
                    $report['listing_read'] = true;
                    foreach (SourceReader::listingLinks($page['body'], $page['url'], $maxItems * 4, in_array((string) ($source['category'] ?? ''), ['news', 'tender_aggregator', 'vendor'], true)) as $l) {
                        $candidates[] = ['url' => $l['url'], 'title' => $l['title'], 'date' => null];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->fail($report, $e);

            return ['items' => [], 'report' => $report];
        }

        $report['candidates'] = count($candidates);
        $items = [];
        foreach ($candidates as $c) {
            if (count($items) >= $maxItems || $stop()) {
                break;
            }
            if ($c['date'] !== null && $notBefore !== null && $c['date'] < $notBefore->toDateString()) {
                continue;   // dated before the freshness window
            }
            if ($isSeen($c['url'])) {
                $report['skipped_seen']++;

                continue;
            }
            try {
                if (! $this->robots->allows($c['url'])) {
                    $report['refused_robots']++;

                    continue;
                }
                $page = $this->fetcher->fetch($c['url'], 3000000, 12, true, ['application/pdf']);
            } catch (\Throwable $e) {
                $this->fail($report, $e);
                if (FetchFailure::category($e) === 'budget') {
                    break;
                }

                continue;
            }
            $item = $this->item($source, $c, $page, $now);
            if ($item === null) {
                $this->fail($report, new \RuntimeException('empty'));

                continue;
            }
            $items[] = $item;
            $report['read']++;
        }

        return ['items' => $items, 'report' => $report];
    }

    /** @return array<string, mixed>|null */
    private function item(array $source, array $candidate, array $page, Carbon $now): ?array
    {
        $tz = (string) config('signals.timezone', 'Asia/Kolkata');
        if (($page['content_type'] ?? '') === 'application/pdf') {
            try {
                $segments = $this->documents->extract($page['body'], 'pdf')['segments'];
            } catch (\Throwable) {
                return null;
            }
            $text = trim(implode("\n", array_column($segments, 'text')));
            $title = $candidate['title'];
            $date = SourceDates::extract('', $text, $now, $tz, false);
        } else {
            $html = HtmlText::extract($page['body']);
            $text = $html['text'];
            $title = $html['title'] ?: $candidate['title'];
            $date = SourceDates::extract($page['body'], $text, $now, $tz, false);
        }
        if (mb_strlen($text) < 80) {
            return null;   // a shell page or an image-only PDF: nothing to read
        }
        // The feed's own date is what the source says; otherwise the page's own metadata or text.
        $published = $candidate['date'] ?? $date['date'];

        return [
            'id' => null, 'url' => $page['url'], 'title' => mb_substr($title, 0, 300), 'published_at' => $published,
            'date_provenance' => $candidate['date'] !== null ? 'source_feed' : $date['provenance'],
            'date_confidence' => $candidate['date'] !== null ? 'medium' : $date['confidence'],
            'text' => mb_substr($text, 0, 6000), 'page_fetched' => true, 'retrieved_at' => $now->toIso8601String(),
            'origin' => 'direct', 'registry_id' => (string) $source['id'],
        ];
    }

    /** @param array<string, mixed> $report */
    private function fail(array &$report, \Throwable $e): void
    {
        $k = FetchFailure::category($e);
        $report['failed'][$k] = ($report['failed'][$k] ?? 0) + 1;
    }
}
