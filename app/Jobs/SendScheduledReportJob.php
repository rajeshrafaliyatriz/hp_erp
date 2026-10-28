<?php

namespace App\Jobs;

use App\Domain\Reports\ReportQueryExecutionService;
use App\Domain\Reports\ScheduledReportMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Dispatched by `reports:send-scheduled` once per due, already-claimed `report_schedules` row — see that command's own docblock for the claim-before-dispatch reasoning. */
class SendScheduledReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $scheduleId)
    {
    }

    public function handle(ScheduledReportMailer $mailer, ReportQueryExecutionService $executor): void
    {
        $schedule = DB::table('report_schedules')->where('id', $this->scheduleId)->first();

        if (!$schedule || !$schedule->is_active) {
            return;
        }

        $report = DB::table('reports')->where('id', $schedule->report_id)->whereNull('deleted_at')->first();

        if (!$report) {
            Log::warning('scheduled report send skipped: report no longer exists', ['schedule_id' => $this->scheduleId]);

            return;
        }

        $outcome = $mailer->send($schedule, $report, $executor);

        if (!$outcome['ok']) {
            Log::warning('scheduled report send refused', ['schedule_id' => $this->scheduleId, 'code' => $outcome['code'], 'message' => $outcome['message']]);
        }
    }
}
