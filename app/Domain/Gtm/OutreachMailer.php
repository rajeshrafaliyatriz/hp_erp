<?php

namespace App\Domain\Gtm;

use App\Domain\Gtm\Outreach\SendFailure;
use App\Domain\Gtm\Outreach\Suppressions;
use App\Support\MailGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The only place GTM mail leaves the system, and every condition that must hold before it does.
 *
 * Order of checks, none skippable: the platform gate (MailGate, per organisation), the GTM master
 * switch, a reachable unsubscribe link, the recipient's own status and the suppression list, the daily cap,
 * sequence order and replies, and that the text being sent is byte-for-byte what was approved. Only then is
 * the message claimed (approved -> sending, atomic) and handed to the mailer.
 *
 * EVERY attempt is recorded (gtm_outreach_attempts), including refusals, so "what happened to this email" is
 * answerable from data. A failure is classified by SendFailure: a scheduled send retries transient and
 * rate-limited failures with bounded backoff and never retries a permanent one; a manual send simply
 * becomes `failed` and can be retried by a person. Each email carries a Message-ID we generate, which is
 * how a later reply or bounce is matched back to it.
 */
final class OutreachMailer
{
    public const BLOCKED_CONTACT = ['bounced', 'unsubscribed', 'do_not_contact'];

    /** @return array<string, mixed> What would stop a send right now, without sending anything. */
    public function readiness(int $tenant, bool $probeSmtp = false): array
    {
        $mailer = (string) config('mail.default');
        $host = (string) config("mail.mailers.{$mailer}.host", '');
        $sentToday = $this->sentToday($tenant);
        $cap = (int) config('gtm.outreach.daily_cap');
        $unsub = $this->unsubscribeProblem();
        $out = [
            'mailer' => $mailer, 'host' => $host !== '' ? $host : null,
            'from' => (string) config('mail.from.address'), 'from_name' => (string) config('mail.from.name'),
            'platform_gate_allows' => MailGate::allowedForTenant($tenant), 'platform_gate_reason' => MailGate::reasonForTenant($tenant),
            'gtm_sending_enabled' => (bool) config('gtm.outreach.sending_enabled'),
            'scheduler_enabled' => (bool) config('gtm.outreach.scheduler_enabled'),
            'unsubscribe_link_ok' => $unsub === null, 'unsubscribe_problem' => $unsub,
            'daily_cap' => $cap, 'sent_today' => $sentToday, 'require_second_approver' => (bool) config('gtm.outreach.require_second_approver'),
            'smtp' => null,
        ];
        $out['can_send'] = $out['platform_gate_allows'] && $out['gtm_sending_enabled'] && $out['unsubscribe_link_ok'] && $sentToday < $cap && ! in_array($mailer, ['log', 'array'], true);
        $out['blockers'] = array_values(array_filter([
            $out['platform_gate_allows'] ? null : $out['platform_gate_reason'],
            $out['gtm_sending_enabled'] ? null : 'GTM outreach sending is switched off (GTM_OUTREACH_SENDING_ENABLED).',
            $unsub,
            $sentToday < $cap ? null : "The daily limit of {$cap} emails has been reached.",
            in_array($mailer, ['log', 'array'], true) ? "The mail driver is '{$mailer}', which writes to a log instead of delivering." : null,
        ]));

        if ($probeSmtp) {
            // Connect and authenticate WITHOUT sending: proves the credentials and host, nothing more.
            $out['smtp'] = Cache::remember("gtm:smtp-probe:{$mailer}", 60, function () use ($mailer) {
                if ($mailer !== 'smtp') {
                    return ['ok' => false, 'error' => "The mail driver is '{$mailer}', not smtp."];
                }
                try {
                    Mail::mailer()->getSymfonyTransport()->start();

                    return ['ok' => true, 'error' => null];
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => $this->safe($e->getMessage())];
                }
            });
        }

        return $out;
    }

    /**
     * Send one APPROVED message. Returns the outcome; never throws for an expected refusal.
     *
     * `$scheduled` is true when the scheduler (not a person) is sending: it changes only what happens to a
     * message after a TRANSIENT failure (it goes back to `approved` to be retried, up to a bound).
     *
     * @return array{ok: bool, http: int, code: string, message: string, kind?: string, retry_at?: ?string}
     */
    public function send(GtmOutreachMessage $m, ?int $actorId, bool $scheduled = false): array
    {
        $tenant = (int) $m->sub_institute_id;
        $refuse = function (int $http, string $code, string $message) use ($m, $tenant, $actorId, $scheduled) {
            $this->attempt($m, 'refused', $code, $message, $scheduled, $actorId, false);

            return ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message];
        };

        if (! in_array($m->status, ['approved', 'failed'], true)) {
            return ['ok' => false, 'http' => 409, 'code' => 'not_approved', 'message' => 'Only an approved message can be sent.'];
        }
        if (! MailGate::allowedForTenant($tenant)) {
            return $refuse(403, 'mail_disabled', (string) MailGate::reasonForTenant($tenant));
        }
        if (! config('gtm.outreach.sending_enabled')) {
            return $refuse(403, 'sending_disabled', 'GTM outreach sending is switched off. Set GTM_OUTREACH_SENDING_ENABLED=true to enable it.');
        }
        if ($problem = $this->unsubscribeProblem()) {
            return $refuse(422, 'unsubscribe_unreachable', $problem);
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return $refuse(422, 'mailer_not_delivering', 'The mail driver does not deliver real email.');
        }

        $contact = GtmContact::where('sub_institute_id', $tenant)->find($m->contact_id);
        if (! $contact || trim((string) $contact->email) === '' || ! filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
            return $refuse(422, 'no_recipient', 'The contact has no valid email address.');
        }
        if (in_array($contact->status, self::BLOCKED_CONTACT, true)) {
            return $refuse(422, 'contact_blocked', "The contact is marked '{$contact->status}'. Nothing was sent.");
        }
        if ($block = app(Suppressions::class)->blocking($tenant, $contact->email)) {
            return $refuse(422, 'suppressed', "This address is on the suppression list ({$block['kind']} {$block['value']}, {$block['reason']}). Nothing was sent.");
        }
        if ($m->approved_hash === null || ! hash_equals($m->approved_hash, $m->contentHash())) {
            return $refuse(409, 'content_changed', 'The text differs from what was approved. Resubmit it for approval.');
        }
        if ($this->sentToday($tenant) >= (int) config('gtm.outreach.daily_cap')) {
            return $refuse(429, 'daily_cap', 'The daily sending limit has been reached. Try again tomorrow.');
        }
        if ($m->sequence_key) {
            $earlier = GtmOutreachMessage::where('sub_institute_id', $tenant)->where('sequence_key', $m->sequence_key)->where('step', '<', $m->step)
                ->whereNotIn('status', ['sent', 'cancelled'])->exists();
            if ($earlier) {
                return $refuse(409, 'sequence_order', 'An earlier step of this sequence has not been sent yet.');
            }
            $firstSent = GtmOutreachMessage::where('sub_institute_id', $tenant)->where('sequence_key', $m->sequence_key)->where('status', 'sent')->min('sent_at');
            if ($firstSent && GtmActivity::where('sub_institute_id', $tenant)->where('contact_id', $contact->id)->where('direction', 'inbound')->where('occurred_at', '>=', $firstSent)->exists()) {
                return $refuse(409, 'reply_recorded', 'The contact has replied since this sequence began, so it is stopped. Cancel the remaining steps.');
            }
        }

        // Atomic claim: only one caller can move approved/failed -> sending.
        $messageId = sprintf('gtm.%d.%d.%s@%s', $tenant, $m->id, bin2hex(random_bytes(6)), $this->domain());
        $claimed = DB::table('gtm_outreach_messages')->where('id', $m->id)->where('sub_institute_id', $tenant)->whereIn('status', ['approved', 'failed'])
            ->update(['status' => 'sending', 'to_email' => $contact->email, 'error' => null, 'provider_message_id' => $messageId, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return ['ok' => false, 'http' => 409, 'code' => 'already_claimed', 'message' => 'This message is already being sent.'];
        }

        $unsubscribeUrl = URL::temporarySignedRoute('gtm.unsubscribe', now()->addDays(365), ['contact' => $contact->id]);
        $text = rtrim($m->body)."\n\n--\nIf you would rather not receive emails from us, unsubscribe here: {$unsubscribeUrl}\n";

        try {
            Mail::raw($text, function ($message) use ($contact, $m, $unsubscribeUrl, $messageId) {
                $message->to($contact->email, $contact->full_name)->subject($m->subject);
                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->remove('Message-ID');
                $headers->addIdHeader('Message-ID', $messageId);
                $headers->addTextHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        } catch (\Throwable $e) {
            return $this->failed($m, $e, $scheduled, $actorId);
        }

        DB::transaction(function () use ($m, $contact, $tenant, $actorId, $text) {
            DB::table('gtm_outreach_messages')->where('id', $m->id)->update(['status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null, 'updated_at' => now()]);
            GtmActivity::create([
                'sub_institute_id' => $tenant, 'account_id' => $m->account_id, 'contact_id' => $contact->id, 'deal_id' => $m->deal_id,
                'type' => 'email', 'direction' => 'outbound', 'subject' => $m->subject, 'body' => $text, 'occurred_at' => now(), 'user_id' => $actorId,
                'metadata' => ['outreach_message_id' => $m->id, 'sequence_key' => $m->sequence_key, 'step' => $m->step],
            ]);
        });
        $this->attempt($m, 'sent', 'sent', 'Accepted by the mail server.', $scheduled, $actorId, true);
        GtmAudit::record('gtm.outreach.sent', $tenant, 'gtm_outreach_messages', $m->id, $actorId, ['contact_id' => $contact->id, 'step' => $m->step, 'scheduled' => $scheduled]);

        return ['ok' => true, 'http' => 200, 'code' => 'sent', 'message' => 'Sent.'];
    }

    /**
     * Record and apply the consequence of a failed send. Transient and rate-limited failures of a SCHEDULED
     * send go back to `approved` with a retry time (bounded by SendFailure::backoff); everything else is `failed`.
     *
     * @return array{ok: false, http: int, code: string, message: string, kind: string, retry_at: ?string}
     */
    private function failed(GtmOutreachMessage $m, \Throwable $e, bool $scheduled, ?int $actorId): array
    {
        $tenant = (int) $m->sub_institute_id;
        $error = $this->safe($e->getMessage());
        $c = SendFailure::classify($e->getMessage());
        $attempts = (int) DB::table('gtm_outreach_messages')->where('id', $m->id)->value('attempts') + 1;
        $retryAt = null;

        if ($scheduled && $c['kind'] === 'transient') {
            $wait = SendFailure::backoff($attempts);
            $retryAt = $wait === null ? null : now()->addMinutes($wait);
        } elseif ($scheduled && $c['kind'] === 'rate_limited' && $attempts < 8) {
            $retryAt = now()->addMinutes((int) $c['retry_after_minutes']);
        }

        DB::table('gtm_outreach_messages')->where('id', $m->id)->update($retryAt
            ? ['status' => 'approved', 'error' => $error, 'next_attempt_at' => $retryAt, 'attempts' => $attempts, 'last_attempt_at' => now(), 'updated_at' => now()]
            : ['status' => 'failed', 'error' => $error, 'next_attempt_at' => null, 'attempts' => $attempts, 'last_attempt_at' => now(), 'updated_at' => now()]);
        $this->attempt($m, $retryAt ? 'retry' : 'failed', $c['kind'].($c['code'] ? ":{$c['code']}" : ''), $error, $scheduled, $actorId, false, $attempts);
        GtmAudit::record('gtm.outreach.send_failed', $tenant, 'gtm_outreach_messages', $m->id, $actorId, ['error' => $error, 'kind' => $c['kind'], 'will_retry' => $retryAt !== null, 'attempt' => $attempts]);

        $http = match ($c['kind']) { 'rate_limited' => 429, 'auth' => 502, default => 502 };

        return ['ok' => false, 'http' => $http, 'code' => 'send_failed', 'message' => "The email could not be sent: {$error}", 'kind' => $c['kind'], 'retry_at' => $retryAt?->toDateTimeString()];
    }

    private function attempt(GtmOutreachMessage $m, string $outcome, string $code, string $detail, bool $scheduled, ?int $actorId, bool $counts, ?int $attemptNo = null): void
    {
        if ($counts) {
            DB::table('gtm_outreach_messages')->where('id', $m->id)->increment('attempts', 1, ['last_attempt_at' => now()]);
        }
        $n = $attemptNo ?? (int) DB::table('gtm_outreach_messages')->where('id', $m->id)->value('attempts');
        DB::table('gtm_outreach_attempts')->insert([
            'sub_institute_id' => (int) $m->sub_institute_id, 'message_id' => $m->id, 'attempt' => min(255, max(1, $n)), 'outcome' => $outcome,
            'code' => mb_substr($code, 0, 40), 'detail' => mb_substr($detail, 0, 400), 'scheduled' => $scheduled, 'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }

    private function sentToday(int $tenant): int
    {
        return GtmOutreachMessage::where('sub_institute_id', $tenant)->where('status', 'sent')->where('sent_at', '>=', now()->startOfDay())->count();
    }

    /** Why the unsubscribe link in an email would not work for a real recipient, or null. */
    public function unsubscribeProblem(): ?string
    {
        if (config('gtm.outreach.allow_local_unsubscribe')) {
            return null;
        }
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.test')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));

        return $local
            ? "Every email carries an unsubscribe link built from APP_URL ({$host}), which a recipient cannot reach. Set APP_URL to the public API address before sending to real contacts."
            : null;
    }

    /** The domain used in generated Message-IDs: the sender's own, so replies thread back to us. */
    private function domain(): string
    {
        $from = (string) config('mail.from.address');

        return str_contains($from, '@') ? substr($from, strrpos($from, '@') + 1) : 'gtm.local';
    }

    /** Never put credentials or long provider dumps into a stored error. */
    private function safe(string $message): string
    {
        $message = preg_replace('/(password|passwd|pwd|token|secret)\s*[=:]\s*\S+/i', '$1=[redacted]', $message) ?? $message;
        $password = (string) config('mail.mailers.smtp.password');
        if ($password !== '') {
            $message = str_replace($password, '[redacted]', $message);
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 400);
    }
}
