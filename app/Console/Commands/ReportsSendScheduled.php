<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledReportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled/emailed report runs (Docs/reports-engine/PLAN.md, Phase 4).
 *
 * Runs every 15 minutes (see routes/console.php) and asks each active
 * `report_schedules` row "is now your moment, and have you already gone
 * out today?" — a daily/weekly/monthly schedule only ever fires once per
 * calendar day, so "already sent today" (via `last_sent_at`) is the whole
 * idempotency story, no separate "is_sending" flag needed.
 *
 * THE CLAIM HAPPENS HERE, NOT IN THE JOB
 *
 * `last_sent_at` is updated the moment a schedule is found due — BEFORE
 * `SendScheduledReportJob` is even dispatched, with a `WHERE` guard
 * (`whereNull('last_sent_at')->orWhere('last_sent_at', '<', today start)`)
 * so the update affects exactly one row if two command ticks somehow
 * overlap (`withoutOverlapping()` in routes/console.php already prevents
 * this in the normal case; the guard is defense in depth, same posture
 * App\Services\Crm\CrmOutreachMailer's own Queued->Sending claim takes).
 * If the job itself is queued and takes a while, or even fails outright,
 * the claim still holds for the rest of today — a failed send is logged
 * and left for tomorrow's run rather than retried the same day.
 */
class ReportsSendScheduled extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Dispatch every due, not-yet-sent-today report schedule';

    public function handle(): int
    {
        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $dispatched = 0;

        $schedules = DB::table('report_schedules')->where('is_active', true)->get();

        foreach ($schedules as $schedule) {
            if (!$this->isDue($schedule, $now)) {
                continue;
            }

            $claimed = DB::table('report_schedules')
                ->where('id', $schedule->id)
                ->where(function ($query) use ($todayStart) {
                    $query->whereNull('last_sent_at')->orWhere('last_sent_at', '<', $todayStart);
                })
                ->update(['last_sent_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            SendScheduledReportJob::dispatch($schedule->id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} scheduled report send(s).");

        return self::SUCCESS;
    }

    /** Due = the right day for this frequency, and within the first 15 minutes past its configured time (matching the command's own run cadence). */
    private function isDue(object $schedule, Carbon $now): bool
    {
        if ($schedule->frequency === 'weekly' && $schedule->day_of_week !== null && (int) $now->dayOfWeek !== (int) $schedule->day_of_week) {
            return false;
        }

        if ($schedule->frequency === 'monthly' && $schedule->day_of_month !== null) {
            $targetDay = min((int) $schedule->day_of_month, $now->daysInMonth);

            if ((int) $now->day !== $targetDay) {
                return false;
            }
        }

        $scheduledToday = $now->copy()->setTimeFromTimeString((string) $schedule->time_of_day);

        return $now->gte($scheduledToday) && $now->lt($scheduledToday->copy()->addMinutes(15));
    }
}
