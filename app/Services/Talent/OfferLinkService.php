<?php

namespace App\Services\Talent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The candidate's key to a single offer.
 *
 * ── WHY A TOKEN AND NOT AN ACCOUNT ──────────────────────────────────────────
 *
 * A candidate is not a user of this product. Giving them a login means a second
 * identity model, a registration flow, password reset, verification and lockout
 * — all before they can answer one question. A signed, expiring, single-purpose
 * link answers that one question and expires.
 *
 * ── WHAT THIS DOES DIFFERENTLY FROM THE TWO EXISTING PRECEDENTS ─────────────
 *
 * `signupOtpController` generates with `rand()` over a 10,000-value space, stores
 * the code in plaintext, never enforces single use (it sets is_verified and never
 * reads it back), and its routes carry no throttle.
 *
 * `password_reset_tokens` uses a proper CSPRNG but stores the token in plaintext,
 * writes `created_at` and never checks it on redemption, and enforces single use
 * by DELETING the row — destroying the audit trail.
 *
 * This takes the good half of each and fixes the rest:
 *
 *   - `Str::random(64)` — CSPRNG, 64 chars.
 *   - only the SHA-256 HASH is stored. A leaked database row is not redeemable.
 *   - `token_expires_at` is written AND checked at redemption.
 *   - single use is `token_used_at` — a marker, not a delete, so the record of
 *     who answered and when survives.
 *   - one live token per offer: re-issuing overwrites the hash, so an older link
 *     stops working the moment a new one is sent.
 */
class OfferLinkService
{
    /** How long a candidate has to answer before the link stops working. */
    public const TTL_DAYS = 14;

    /**
     * Mint a link for an offer. Returns the RAW token — the only moment it exists
     * in a readable form. It is not stored and cannot be recovered afterwards; a
     * lost link is re-issued, never looked up.
     *
     * @return array{token:string, expires_at:\Illuminate\Support\Carbon}
     */
    public function mint(object $offer, int $tenantId, string $syear, ?int $actorId, ?string $candidateEmail): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        $row = [
            'acceptance_token_hash' => hash('sha256', $token),
            'token_expires_at'      => $expiresAt,
            'token_used_at'         => null,
            'candidate_email'       => $candidateEmail,
            'updated_by'            => $actorId,
            'updated_at'            => now(),
        ];

        $existing = DB::table('talent_offer_acceptances')
            ->where('offer_id', $offer->id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'decision']);

        if ($existing) {
            DB::table('talent_offer_acceptances')->where('id', $existing->id)->update($row);
        } else {
            DB::table('talent_offer_acceptances')->insert($row + [
                'sub_institute_id' => $tenantId,
                'syear'            => $syear,
                'offer_id'         => (int) $offer->id,
                'application_id'   => (int) $offer->application_id,
                'decision'         => 'pending',
                'created_by'       => $actorId,
                'created_at'       => now(),
            ]);
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * The acceptance row a raw token opens, or null.
     *
     * Null covers every failure identically — unknown, expired, already used — so
     * a caller cannot tell which by probing. The reason is returned separately for
     * the page to show a person, never for a machine to branch on.
     *
     * @return array{row:?object, reason:?string}
     */
    public function resolve(string $token): array
    {
        if (strlen($token) !== 64) {
            return ['row' => null, 'reason' => 'not_found'];
        }

        $row = DB::table('talent_offer_acceptances')
            ->where('acceptance_token_hash', hash('sha256', $token))
            ->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return ['row' => null, 'reason' => 'not_found'];
        }

        if ($row->token_used_at !== null) {
            return ['row' => null, 'reason' => 'used'];
        }

        if ($row->token_expires_at !== null && now()->greaterThan($row->token_expires_at)) {
            return ['row' => null, 'reason' => 'expired'];
        }

        return ['row' => $row, 'reason' => null];
    }

    /** Burn the token. Called once the candidate's answer is recorded. */
    public function markUsed(int $acceptanceId): void
    {
        DB::table('talent_offer_acceptances')
            ->where('id', $acceptanceId)
            ->update(['token_used_at' => now(), 'updated_at' => now()]);
    }
}
