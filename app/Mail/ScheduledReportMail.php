<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A scheduled report delivery (Docs/reports-engine/PLAN.md, Phase 4) — the report's own CSV export, generated fresh at send time, same shape as the interactive "Export CSV" button (App\Domain\Reports\ReportCsvExporter). */
class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reportTitle,
        public readonly ?string $reportDescription,
        public readonly int $rowCount,
        public readonly string $csv,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Scheduled report: {$this->reportTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.scheduled_report');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->reportTitle) . '.csv';

        return [
            Attachment::fromData(fn () => $this->csv, $filename)->withMime('text/csv'),
        ];
    }
}
