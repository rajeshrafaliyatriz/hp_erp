<?php

namespace App\Services\Crm;

use App\Support\MailGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * The only place a CRM Email leaves the system, and every condition that
 * must hold before it does - modeled directly on
 * App\Domain\Gtm\OutreachMailer (read that class first; this one mirrors its
 * shape deliberately rather than inventing a second convention for the same
 * risk: a real email to a real external Lead/Contact).
 *
 * Order of checks, none skippable: the CRM-specific master switch
 * (config('crm.email_sending_enabled'), OFF by default - see config/crm.php),
 * the platform gate (MailGate, per organisation), a reachable unsubscribe
 * link, the recipient's own opt-out flag, and the daily cap. Only then is the
 * row claimed (Queued -> Sending, atomic) and handed to the mailer. The
 * outcome is recorded either way: a send updates the row to Sent/sent_at; a
 * failure keeps status Failed + failure_reason.
 *
 * CrmEmailController::compose() is the only caller, and only when
 * config('crm.email_sending_enabled') is true - but every check below is
 * re-asserted in THIS class too (not just trusted from the caller), because
 * this is the one file the "no unconditional sending" requirement has to
 * hold in even if a future caller forgets its own gate.
 */
final class CrmOutreachMailer
{
    /**
     * Send one Queued crm_emails row. Returns the outcome; never throws for
     * an expected refusal.
     *
     * @param  object  $emailRow  A full `crm_emails` row (DB::table()->first()) - status must be 'Queued'.
     * @param  string  $linkedType  UPPERCASE singular - LEAD/CONTACT/ORGANIZATION/OPPORTUNITY/TICKET (same convention as HasCrmEmailLinks/HasCrmDocuments).
     * @param  int  $linkedId  The linked record's id - used for the opt-out check and the unsubscribe link.
     * @return array{ok: bool, http: int, code: string, message: string}
     */
    public function send(object $emailRow, ?int $actorId, string $linkedType, int $linkedId): array
    {
        $tenant = (int) $emailRow->sub_institute_id;
        $refuse = fn (int $http, string $code, string $message) => ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message];

        if (! config('crm.email_sending_enabled')) {
            return $refuse(403, 'sending_disabled', 'CRM email sending is switched off. Set CRM_EMAIL_SENDING_ENABLED=true to enable it.');
        }
        if (! MailGate::allowedForTenant($tenant)) {
            return $refuse(403, 'mail_disabled', (string) MailGate::reasonForTenant($tenant));
        }
        if ($problem = $this->unsubscribeProblem()) {
            return $refuse(422, 'unsubscribe_unreachable', $problem);
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return $refuse(422, 'mailer_not_delivering', 'The mail driver does not deliver real email.');
        }
        if (trim((string) $emailRow->to_address) === '' || ! filter_var($emailRow->to_address, FILTER_VALIDATE_EMAIL)) {
            return $refuse(422, 'no_recipient', 'This email has no valid recipient address.');
        }
        if ($blocked = $this->recipientBlocked($linkedType, $linkedId)) {
            return $refuse(422, 'recipient_blocked', $blocked);
        }
        if ($this->sentToday($tenant) >= (int) config('crm.email_daily_cap')) {
            return $refuse(429, 'daily_cap', 'The daily sending limit has been reached. Try again tomorrow.');
        }

        // Atomic claim: only one caller can move Queued -> Sending, same
        // pattern OutreachMailer::send() uses for approved -> sending.
        $claimed = DB::table('crm_emails')->where('id', $emailRow->id)->where('sub_institute_id', $tenant)
            ->where('status', 'Queued')
            ->update(['status' => 'Sending', 'updated_at' => now()]);

        if ($claimed !== 1) {
            return $refuse(409, 'already_claimed', 'This email is already being sent or was already sent.');
        }

        $unsubscribeUrl = URL::temporarySignedRoute('crm.email.unsubscribe', now()->addDays(365), [
            'type' => $linkedType,
            'id' => $linkedId,
        ]);
        $body = rtrim((string) $emailRow->body)
            ."\n\n--\nIf you would rather not receive emails from us, unsubscribe here: {$unsubscribeUrl}\n";

        try {
            Mail::raw($body, function ($message) use ($emailRow, $unsubscribeUrl) {
                $message->to($emailRow->to_address)->subject($emailRow->subject);
                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        } catch (\Throwable $e) {
            $error = $this->safe($e->getMessage());
            DB::table('crm_emails')->where('id', $emailRow->id)->update([
                'status' => 'Failed', 'failure_reason' => $error, 'updated_at' => now(),
            ]);

            return $refuse(502, 'send_failed', "The email could not be sent: {$error}");
        }

        DB::table('crm_emails')->where('id', $emailRow->id)->update([
            'status' => 'Sent', 'sent_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true, 'http' => 200, 'code' => 'sent', 'message' => 'Sent.'];
    }

    private function sentToday(int $tenant): int
    {
        return (int) DB::table('crm_emails')
            ->where('sub_institute_id', $tenant)
            ->where('status', 'Sent')
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }

    /** Why the unsubscribe link in an email would not work for a real recipient, or null. Mirrors OutreachMailer::unsubscribeProblem() exactly, with the CRM-specific env var. */
    public function unsubscribeProblem(): ?string
    {
        if (config('crm.email_allow_local_unsubscribe')) {
            return null;
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.test')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));

        return $local
            ? "Every email carries an unsubscribe link built from APP_URL ({$host}), which a recipient cannot reach. Set APP_URL to the public API address before sending to real contacts."
            : null;
    }

    /**
     * Whether the recipient behind this link has opted out of email -
     * `email_opt_out` already exists on crm_leads/crm_contacts/
     * crm_organizations (migrated well before this phase). An Opportunity/
     * Ticket carries no such column of its own, so the check follows its
     * own parent FK(s) to whichever parent actually has the flag - an
     * Opportunity has only organization_id (crm_opportunities has no
     * contact_id column), a Ticket has both contact_id and organization_id
     * (checked in that priority order) - same resolution
     * CrmEmailController::unsubscribe() uses to decide what to flip when the
     * recipient clicks that link.
     */
    private function recipientBlocked(string $linkedType, int $linkedId): ?string
    {
        $target = match (strtoupper($linkedType)) {
            'LEAD' => ['table' => 'crm_leads', 'id' => $linkedId],
            'CONTACT' => ['table' => 'crm_contacts', 'id' => $linkedId],
            'ORGANIZATION' => ['table' => 'crm_organizations', 'id' => $linkedId],
            'OPPORTUNITY' => $this->parentOptOutTarget('crm_opportunities', $linkedId, ['organization_id' => 'crm_organizations']),
            'TICKET' => $this->parentOptOutTarget('crm_tickets', $linkedId, ['contact_id' => 'crm_contacts', 'organization_id' => 'crm_organizations']),
            default => null,
        };

        if (! $target) {
            return null;
        }

        $optedOut = DB::table($target['table'])->where('id', $target['id'])->value('email_opt_out');

        return $optedOut ? 'This recipient has opted out of emails. Nothing was sent.' : null;
    }

    /** @param array<string, string> $parentColumns own column => parent table, checked in priority order. */
    private function parentOptOutTarget(string $table, int $id, array $parentColumns): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        if (! $row) {
            return null;
        }

        foreach ($parentColumns as $column => $parentTable) {
            if (! empty($row->{$column})) {
                return ['table' => $parentTable, 'id' => (int) $row->{$column}];
            }
        }

        return null;
    }

    /** Never put credentials or long provider dumps into a stored error. Identical to OutreachMailer::safe(). */
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
