<?php

namespace App\Domain\Signals\Outreach;

use App\Domain\Signals\Opportunities\SignalContact;

/**
 * What can be done with a person, and with a company, in the Outreach tab of Market Signals. Pure: one row of facts in, one word out, so every rule is proved without a database.
 *
 *   ready          a usable email AND a usable phone
 *   email_only     a usable email, no usable phone
 *   phone_only     a usable phone, no usable email
 *   needs_details  nothing to write to yet (the research found no email or phone), or nothing at all
 *   blocked        they opted out or were suppressed; or they have details but every one is ruled out (no email asked for + do not call)
 *
 * "Usable" = the value passes the same checks as everywhere else in Signals (SignalContact::validEmail / validPhone) AND the person has not ruled that channel out
 * (`email_opt_out` for email, `do_not_call` for phone). A person who opted out or was suppressed has no usable channel at all. Details found on the public web are never
 * "verified": reach says what may be tried, not that it works.
 */
final class OutreachReach
{
    public const READY = 'ready';
    public const EMAIL_ONLY = 'email_only';
    public const PHONE_ONLY = 'phone_only';
    public const NEEDS_DETAILS = 'needs_details';
    public const BLOCKED = 'blocked';

    /** @var list<string> */
    public const REACHABLE = [self::READY, self::EMAIL_ONLY, self::PHONE_ONLY];

    /** @var list<string> */
    public const CONSENT = ['unknown', 'opted_in', 'opted_out', 'suppressed'];

    /** The consent answers that rule a person out completely. @var list<string> */
    public const CONSENT_BLOCKING = ['opted_out', 'suppressed'];

    /** @param array<string, mixed>|object $row a g2g_signal_contacts row */
    public static function forPerson(array|object $row): string
    {
        $c = (array) $row;
        $blocked = in_array((string) ($c['consent_status'] ?? 'unknown'), self::CONSENT_BLOCKING, true) || ! empty($c['suppressed_at']);
        $hasEmail = self::hasEmail($c);
        $hasPhone = self::hasPhone($c);
        $emailUsable = $hasEmail && ! (bool) ($c['email_opt_out'] ?? false);
        $phoneUsable = $hasPhone && ! (bool) ($c['do_not_call'] ?? false);

        if ($blocked) {
            return self::BLOCKED;
        }
        if ($emailUsable && $phoneUsable) {
            return self::READY;
        }
        if ($emailUsable) {
            return self::EMAIL_ONLY;
        }
        if ($phoneUsable) {
            return self::PHONE_ONLY;
        }

        return ($hasEmail || $hasPhone) ? self::BLOCKED : self::NEEDS_DETAILS;
    }

    /**
     * A company's reach is the best reach among its people. With none reachable it is `blocked` only when EVERY person is blocked (nobody left to try); a mix of blocked
     * people and people without details still needs details.
     *
     * @param  list<string>  $personReaches
     */
    public static function forCompany(array $personReaches): string
    {
        foreach ([self::READY, self::EMAIL_ONLY, self::PHONE_ONLY] as $best) {
            if (in_array($best, $personReaches, true)) {
                return $best;
            }
        }

        return $personReaches !== [] && count(array_unique($personReaches)) === 1 && $personReaches[0] === self::BLOCKED ? self::BLOCKED : self::NEEDS_DETAILS;
    }

    public static function isReachable(string $reach): bool
    {
        return in_array($reach, self::REACHABLE, true);
    }

    /** The words for a refusal to write to someone blocked, the same ones the screen shows. @param array<string, mixed>|object $row */
    public static function whyBlocked(array|object $row): string
    {
        $c = (array) $row;
        $consent = (string) ($c['consent_status'] ?? 'unknown');
        if ($consent === 'opted_out') {
            return 'They opted out.';
        }
        if ($consent === 'suppressed' || ! empty($c['suppressed_at'])) {
            return ! empty($c['suppressed_reason']) ? "Suppressed: {$c['suppressed_reason']}." : 'They were suppressed.';
        }
        $noEmail = (bool) ($c['email_opt_out'] ?? false);
        $noCalls = (bool) ($c['do_not_call'] ?? false);
        if ($noEmail && $noCalls) {
            return 'They asked for no email and no calls.';
        }
        if ($noEmail) {
            return 'They asked for no email.';
        }

        return $noCalls ? 'They asked for no calls.' : 'No message can be written to them.';
    }

    /** @param array<string, mixed> $c */
    public static function hasEmail(array $c): bool
    {
        return SignalContact::validEmail($c['email'] ?? null) || SignalContact::validEmail($c['secondary_email'] ?? null);
    }

    /** @param array<string, mixed> $c */
    public static function hasPhone(array $c): bool
    {
        return SignalContact::validPhone($c['phone'] ?? null) || SignalContact::validPhone($c['mobile'] ?? null);
    }

    /** The address a message to this person goes to: the main email, else the second one. @param array<string, mixed>|object $row */
    public static function emailOf(array|object $row): ?string
    {
        $c = (array) $row;
        foreach (['email', 'secondary_email'] as $field) {
            if (SignalContact::validEmail($c[$field] ?? null)) {
                return strtolower(trim((string) $c[$field]));
            }
        }

        return null;
    }
}
