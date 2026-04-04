<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewsletterMail;
use App\Jobs\SendNewsletterJob;
use Carbon\Carbon;

class NewsletterController extends Controller
{
    public function sendNewsletter(Request $request)
    {
        $data = $request->all();

        $recipients = $data['test_mode'] ? $data['test_emails'] : $data['emails'];

        if (empty($recipients)) {
            return response()->json(['error' => 'No recipients specified'], 400);
        }

        $subject = $data['subject_override'] ?? $this->extractSubjectFromBody($data['body']) ?? 'Newsletter';

        if ($data['send_immediately']) {
            // Send immediately
            Mail::to($recipients)->send(new NewsletterMail($data['body'], $data['cta_link'], $data['cta_text'], $subject));
            return response()->json(['message' => 'Newsletter sent immediately']);
        } elseif ($data['scheduled_time']) {
            // Schedule for later
            $scheduledTime = Carbon::parse($data['scheduled_time']);
            SendNewsletterJob::dispatch($data, $recipients)->delay($scheduledTime);
            return response()->json(['message' => 'Newsletter scheduled']);
        } else {
            return response()->json(['error' => 'Neither send_immediately nor scheduled_time specified'], 400);
        }
    }

    private function extractSubjectFromBody($body)
    {
        // Extract subject from body if present
        if (preg_match('/Subject:\s*(.+?)\n/', $body, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}