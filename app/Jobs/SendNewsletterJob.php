<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewsletterMail;

class SendNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $newsletterData;
    protected $recipients;

    /**
     * Create a new job instance.
     */
    public function __construct($newsletterData, $recipients)
    {
        $this->newsletterData = $newsletterData;
        $this->recipients = $recipients;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $subject = $this->newsletterData['subject_override'] ?? $this->extractSubjectFromBody($this->newsletterData['body']) ?? 'Newsletter';

        $mail = new NewsletterMail(
            $this->newsletterData['body'],
            $this->newsletterData['cta_link'],
            $this->newsletterData['cta_text'],
            $subject
        );

        // THE GATE. A queued job is the easiest place for a send to escape a
        // per-route fix - the route is gated, the job it dispatched is not.
        if (!\App\Support\MailGate::allowed()) {
            return;
        }

        Mail::to($this->recipients)->send($mail);
    }

    private function extractSubjectFromBody($body)
    {
        // Simple extraction: look for "Subject: " in the body
        if (preg_match('/Subject:\s*(.+?)\n/', $body, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}