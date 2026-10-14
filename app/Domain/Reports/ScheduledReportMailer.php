<?php

namespace App\Domain\Reports;

use App\Mail\ScheduledReportMail;
use App\Support\MailGate;
use App\Support\MailSender;
use Illuminate\Support\Facades\Log;

/**
 * The only place a scheduled report's email actually leaves the system —
 * modeled on App\Services\Crm\CrmOutreachMailer's own shape (gate checks,
 * none skippable, re-asserted here rather than only trusted from the
 * caller), simplified for this feature's own risk profile: recipients are
 * admin-configured internal/trusted addresses on a `report_schedules` row,
 * not external marketing contacts, so there is no unsubscribe link, opt-out
 * check, or daily cap to re-derive — only "is outbound mail allowed for
 * this tenant at all" and "does the mail driver actually deliver."
 *
 * Deliberately does NOT touch `report_schedules.last_sent_at` — that is the
 * scheduled command's own atomic claim (set BEFORE dispatching the job, so
 * a queued send still can't double-fire if the command's next 15-minute
 * tick runs before the job finishes), not something this class re-derives.
 * A failed send here is not retried later the same day; it is logged and
 * left for the next day's scheduled run rather than retried within the
 * same window.
 */
final class ScheduledReportMailer
{
    /**
     * @param  object  $schedule  A full `report_schedules` row.
     * @param  object  $report  The `reports` row it belongs to.
     * @return array{ok: bool, code: string, message: string}
     */
    public function send(object $schedule, object $report, ReportQueryExecutionService $executor): array
    {
        $tenant = (int) $schedule->sub_institute_id;
        $refuse = fn (string $code, string $message) => ['ok' => false, 'code' => $code, 'message' => $message];

        if (!MailGate::allowedForTenant($tenant)) {
            return $refuse('mail_disabled', (string) MailGate::reasonForTenant($tenant));
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return $refuse('mailer_not_delivering', 'The mail driver does not deliver real email.');
        }

        $recipients = array_values(array_filter(
            array_map('trim', json_decode($schedule->recipients, true) ?? []),
            fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL),
        ));

        if ($recipients === []) {
            return $refuse('no_recipients', 'This schedule has no valid recipient addresses.');
        }

        try {
            $definition = json_decode($report->definition, true);
            // forceFresh: a scheduled send that handed out a stale cached
            // result (Phase 4's own 180s summary/pivot/chart cache) would
            // be a silent correctness bug nobody could see from the inbox.
            $result = $executor->execute($report->report_type, $report->primary_module, $definition, $tenant, forceFresh: true);
            $csv = ReportCsvExporter::toCsvString($report->report_type, $result, $definition);
            $rowCount = match (true) {
                isset($result['total']) => (int) $result['total'],
                isset($result['groups']) => count($result['groups']),
                isset($result['row_labels']) => count($result['row_labels']),
                isset($result['labels']) => count($result['labels']),
                default => 0,
            };

            $mailable = new ScheduledReportMail($report->title, $report->description, $rowCount, $csv);
            MailSender::mailer($tenant, 'report_builder')->to($recipients)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('scheduled report send failed', [
                'schedule_id' => $schedule->id,
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return $refuse('send_failed', 'The scheduled report could not be sent.');
        }

        return ['ok' => true, 'code' => 'sent', 'message' => 'Sent.'];
    }
}
