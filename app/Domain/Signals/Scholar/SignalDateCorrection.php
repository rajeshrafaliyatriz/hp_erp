<?php

namespace App\Domain\Signals\Scholar;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Market\OpportunityEvent;
use App\Domain\Signals\Market\SectionAssigner;
use App\Domain\Signals\Market\SignalStrength;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the DATE of one stored signal, and everything that depends on it, using the SAME rules a fresh signal gets (SignalStrength,
 * the freshness limit, the trigger's decay, the undated-keep window). It exists because signals stored before the date extractor was
 * correct may be undated, wrongly dated, or aged from the day they were found.
 *
 * What it changes: source_published_at, event_date (only when it was merely a copy of the old page date), expires_at, urgency, priority,
 * feed_section, score_reasoning, and the date / strength parts of `assessment` (with a `corrections` history entry).
 * What it never changes: the record id, review_status, pipeline_status, review notes, first_discovered_at, observed_at (when we found it),
 * the source URL, the company or its identity, the buying-intent level, the sales-pull and product-fit assessments.
 * Every change writes an `date_corrected` row to g2g_opportunity_events with the before and after values, so the history is auditable.
 *
 * The new date is passed in and must come from the source page (the command re-reads it). Null means "the page gives no defensible single
 * date": the stored date is removed and the signal becomes undated (at most WATCH, kept for the undated window).
 */
final class SignalDateCorrection
{
    /**
     * @param  array{provenance?: ?string, confidence?: ?string, note?: ?string}  $source
     * @return array{changed: bool, changes: array<string, array{0: mixed, 1: mixed}>, strength: string, stale: bool}
     */
    public function apply(CompanyOpportunity $o, ?string $sourceDate, array $source = [], bool $write = true, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $a = is_array($o->assessment) ? $o->assessment : [];
        $oldSource = $o->source_published_at?->toDateString();
        $oldEvent = $o->event_date?->toDateString();
        $isContext = in_array($o->feed_section, [SectionAssigner::MARKET, SectionAssigner::COMPETITOR], true);
        $isTender = in_array($o->trigger_type, ['tender', 'rfp', 'eoi', 'rfq'], true);

        // An event date that was only the page's date copied by the model is not an event date.
        $newEvent = ($oldEvent !== null && $oldEvent === $oldSource) ? null : $oldEvent;
        if ($sourceDate === null && $oldEvent === $oldSource) {
            $newEvent = null;
        }

        $level = (string) ($o->buying_intent ?: ($a['buying_intent']['level'] ?? 'unconfirmed'));
        $assessed = (new SignalStrength())->assess([
            'trigger_type' => (string) ($o->trigger_type ?: 'other'), 'intent' => BuyingIntent::toIntent($level), 'buying_intent' => $level,
            'event_date' => $newEvent, 'source_published_at' => $sourceDate, 'observed_at' => $o->observed_at?->toDateString(),
            'expires_at' => $isTender ? $o->expires_at?->toDateString() : null, 'quote_verified' => (bool) ($a['evidence']['quote_verified'] ?? true),
        ], $now);

        // Stale: a dated, non-tender source older than the freshness limit is historical evidence, not a current opportunity.
        $date = $newEvent ?? $sourceDate;
        $maxAge = (int) config('sales_pull.qualification.max_age_days', 120);
        $stale = ! $isTender && $date !== null && Carbon::parse($date)->lt($now->copy()->subDays($maxAge));
        $strength = $stale ? SignalStrength::WATCH : $assessed['strength'];
        $reason = $stale
            ? "The source is dated {$date}, older than {$maxAge} days: this is historical evidence, not a current opportunity."
            : $assessed['reason'];
        $age = $date !== null ? max(0, (int) Carbon::parse($date)->startOfDay()->diffInDays($now->copy()->startOfDay(), false)) : null;

        // Expiry exactly as a fresh signal gets it. A tender keeps its own closing date.
        $expires = $o->expires_at?->toDateString();
        if (! $isTender) {
            $decay = IcpPack::forRun()->decayDays((string) ($o->trigger_type ?: 'other'));
            if ($decay !== null) {
                $base = $date !== null ? Carbon::parse($date) : ($o->observed_at ?? $now);
                if ($date === null) {
                    $decay = min($decay, max(1, (int) config('sales_pull.qualification.undated_keep_days', 30)));
                }
                $expires = $base->copy()->addDays($decay)->toDateString();
            }
        }

        $new = [
            'source_published_at' => $sourceDate, 'event_date' => $newEvent, 'expires_at' => $expires,
            'urgency' => $isContext ? $o->urgency : SignalStrength::BAND[$strength],
            'priority' => ['hot' => 'High', 'warm' => 'Medium', 'watch' => 'Low'][$strength],
            'feed_section' => $isContext ? $o->feed_section : SignalStrength::SECTION[$strength],
            'score_reasoning' => $reason,
        ];
        $changes = [];
        foreach ($new as $col => $value) {
            $old = $o->{$col};
            $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;
            if ((string) $old !== (string) $value) {
                $changes[$col] = [$old, $value];
            }
        }

        // The assessment keeps its other parts; its date and strength parts follow the corrected date.
        $e = $a['evidence'] ?? [];
        $e['source_published_at'] = $sourceDate;
        $e['date_provenance'] = $sourceDate !== null ? ($source['provenance'] ?? null) : null;
        $e['date_confidence'] = $sourceDate !== null ? ($source['confidence'] ?? null) : null;
        $e['date_status'] = $newEvent !== null ? 'event_date_stated' : ($sourceDate !== null ? 'source_date_only' : 'undated');
        $a['evidence'] = $e;
        $a['opportunity'] = ['strength' => $strength, 'reason' => $reason, 'age_days' => $age] + ($stale ? ['stale' => true] : []);
        $unverified = array_values(array_filter((array) ($a['unverified'] ?? []), fn ($u) => ! str_contains((string) $u, 'The source gives no date')));
        if ($sourceDate === null) {
            array_unshift($unverified, 'The source gives no date, so how recent this is is unknown.');
        }
        $a['unverified'] = $unverified;
        $a['corrections'] = array_merge((array) ($a['corrections'] ?? []), [[
            'at' => $now->toIso8601String(), 'by' => 'signals:correct-dates', 'reason' => $source['note'] ?? 'date re-read from the source page',
            'from' => ['source_published_at' => $oldSource, 'event_date' => $oldEvent, 'strength' => $o->assessment['opportunity']['strength'] ?? null],
            'to' => ['source_published_at' => $sourceDate, 'event_date' => $newEvent, 'strength' => $strength],
        ]]);

        $changed = $changes !== [] || ($o->assessment['evidence']['source_published_at'] ?? null) !== $sourceDate || ($o->assessment['opportunity']['strength'] ?? null) !== $strength;
        if (! $write || ! $changed) {
            return ['changed' => $changed, 'changes' => $changes, 'strength' => $strength, 'stale' => $stale];
        }

        DB::transaction(function () use ($o, $new, $a, $changes, $source, $now) {
            // first_discovered_at is assigned to itself so the column can never reset on an UPDATE (as MarketImporter does).
            $o->forceFill($new + ['assessment' => $a]);
            CompanyOpportunity::where('id', $o->id)->update(
                $o->getDirty() + ['first_discovered_at' => DB::raw(DB::getQueryGrammar()->wrap('first_discovered_at')), 'updated_at' => $now]
            );
            OpportunityEvent::create([
                'sub_institute_id' => $o->sub_institute_id, 'opportunity_id' => $o->id, 'event_type' => 'date_corrected',
                'changes' => $changes + ['evidence' => $source['note'] ?? null], 'scan_log_id' => null,
            ]);
        });

        return ['changed' => true, 'changes' => $changes, 'strength' => $strength, 'stale' => $stale];
    }
}
