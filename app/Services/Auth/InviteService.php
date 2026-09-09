<?php

namespace App\Services\Auth;

use App\Support\MailGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GETTING A CREDENTIAL TO A NEW PERSON — the part that was missing entirely.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACES
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `EmployeeFactory::issueInvite()` did this:
 *
 *     DB::table('password_reset_tokens')->insert([...]);
 *     return ['sent' => true, 'error' => null];
 *
 * There is no mail call in it. None. It wrote a token nothing read, reported
 * success, and the screen told the new employee *"An invite was sent to you"*
 * and *"They will be emailed a link to set their own password."*
 *
 * Their password is `bin2hex(random_bytes(12))`, hashed and discarded. So the
 * combined effect of those two facts is that **every employee ever created
 * through Employee Directory has been unable to log in**, and been told
 * otherwise.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THE LINK IS RETURNED, NOT JUST EMAILED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Because email does not work here, and pretending otherwise is what got us
 * into this. Measured: `G2G_NOTIFY_EMAIL` is absent from `.env`, one tenant is
 * on the per-tenant allowlist, and `smtp_details` holds ONE row for twelve live
 * organisations.
 *
 * An invite that can only be delivered by email is an invite that does not work
 * for eleven of twelve customers. So this returns the link as well, for the
 * administrator to hand over however they actually communicate with their
 * people — and the caller shows it.
 *
 *     'email'  it was sent, and the link is NOT returned
 *     'link'   nothing was sent; here is the link, give it to them yourself
 *     'failed' nothing was sent and there is no link — with the reason
 *
 * Three states, because the difference between them is exactly what the old
 * boolean destroyed.
 *
 * ── THE LINK IS NOT RETURNED WHEN IT WAS EMAILED ────────────────────────────
 *
 * Deliberate. A one-time credential that has been delivered to its owner should
 * not also sit in an administrator's browser tab, in a screenshot, or in a
 * server log. If the email went, the email is the delivery.
 */
class InviteService
{
    /** How long an invite link stays usable. Mirrors ForgotPasswordController. */
    public const TOKEN_HOURS = 24;

    /**
     * Mint a set-password link for somebody, and deliver it if we can.
     *
     * NEVER THROWS. An employee who exists but was not invited is a recoverable
     * situation an administrator can fix from the People screen; an exception
     * thrown after their record was committed is not.
     *
     * @return array{delivered:'email'|'link'|'failed', link:?string, error:?string, expires_hours:int}
     */
    public function issue(?string $email, ?int $tenantId, string $purpose = 'invite'): array
    {
        $email = trim((string) $email);

        if ($email === '') {
            return $this->failed('This person has no email address, so there is nothing to send a link to. Add one first.');
        }

        $base = rtrim((string) config('app.frontend_url'), '/');

        if ($base === '') {
            return $this->failed('No application address is configured (FRONTEND_URL), so a set-password link cannot be built.');
        }

        try {
            $token = Str::random(64);

            /*
             * One live token per address. Re-inviting invalidates the previous
             * link rather than leaving two valid ways in - the table's primary
             * key is `email`, so this is also what keeps the insert legal.
             */
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => $token,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return $this->failed('The invite could not be created: ' . $e->getMessage());
        }

        $link = $base . '/set-password/' . $token . '?email=' . rawurlencode($email);

        if (!MailGate::allowedForTenant($tenantId)) {
            // Not a failure. The link exists and works; it just has to travel by
            // hand. Saying so plainly is the whole point of this class.
            return [
                'delivered' => 'link',
                'link' => $link,
                'error' => null,
                'expires_hours' => self::TOKEN_HOURS,
            ];
        }

        try {
            $this->mail($email, $link, $purpose);
        } catch (\Throwable $e) {
            /*
             * Mail was permitted and still did not go. The link is handed back
             * rather than swallowed, so the administrator is not stuck: the
             * person can still be let in while somebody looks at the mail
             * configuration.
             */
            Log::warning('Invite email failed; falling back to a copyable link.', [
                'tenant' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [
                'delivered' => 'link',
                'link' => $link,
                'error' => 'The email could not be sent (' . $e->getMessage() . '), so use the link instead.',
                'expires_hours' => self::TOKEN_HOURS,
            ];
        }

        return [
            'delivered' => 'email',
            // Withheld on purpose - see the class note.
            'link' => null,
            'error' => null,
            'expires_hours' => self::TOKEN_HOURS,
        ];
    }

    /**
     * Is this token good, and whose is it?
     *
     * @return array{valid:bool, email:?string, reason:?string}
     */
    public function check(string $token): array
    {
        $row = DB::table('password_reset_tokens')->where('token', $token)->first();

        if (!$row) {
            // The same answer for "never existed" and "already used", so a
            // caller cannot probe for which tokens were real.
            return ['valid' => false, 'email' => null, 'reason' => 'This link is not valid. Ask for a new one.'];
        }

        if ($row->created_at !== null
            && \Carbon\Carbon::parse($row->created_at)->addHours(self::TOKEN_HOURS)->isPast()) {

            DB::table('password_reset_tokens')->where('token', $token)->delete();

            return ['valid' => false, 'email' => null, 'reason' => 'This link has expired. Ask for a new one.'];
        }

        return ['valid' => true, 'email' => $row->email, 'reason' => null];
    }

    /** Spend a token. Returns the email it belonged to, or null. */
    public function consume(string $token): ?string
    {
        $check = $this->check($token);

        if (!$check['valid']) {
            return null;
        }

        DB::table('password_reset_tokens')->where('token', $token)->delete();

        return $check['email'];
    }

    private function failed(string $reason): array
    {
        return ['delivered' => 'failed', 'link' => null, 'error' => $reason, 'expires_hours' => self::TOKEN_HOURS];
    }

    /**
     * The message itself.
     *
     * `Mail::raw` rather than a Mailable, matching every other send in this
     * codebase - there are exactly two Mailables and both are candidate-facing.
     * A template is worth adding when there is a second account email to share
     * it with; there is not yet.
     */
    private function mail(string $email, string $link, string $purpose): void
    {
        $subject = $purpose === 'reset'
            ? 'Reset your password'
            : 'Set your password';

        $opening = $purpose === 'reset'
            ? 'Somebody asked to reset the password for this account.'
            : 'An account has been created for you.';

        \Illuminate\Support\Facades\Mail::raw(
            $opening . "\n\n"
            . "Use this link to set a password:\n"
            . $link . "\n\n"
            . 'The link works once and expires in ' . self::TOKEN_HOURS . " hours.\n\n"
            . "If you were not expecting this, you can ignore it - nothing changes until the link is used.",
            function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            }
        );
    }
}
