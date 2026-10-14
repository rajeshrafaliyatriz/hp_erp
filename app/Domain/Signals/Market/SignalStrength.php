<?php

namespace App\Domain\Signals\Market;

use Illuminate\Support\Carbon;

/**
 * How hot is a ScholarClone buying signal? Decided HERE, by plain rules, never by the model scoring itself.
 *
 * An UNDATED source (no publication or event date) is at most WATCH, whatever it says.
 *
 *   HOT    a school is, right now, doing something only a school that needs our product does:
 *          an open tender, a public ask for an ERP/LMS vendor, hiring someone to run or migrate a school system,
 *          or any fresh statement of a direct need.                          -> approach this week
 *   WARM   an event that usually opens a purchase (a new campus, a new principal, a stated pain with the current
 *          system) and is still fresh.                                       -> approach this month
 *          HIRING is different: a hiring post alone (teachers, counsellors, coordinators) shows a school is staffing, not that it
 *          is buying software. It is WARM only when the buying-intent assessment also shows a project (hiring for a system role,
 *          a documented implementation, a vendor search); otherwise it stays on WATCH.
 *   WATCH  related and recent enough to keep an eye on, nothing to act on yet.
 *
 * The model only reports WHAT the source says and whether it is a direct need (`intent`). The quote is already
 * verified verbatim by the researcher before a record gets here. Freshness is measured from the event (or the
 * source's publication date), never from the day we happened to find it.
 *
 * Pure: no database, the clock is passed in, so every rule is unit-testable.
 */
final class SignalStrength
{
    public const HOT = 'hot';
    public const WARM = 'warm';
    public const WATCH = 'watch';

    public const INTENTS = ['direct_need', 'likely_need', 'context'];

    /** Closing-date style notices: they stay open until their own closing date. */
    private const TENDERS = ['tender', 'rfp', 'eoi', 'rfq'];

    /** How many days after the event each kind of signal is still worth acting on, and how fresh a HOT one must be. */
    private const WARM_DAYS = ['expansion' => 90, 'leadership' => 180, 'hiring' => 60, 'pain_point' => 60, 'vendor_search' => 60, 'regulation' => 45, 'other' => 45];
    private const HOT_DAYS = ['vendor_search' => 45, 'hiring' => 45, 'pain_point' => 21, 'expansion' => 30, 'leadership' => 30, 'other' => 30, 'regulation' => 21];

    /** Urgency band stored on the opportunity (CardPresenter's vocabulary), and the feed section for each strength. */
    public const BAND = [self::HOT => 'act_this_week', self::WARM => 'act_this_month', self::WATCH => 'nurture'];
    public const SECTION = [self::HOT => SectionAssigner::ACT_NOW, self::WARM => SectionAssigner::WATCHING, self::WATCH => SectionAssigner::WATCHING];

    /**
     * @param  array{trigger_type: string, intent?: ?string, buying_intent?: ?string, event_date?: ?string, source_published_at?: ?string, observed_at?: ?string, expires_at?: ?string, quote_verified?: bool}  $s
     * @return array{strength: string, reason: string, age_days: ?int}
     */
    public function assess(array $s, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->startOfDay();
        $trigger = (string) ($s['trigger_type'] ?? 'other');
        $intent = in_array($s['intent'] ?? null, self::INTENTS, true) ? $s['intent'] : 'likely_need';
        $verified = (bool) ($s['quote_verified'] ?? true);

        $base = $this->firstDate($s['event_date'] ?? null, $s['source_published_at'] ?? null, $s['observed_at'] ?? null);
        $dated = ($s['event_date'] ?? null) !== null || ($s['source_published_at'] ?? null) !== null;
        // Only a date the SOURCE gives is an age. The day we found it says nothing about how old the event is.
        $age = ($base === null || ! $dated) ? null : max(0, (int) $base->copy()->startOfDay()->diffInDays($now, false));
        $when = $age === null ? '' : ($age === 0 ? ' today' : ($age === 1 ? ' yesterday' : " {$age} days ago"));

        // A tender is open until its own closing date.
        if (in_array($trigger, self::TENDERS, true)) {
            $closes = $this->firstDate($s['expires_at'] ?? null);
            if ($closes !== null) {
                $left = (int) $now->diffInDays($closes->copy()->startOfDay(), false);
                if ($left < 0) {
                    return $this->result(self::WATCH, 'The tender has closed. Keep it only to know who bought.', $age);
                }

                return $this->result($verified ? self::HOT : self::WARM, $left === 0 ? 'A tender for school software closes today.' : "A tender for school software is open and closes in {$left} " . ($left === 1 ? 'day' : 'days') . '.', $age);
            }
            if ($age !== null && $age <= 60) {
                return $this->result($verified ? self::HOT : self::WARM, "A tender for school software was published{$when}; no closing date is stated, so check it is still open.", $age);
            }

            return $this->result(self::WATCH, $age === null
                ? 'The tender states no date and no closing date, so it is not known to be open: check the portal.'
                : 'A tender was published a while ago and states no closing date.', $age);
        }

        if ($intent === 'context') {
            return $this->result(self::WATCH, 'Background about the school; nothing to act on yet.', $age);
        }

        $hotDays = self::HOT_DAYS[$trigger] ?? 30;
        $warmDays = self::WARM_DAYS[$trigger] ?? 45;

        // Without a date a stale page and a fresh one look the same, so an undated source is never hot, never warm and never "act this
        // month": it stays on WATCH until someone (or a later run) finds its date. Unknown stays unknown.
        if ($age === null || ! $dated) {
            return $this->result(self::WATCH, 'The source gives no date, so how recent this is is unknown. It stays on watch until the date is checked.', $age);
        }

        // Hiring alone is not procurement momentum: without a project-level buying indicator it is background, however fresh.
        // (`buying_intent` is the assessed level; when a caller does not supply it the rule cannot apply and nothing changes.)
        $level = $s['buying_intent'] ?? null;
        if ($trigger === 'hiring' && is_string($level) && ! \App\Domain\Signals\Scholar\BuyingIntent::atLeast($level, 'moderate')) {
            return $this->result(self::WATCH, 'Hiring on its own does not show the school is buying software. It stays on watch until a system-related role, a tender or a vendor search is found.', $age);
        }

        if ($intent === 'direct_need' && $age <= $hotDays && $verified) {
            return $this->result(self::HOT, $this->hotReason($trigger) . $when . '.', $age);
        }
        if (($intent === 'direct_need' || $intent === 'likely_need') && $age <= $warmDays) {
            return $this->result(self::WARM, $this->warmReason($trigger, $intent) . $when . '.', $age);
        }

        return $this->result(self::WATCH, 'Related to the school, but older than we would act on.', $age);
    }

    private function hotReason(string $trigger): string
    {
        return match ($trigger) {
            'vendor_search' => 'The school is publicly looking for a school ERP or learning system',
            'hiring' => 'The school is hiring to run or move its school systems',
            'pain_point' => 'The school has publicly raised a problem with its current system',
            'expansion' => 'The school is opening a new campus and needs systems from day one',
            'leadership' => 'New leadership has said it will review the school\'s systems',
            default => 'The school has stated a clear need for what we sell',
        };
    }

    private function warmReason(string $trigger, string $intent): string
    {
        return match ($trigger) {
            'expansion' => 'A new campus or branch usually needs systems set up',
            'leadership' => 'New leaders usually review systems in their first months',
            'hiring' => 'The school is hiring in an area our product supports',
            'pain_point' => 'The school has shown a problem our product solves',
            'vendor_search' => 'The school is looking at school software',
            default => $intent === 'direct_need' ? 'The school has stated a need for what we sell' : 'This kind of event often leads to a purchase',
        };
    }

    /** @return array{strength: string, reason: string, age_days: ?int} */
    private function result(string $strength, string $reason, ?int $age): array
    {
        return ['strength' => $strength, 'reason' => $reason, 'age_days' => $age];
    }

    private function firstDate(mixed ...$values): ?Carbon
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                try {
                    return Carbon::parse($v);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
