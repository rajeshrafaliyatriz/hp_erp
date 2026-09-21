<?php

namespace App\Services\Account;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TWO-STEP VERIFICATION WITH AN AUTHENTICATOR APP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS HAND-ROLLED AND NOT A PACKAGE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `pragmarx/google2fa` is the obvious choice and was the plan. Three things
 * argued against it here:
 *
 *   NO DEPLOY PIPELINE. Live is updated by `git pull`. A new dependency means a
 *   `composer install` somebody has to remember, and the failure mode if they do
 *   not is a fatal error on the sign-in path - the worst place in the product to
 *   half-deploy. Nothing else in this codebase needs that step.
 *
 *   THE PRECEDENT. `DeviceLabel` is hand-rolled for the same reason, and says so.
 *
 *   IT IS VERIFIABLE. TOTP is RFC 6238 and the RFC publishes TEST VECTORS.
 *   `prove-two-factor.php` checks this implementation against the official
 *   SHA-1 vectors from Appendix B. That is a stronger guarantee than trusting a
 *   package I cannot audit in one sitting - a wrong answer here accepts codes it
 *   should refuse.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IS DELIBERATELY NOT HERE: A QR CODE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * There is no QR encoder in this repository, front or back, and writing one means
 * Reed-Solomon error correction - a few hundred lines of security-adjacent code
 * with no published vectors to check it against. So enrolment offers the two
 * paths that need no encoder and that every authenticator app supports:
 *
 *   the `otpauth://` URI, which on a phone opens the app and enrols in one tap
 *   the secret itself, grouped in fours, for typing in on a desktop
 *
 * A scannable QR is a genuine convenience and is one dependency away. It is worth
 * adding deliberately, not smuggling in as part of this.
 */
class TwoFactor
{
    /** The RFC 6238 default, and what every authenticator app assumes. */
    public const PERIOD = 30;

    /** Six digits, as every authenticator app shows. */
    public const DIGITS = 6;

    /**
     * How many time steps either side of now are accepted.
     *
     * One step (30 seconds) in each direction. This is the trade nobody should
     * make silently: a wider window forgives a badly-set phone clock and also
     * widens the period in which a shoulder-surfed code still works. One step is
     * what RFC 6238 section 5.2 suggests and what the major implementations use.
     */
    public const WINDOW = 1;

    /** How many single-use recovery codes are issued. */
    public const RECOVERY_CODES = 10;

    /**
     * A new shared secret, base32, 160 bits.
     *
     * 160 bits because that is HMAC-SHA1's block-optimal key length and what the
     * RFC's own examples use. Base32 because that is what the `otpauth://` URI
     * carries and what a person types in by hand - it has no case sensitivity and
     * no visually ambiguous characters.
     */
    public function newSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * The enrolment URI an authenticator app understands.
     *
     * The label carries the issuer twice - once as a prefix on the label and once
     * as a parameter - which looks redundant and is what the Key URI spec asks
     * for: older apps read the prefix, newer ones read the parameter, and an app
     * that gets neither files the account under a bare email address with no clue
     * which system it belongs to.
     */
    public function enrolmentUri(string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($email);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /**
     * The code for one moment. Exposed so the evidence can check RFC vectors.
     *
     * `$digits` is a parameter only because the RFC's published vectors are eight
     * digits while authenticator apps use six. Testing against the vectors as
     * published, rather than against a truncation of them, is what makes the
     * comparison meaningful.
     */
    public function codeAt(string $secret, int $timestamp, int $digits = self::DIGITS): string
    {
        $counter = (int) floor($timestamp / self::PERIOD);

        return $this->hotp($this->base32Decode($secret), $counter, $digits);
    }

    /**
     * Whether a code is currently valid for this secret.
     *
     * ── COMPARED IN CONSTANT TIME ───────────────────────────────────────────
     *
     * `hash_equals`, not `===`. A short-circuiting string comparison leaks how
     * many leading digits were right through timing, and six digits is a small
     * enough space that leaking position by position turns a 1-in-a-million guess
     * into ten guesses per digit.
     */
    public function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $at = $at ?? time();
        $valid = false;

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $candidate = $this->codeAt($secret, $at + ($offset * self::PERIOD));

            // No early return: leaving the loop on the first match would make a
            // correct code measurably faster than a wrong one, which is the same
            // leak `hash_equals` is here to close.
            if (hash_equals($candidate, $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * Verify a code against the secret STORED for this person.
     *
     * Separate from `verify()` so no caller outside this service ever handles a
     * secret. `verify()` takes one because the evidence needs to check RFC
     * vectors; everything in the application uses this, and the secret never
     * leaves the class.
     *
     * Only a CONFIRMED enrolment counts. A pending secret must not open an
     * account - that is the difference the whole flow is built on.
     */
    public function verifyFor(int $userId, string $code): bool
    {
        $secret = DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->value('secret');

        return $secret ? $this->verify((string) $secret, $code) : false;
    }

    /* ── enrolment state ──────────────────────────────────────────────────── */

    /** Whether this person has completed enrolment. Pending does not count. */
    public function isEnabled(int $userId): bool
    {
        return DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * Begin enrolment: store a secret that does NOT yet protect the account.
     *
     * `confirmed_at` stays null until a code proves the person's app holds the
     * same secret. Enabling on the strength of the secret alone is how somebody
     * locks themselves out of their own account by closing the tab - the secret
     * would be live and their app would never have received it.
     *
     * Starting again replaces any unconfirmed secret. A half-finished enrolment is
     * not something to preserve, and reusing the old secret would mean an app that
     * scanned it once keeps working after the person deliberately restarted.
     */
    public function beginEnrolment(int $userId, ?int $tenantId): string
    {
        $secret = $this->newSecret();

        DB::table('user_two_factor')->updateOrInsert(
            ['user_id' => $userId],
            [
                'sub_institute_id' => $tenantId,
                'secret' => $secret,
                'confirmed_at' => null,
                'recovery_codes' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $secret;
    }

    /** The secret awaiting confirmation, or null. */
    public function pendingSecret(int $userId): ?string
    {
        return DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->whereNull('confirmed_at')
            ->value('secret');
    }

    /**
     * Finish enrolment and issue recovery codes.
     *
     * ── THE CODES ARE NOT OPTIONAL ──────────────────────────────────────────
     *
     * Without them, losing a phone means losing the account, and the recovery path
     * becomes "ask HR", which on a platform with twelve tenants means twelve
     * different people improvising identity checks. They are generated here and
     * returned ONCE; only their hashes are stored, so a database read cannot
     * recover them.
     *
     * @return string[] the plaintext codes, shown once and never again
     */
    public function confirmEnrolment(int $userId, string $code): ?array
    {
        $secret = $this->pendingSecret($userId);

        if (!$secret || !$this->verify($secret, $code)) {
            return null;
        }

        [$plain, $hashed] = $this->generateRecoveryCodes();

        DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->update([
                'confirmed_at' => now(),
                'recovery_codes' => json_encode($hashed),
                'updated_at' => now(),
            ]);

        return $plain;
    }

    /**
     * Issue a fresh set of recovery codes, leaving the secret alone.
     *
     * The authenticator app keeps working - only the codes change. Extracted from
     * `confirmEnrolment` rather than duplicated, because two generators would be
     * two places for the character-substitution rule to drift, and a code shown to
     * somebody in one alphabet and checked in another is unusable.
     *
     * @return string[] the plaintext codes, shown once
     */
    public function reissueRecoveryCodes(int $userId): array
    {
        [$plain, $hashed] = $this->generateRecoveryCodes();

        DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->update([
                'recovery_codes' => json_encode($hashed),
                'updated_at' => now(),
            ]);

        return $plain;
    }

    /**
     * Ten single-use codes: the plaintext to show once, the hashes to keep.
     *
     * `0/O` and `1/I/L` are substituted out because these get written on paper and
     * typed back in later. A code somebody cannot transcribe is a code that does
     * not work when they need it most - which is the one moment it exists for.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function generateRecoveryCodes(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $value = strtoupper(Str::random(5) . '-' . Str::random(5));
            $value = str_replace(['0', 'O', '1', 'I', 'L'], ['2', '3', '4', '5', '6'], $value);

            $plain[] = $value;
            $hashed[] = Hash::make($value);
        }

        return [$plain, $hashed];
    }

    /**
     * Spend a recovery code. Single use, and the spend is the point.
     *
     * A code that still worked after being used would turn a written-down list
     * into a permanent bypass of the second factor. The matching hash is removed
     * from the stored set rather than marked, so there is nothing left to replay.
     */
    public function useRecoveryCode(int $userId, string $code): bool
    {
        $row = DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->first(['recovery_codes']);

        if (!$row) {
            return false;
        }

        $hashes = json_decode((string) $row->recovery_codes, true);

        if (!is_array($hashes)) {
            return false;
        }

        $candidate = strtoupper(trim($code));

        foreach ($hashes as $index => $hash) {
            if (!Hash::check($candidate, $hash)) {
                continue;
            }

            unset($hashes[$index]);

            DB::table('user_two_factor')
                ->where('user_id', $userId)
                ->update([
                    'recovery_codes' => json_encode(array_values($hashes)),
                    'updated_at' => now(),
                ]);

            return true;
        }

        return false;
    }

    /** How many recovery codes remain unspent. */
    public function recoveryCodesLeft(int $userId): int
    {
        $stored = DB::table('user_two_factor')
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->value('recovery_codes');

        $hashes = json_decode((string) $stored, true);

        return is_array($hashes) ? count($hashes) : 0;
    }

    /** Turn it off completely. The caller is responsible for proving identity. */
    public function disable(int $userId): void
    {
        DB::table('user_two_factor')->where('user_id', $userId)->delete();
    }

    /* ── RFC 4226 / 4648 internals ────────────────────────────────────────── */

    /**
     * HOTP, RFC 4226 section 5.3 — the dynamic truncation everything rests on.
     *
     * The counter is packed big-endian into 8 bytes, HMAC-SHA1'd, and then the low
     * four bits of the LAST byte select where in the digest to read a 4-byte
     * integer from. Masking the top bit with 0x7f is not decoration: without it
     * the value is sometimes negative and the modulo returns a negative code.
     */
    private function hotp(string $key, int $counter, int $digits): string
    {
        $binary = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Base32, RFC 4648, unpadded — the alphabet authenticator apps expect. */
    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        // Padding and spaces are stripped: a person typing the secret in will add
        // the spaces it was displayed with, and some apps export it padded.
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        // Whole bytes only. A trailing partial group is padding, not data, and
        // decoding it would add a byte the encoder never wrote.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
