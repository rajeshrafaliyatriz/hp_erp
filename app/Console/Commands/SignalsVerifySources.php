<?php

namespace App\Console\Commands;

use App\Domain\Signals\Sources\SourceReader;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Sources\SourceSelector;
use App\Domain\Signals\Sources\SourceVerificationStore;
use App\Domain\Signals\Support\FetchFailure;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use Illuminate\Console\Command;

/**
 * Checks, with real but light requests, how each registry source can be read, and records the result. Opens no database. For each source with an
 * address it makes ONE request for its configured page (plus robots.txt once per site, and at most one more for a feed it advertises), at a polite
 * pace, public pages only, never logging in. A source becomes `verified_operational` only if a feed or a listing of announcement links was actually
 * read from it. Everything else is recorded with the reason, so a blocked source is known to be blocked.
 */
class SignalsVerifySources extends Command
{
    protected $signature = 'signals:verify-sources
        {--only= : Verify only this source id (src-…) }
        {--limit=0 : Verify at most this many sources (0 = all)}
        {--delay-ms=1500 : Pause between sources}
        {--recheck : Also re-verify sources that already have a result}';
    protected $description = 'Fetch each registry source once, record whether its feed or announcement listing can be read, and mark it verified only if it can. No database.';

    /** Failure categories that describe the network at that moment, not the source. */
    public const TRANSIENT_FAILURES = ['dns', 'timeout', 'connection', 'http_429', 'http_5xx', 'budget', 'other'];

    public function handle(): int
    {
        $store = new SourceVerificationStore();
        $registry = SourceRegistry::fromStorage($store);
        $fetcher = new SafeUrlFetcher();
        $robots = new RobotsPolicy($fetcher);
        $rows = array_values(array_filter($registry->all(), fn ($s) => $s['url'] !== null
            && ! in_array($s['retrieval_method'], SourceSelector::DEFAULT_EXCLUDED_METHODS, true)
            && ($this->option('only') ? $s['id'] === $this->option('only') : true)
            && ($this->option('recheck') || $this->option('only') || $s['last_verified_at'] === null)));
        if ((int) $this->option('limit') > 0) {
            $rows = array_slice($rows, 0, (int) $this->option('limit'));
        }
        $this->line(count($rows) . ' source(s) to check.');

        $tally = [];
        foreach ($rows as $i => $s) {
            $r = $this->check($s, $fetcher, $robots);
            // A passing network problem says nothing about the source, so it is NOT written as a verification result: the source stays
            // unverified and the next run (without --recheck) tries it again. Only a definite answer (readable, or a real reason it is not) is stored.
            if (! $r['machine_readable'] && in_array($r['failure'] ?? null, self::TRANSIENT_FAILURES, true)) {
                $outcome = 'not checked (try again later):' . $r['failure'];
                $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
                $this->line(sprintf('%-9s %-44s %s', '??', mb_substr($s['name'], 0, 44), $outcome));
                if ($i < count($rows) - 1) {
                    usleep(max(0, (int) $this->option('delay-ms')) * 1000);
                }

                continue;
            }
            $store->record($s['id'], $r);
            $outcome = $r['machine_readable'] ? 'readable:' . $r['method'] : 'not readable:' . ($r['failure'] ?? 'no feed or announcement links');
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
            $this->line(sprintf('%-9s %-44s %s', $r['machine_readable'] ? 'OK' : '--', mb_substr($s['name'], 0, 44), $outcome));
            if ($i < count($rows) - 1) {
                usleep(max(0, (int) $this->option('delay-ms')) * 1000);
            }
        }
        ksort($tally);
        $this->line('Summary: ' . json_encode($tally));

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function check(array $s, SafeUrlFetcher $fetcher, RobotsPolicy $robots): array
    {
        $base = ['verified_at' => now()->toDateString(), 'http_status' => null, 'machine_readable' => false, 'method' => 'none', 'feed_url' => null,
            'listing_links' => 0, 'content_type' => null, 'failure' => null];
        try {
            $decision = $robots->decision($s['url']);
            if (! $decision['allowed']) {
                // robots.txt could not be read right now (a timeout, a server error): not a refusal, so retry later instead of recording one.
                return ['failure' => $decision['reason'] === 'robots_unavailable' ? 'timeout' : 'robots_disallowed'] + $base;
            }
            $page = $fetcher->fetch($s['url'], 1500000, 12, true, ['application/pdf']);
        } catch (\Throwable $e) {
            return ['failure' => FetchFailure::category($e)] + $base;
        }
        $base['http_status'] = $page['status'];
        $base['content_type'] = $page['content_type'];
        if ($page['content_type'] === 'application/pdf') {
            return ['machine_readable' => true, 'method' => 'pdf'] + $base;
        }

        foreach (SourceReader::feedLinks($page['body'], $page['url']) as $feed) {
            try {
                if ($robots->allows($feed) && SourceReader::parseFeed($fetcher->fetch($feed, 1500000, 10, true, SourceReader::FEED_TYPES)['body']) !== []) {
                    return ['machine_readable' => true, 'method' => 'feed', 'feed_url' => $feed] + $base;
                }
            } catch (\Throwable) {
                // an advertised feed that does not work: fall back to the listing
            }
        }
        $links = count(SourceReader::listingLinks($page['body'], $page['url'], 12, in_array((string) $s['category'], ['news', 'tender_aggregator', 'vendor'], true)));
        $base['listing_links'] = $links;

        return $links >= 3
            ? ['machine_readable' => true, 'method' => 'listing'] + $base
            : ['failure' => $links === 0 ? 'no_announcement_links' : 'too_few_announcement_links'] + $base;
    }
}
