<?php

namespace App\Console\Commands;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Scholar\SourceScreen;
use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\SourceDates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * READ-ONLY audit of stored signals against the CURRENT date rules.
 *
 *   php artisan signals:recheck-dates --tenant=6 [--ids=38,40] [--report=path.json]
 *
 * Signals created before the date extractor existed (or before it was corrected) may carry no date, a date taken from a page that has
 * no single publication date, or an expiry counted from the day we found them. For each stored signal this fetches its source page once
 * (SSRF-safe, short timeout, one at a time), reads the date the page really gives today, and says what is different. It WRITES NOTHING:
 * no status, date, section or expiry is changed. Fixing a record is a separate, explicit decision.
 *
 * Findings
 *   consistent          the page's date matches what is stored
 *   stored_but_now_undated  a date is stored, but the page no longer yields an unambiguous one: treat the stored date as unreliable
 *   now_dated           nothing was stored; the page now gives a date
 *   date_changed        the stored date and the page's date differ
 *   stale_by_current_rules  the page's date is older than the freshness limit: the evidence is not current
 *   undated_expiry      no date is stored, yet the record shows an expiry counted from discovery
 *   unreachable         the page could not be read now (says nothing about the record)
 */
class RecheckSignalDates extends Command
{
    protected $signature = 'signals:recheck-dates {--tenant= : sub_institute_id (required)}
        {--ids= : comma-separated opportunity ids (default: every researched, undismissed signal, max --limit)}
        {--limit=20 : most records to check}
        {--report= : where to save the JSON report}';

    protected $description = 'Read-only: compare stored signals with the dates their source pages give under the current rules. Writes nothing.';

    public function handle(SafeUrlFetcher $fetcher): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }
        $q = CompanyOpportunity::where('sub_institute_id', $tenant)->whereNotNull('source_url')->where('is_sample', false);
        if ($this->option('ids')) {
            $q->whereIn('id', array_map('intval', explode(',', (string) $this->option('ids'))));
        } else {
            $q->where('review_status', '!=', 'Dismissed');
        }

        $report = [];
        foreach ($q->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get() as $o) {
            $r = $this->check($fetcher, $o);
            $report[] = $r;
            $this->line(sprintf('#%-4d %-34s %-24s stored=%-10s page=%-10s %s', $r['id'], mb_substr($r['company'], 0, 33), $r['finding'], $r['stored_date'] ?? 'none', $r['page_date'] ?? 'none', $r['note']));
        }

        $path = (string) ($this->option('report') ?: storage_path('app/signal_date_recheck_' . now()->format('Ymd_His') . '.json'));
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode(['checked_at' => now()->toIso8601String(), 'writes' => 'none', 'results' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Nothing was changed. Report: {$path}");
        $this->line(json_encode(array_count_values(array_column($report, 'finding'))));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function check(SafeUrlFetcher $fetcher, CompanyOpportunity $o): array
    {
        $stored = ($o->source_published_at ?? null)?->toDateString();
        $out = ['id' => (int) $o->id, 'company' => (string) ($o->company->name ?? ''), 'source_url' => $o->source_url, 'review_status' => $o->review_status,
            'feed_section' => $o->feed_section, 'stored_date' => $stored, 'stored_event_date' => $o->event_date?->toDateString(),
            'stored_expires_at' => $o->expires_at?->toDateString(), 'page_date' => null, 'page_date_provenance' => null, 'finding' => 'unreachable', 'note' => ''];

        try {
            $page = $fetcher->fetch((string) $o->source_url, 600000, 10);
        } catch (\Throwable $e) {
            return ['note' => 'The page could not be read now: ' . mb_substr($e->getMessage(), 0, 80)] + $out;
        }
        $text = HtmlText::extract($page['body'])['text'];
        $job = SourceScreen::reliability((string) $o->source_url)['class'] === 'job_post';
        $found = SourceDates::extract($page['body'], $text, now(), (string) config('signals.timezone', 'Asia/Kolkata'), $job);
        $out['page_date'] = $found['date'];
        $out['page_date_provenance'] = $found['provenance'];

        $maxAge = (int) config('sales_pull.qualification.max_age_days', 120);
        $isTender = in_array($o->trigger_type, ['tender', 'rfp', 'eoi', 'rfq'], true);

        if ($found['date'] !== null && ! $isTender && Carbon::parse($found['date'])->lt(now()->subDays($maxAge))) {
            return ['finding' => 'stale_by_current_rules', 'note' => "The page is dated {$found['date']} (via {$found['provenance']}), older than {$maxAge} days: not current evidence."] + $out;
        }
        if ($stored !== null && $found['date'] === null) {
            return ['finding' => 'stored_but_now_undated', 'note' => "A date ({$stored}) is stored but the page gives no single publication date now: treat it as unreliable."] + $out;
        }
        if ($stored === null && $found['date'] !== null) {
            return ['finding' => 'now_dated', 'note' => "Nothing stored; the page gives {$found['date']} (via {$found['provenance']})."] + $out;
        }
        if ($stored !== null && $found['date'] !== null && $stored !== $found['date']) {
            return ['finding' => 'date_changed', 'note' => "Stored {$stored}, the page now gives {$found['date']}."] + $out;
        }
        if ($stored === null && $found['date'] === null && $o->expires_at !== null) {
            return ['finding' => 'undated_expiry', 'note' => 'No date anywhere, yet the record expires ' . $o->expires_at->toDateString() . ', counted from the day it was found.'] + $out;
        }

        return ['finding' => 'consistent', 'note' => $stored === null ? 'Undated, and the page is still undated.' : 'Matches.'] + $out;
    }
}
