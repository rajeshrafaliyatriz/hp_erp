<?php

namespace App\Domain\Signals\Opportunities;

/**
 * What "a usable product profile" means, and the search queries derived from it.
 *
 * Queries are composed deterministically from the profile - never invented by a model -
 * so what is searched for is exactly what the organisation configured.
 */
class ProductProfileService
{
    private const TRIGGERS = [
        'announces expansion',
        'hiring',
        'implementing OR migrating OR "digital transformation"',
        '"request for proposal" OR RFP OR tender',
        'selects OR evaluating OR "looking for"',
        'compliance OR regulation OR audit',
    ];

    /** @return array{complete: bool, missing: array<int, string>} */
    public function completeness(?ProductProfile $profile): array
    {
        $missing = [];
        $has = fn ($v) => is_array($v) ? count(array_filter($v, fn ($x) => trim((string) $x) !== '')) > 0 : trim((string) $v) !== '';

        if (! $profile || ! $has($profile->product_name)) {
            $missing[] = 'product_name';
        }
        if (! $profile || ! $has($profile->description)) {
            $missing[] = 'description';
        }
        if (! $profile || (! $has($profile->problems_solved) && ! $has($profile->features))) {
            $missing[] = 'problems_solved_or_features';
        }
        if (! $profile || (! $has($profile->keywords) && ! $has($profile->target_industries))) {
            $missing[] = 'keywords_or_target_industries';
        }

        return ['complete' => $missing === [], 'missing' => $missing];
    }

    /** @return array<int, string> */
    public function queries(ProductProfile $profile): array
    {
        $topics = array_values(array_filter(array_map('trim', (array) $profile->keywords)));
        $industries = array_values(array_filter(array_map('trim', (array) $profile->target_industries)));
        $markets = array_values(array_filter(array_map('trim', (array) $profile->target_markets)));

        if ($topics === []) {
            $topics = array_map(fn ($i) => $i . ' companies', $industries);
        }

        $max = max(1, (int) config('signals.opportunities.max_queries', 6));
        $queries = [];
        $n = 0;
        while (count($queries) < $max && $n < $max * 4) {
            $topic = $topics[$n % count($topics)];
            $trigger = self::TRIGGERS[intdiv($n, count($topics)) % count(self::TRIGGERS)] ;
            $industry = $industries !== [] ? $industries[$n % count($industries)] : '';
            $market = $markets !== [] ? $markets[$n % count($markets)] : '';

            $query = trim(sprintf('"%s" %s %s %s', $topic, $industry, $market, $trigger));
            $queries[$query] = true;
            $n++;
        }

        return array_slice(array_keys($queries), 0, $max);
    }

    /** True when the local time falls on a day this profile's frequency runs. */
    public function runsOn(ProductProfile $profile, \Carbon\CarbonInterface $localNow): bool
    {
        return match ($profile->research_frequency) {
            'weekdays' => $localNow->isWeekday(),
            'weekly' => $localNow->isMonday(),
            default => true,
        };
    }

    public function scheduleTime(ProductProfile $profile): string
    {
        $time = (string) ($profile->schedule_time ?: config('signals.opportunities.default_schedule_time', '10:00'));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '10:00';
    }

    /** Local (schedule-timezone) moment of the next scheduled run, or null when research is off. */
    public function nextRun(ProductProfile $profile, ?\Carbon\CarbonInterface $now = null): ?\Carbon\CarbonInterface
    {
        if (! $profile->research_enabled) {
            return null;
        }

        $tz = (string) config('signals.timezone', 'Asia/Kolkata');
        $now = ($now ?? now())->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', $this->scheduleTime($profile)));

        for ($day = 0; $day < 8; $day++) {
            $candidate = $now->copy()->addDays($day)->setTime($h, $m, 0);
            if ($candidate->gt($now) && $this->runsOn($profile, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Should the scheduler start this organisation's run now? True once the configured
     * time has passed on a day the frequency allows, and only if no scheduled run has
     * been recorded for today. Checking "has passed" (not "is exactly now") means a
     * machine that was off at 10:00 catches up when it next ticks, and one tick a day
     * can never become two runs.
     */
    public function isDue(ProductProfile $profile, ?\Carbon\CarbonInterface $now = null): bool
    {
        if (! $profile->research_enabled) {
            return false;
        }

        $local = ($now ?? now())->copy()->setTimezone((string) config('signals.timezone', 'Asia/Kolkata'));

        if (! $this->runsOn($profile, $local) || $local->format('H:i') < $this->scheduleTime($profile)) {
            return false;
        }

        return ! ResearchRun::where('sub_institute_id', $profile->sub_institute_id)
            ->where('trigger', 'scheduled')
            ->whereDate('report_date', $local->toDateString())
            ->exists();
    }
}
