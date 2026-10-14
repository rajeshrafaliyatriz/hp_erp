<?php

namespace App\Domain\Reports;

use App\Mail\ScheduledReportMail;
use App\Support\MailGate;
use App\Support\MailSender;
use Illuminate\Support\Facades\Log;

/**
 * "Send this report by email right now" — the Share dialog's email option,
 * distinct from ScheduledReportMailer (which sends on a recurring cadence to
 * an admin-configured `report_schedules` row). Same gate checks, same
 * CSV-via-ReportCsvExporter shape, same ScheduledReportMail mailable (its own
 * content is just title/description/rowCount/csv — nothing schedule-specific
 * about it), but recipients come straight from the request, and nothing is
 * persisted — exactly as ad hoc as the existing "Export CSV" button, just
 * mailed instead of downloaded.
 */
final class ReportAdHocMailer
{
    /**
     * @param  object  $report  A full `reports` row the caller has already confirmed is viewable.
     * @param  string[]  $recipients  Already-validated email addresses.
     * @return array{ok: bool, code: string, message: string}
     */
    public function send(object $report, array $recipients, ReportQueryExecutionService $executor): array
    {
        $tenant = (int) $report->sub_institute_id;
        $refuse = fn (string $code, string $message) => ['ok' => false, 'code' => $code, 'message' => $message];

        if (!MailGate::allowedForTenant($tenant)) {
            return $refuse('mail_disabled', (string) MailGate::reasonForTenant($tenant));
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return $refuse('mailer_not_delivering', 'The mail driver does not deliver real email.');
        }

        try {
            $definition = json_decode($report->definition, true);
            $result = $executor->execute($report->report_type, $report->primary_module, $definition, $tenant);
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
            Log::error('ad hoc report email failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return $refuse('send_failed', 'This report could not be emailed.');
        }

        return ['ok' => true, 'code' => 'sent', 'message' => 'Sent.'];
    }
}
