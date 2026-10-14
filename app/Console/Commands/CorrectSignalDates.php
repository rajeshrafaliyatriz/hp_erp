<?php

namespace App\Console\Commands;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Scholar\SignalDateCorrection;
use App\Domain\Signals\Support\SafeUrlFetcher;
use Illuminate\Console\Command;

/**
 * Corrects the dates of stored signals from what their SOURCE PAGES say today, with the audit trail, and nothing else.
 *
 *   php artisan signals:correct-dates --tenant=6 --ids=38,39,40            dry run: shows exactly what would change, writes nothing
 *   php artisan signals:correct-dates --tenant=6 --ids=38,39,40 --apply    applies it
 *
 * Each record's source page is re-read (SSRF-safe, short timeout) by the same check as signals:recheck-dates, and only a finding the
 * page supports is acted on:
 *   stale_by_current_rules   the page is dated and older than the freshness limit  -> dated, shown as historical evidence (watch, expired)
 *   now_dated                nothing stored, the page gives a date                  -> dated, strength recomputed by the usual rules
 *   stored_but_now_undated   a date is stored but the page has no single date       -> the date is removed; undated (at most watch)
 *   date_changed             the page's date differs from the stored one            -> the page's date
 * Anything else (consistent, unreachable, undated_expiry) is left alone. The record id, statuses, notes, identity, intent and the
 * assessments other than the date and strength are never touched (see SignalDateCorrection). Without --ids it refuses to guess.
 */
class CorrectSignalDates extends Command
{
    protected $signature = 'signals:correct-dates {--tenant= : sub_institute_id (required)}
        {--ids= : comma-separated opportunity ids (required)}
        {--reassess : also re-run records whose date is already right through the current strength rules (no page date change)}
        {--apply : write the corrections (default: dry run)}';

    protected $description = 'Correct stored signals\' dates from their source pages, with an audit event per change. Dry run unless --apply.';

    private const ACT_ON = ['stale_by_current_rules', 'now_dated', 'stored_but_now_undated', 'date_changed'];

    public function handle(SafeUrlFetcher $fetcher, RecheckSignalDates $recheck, SignalDateCorrection $correction): int
    {
        $tenant = (int) $this->option('tenant');
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('ids'))));
        if ($tenant <= 0 || $ids === []) {
            $this->error('--tenant and --ids are required: this never corrects records it was not told about.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'APPLYING corrections.' : 'DRY RUN: nothing is written. Add --apply to write.');

        foreach (CompanyOpportunity::where('sub_institute_id', $tenant)->whereIn('id', $ids)->orderBy('id')->get() as $o) {
            $r = $recheck->check($fetcher, $o);
            if (! in_array($r['finding'], self::ACT_ON, true)) {
                if ($this->option('reassess') && $r['finding'] === 'consistent') {
                    // The date is right; only the rules that depend on it may have changed. Same service, same date, same audit trail.
                    $e = (array) ($o->assessment['evidence'] ?? []);
                    $res = $correction->apply($o, $o->source_published_at?->toDateString(), [
                        'provenance' => $e['date_provenance'] ?? null, 'confidence' => $e['date_confidence'] ?? null,
                        'note' => 'reassessed under the current strength rules; the date is unchanged',
                    ], $apply);
                    $this->line(sprintf('#%d %s: reassessed -> strength %s%s', $o->id, $r['company'], $res['strength'], $res['changed'] ? '' : ' (no change)'));
                    foreach ($res['changes'] as $col => [$from, $to]) {
                        $this->line(sprintf('      %-20s %s  =>  %s', $col, $from === null ? 'null' : (string) $from, $to === null ? 'null' : mb_substr((string) $to, 0, 90)));
                    }

                    continue;
                }
                $this->line(sprintf('#%d %s: %s: left alone. %s', $o->id, $r['company'], $r['finding'], $r['note']));

                continue;
            }
            $prov = (string) ($r['page_date_provenance'] ?? '');
            $res = $correction->apply($o, $r['page_date'], [
                'provenance' => $prov !== '' ? $prov : null, 'confidence' => in_array($prov, ['structured_data', 'meta_tag', 'time_element'], true) ? 'high' : ($prov !== '' ? 'medium' : null),
                'note' => "{$r['finding']}: {$r['note']}",
            ], $apply);
            $this->line(sprintf('#%d %s: %s -> strength %s%s', $o->id, $r['company'], $r['finding'], $res['strength'], $res['stale'] ? ' (stale: historical evidence)' : ''));
            foreach ($res['changes'] as $col => [$from, $to]) {
                $this->line(sprintf('      %-20s %s  =>  %s', $col, $from === null ? 'null' : (string) $from, $to === null ? 'null' : mb_substr((string) $to, 0, 90)));
            }
        }

        return self::SUCCESS;
    }
}
