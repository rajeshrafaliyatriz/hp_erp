<?php

namespace App\Support;

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * THE ONE PLACE bearer-token strings are turned into a stored credential.
 *
 * ── SECURITY FIX A-1 ────────────────────────────────────────────────────────
 *
 * Between commit 23f7da09 (2026-11-19) and this change, `findToken()` did far more than look a token up. For a string it did not recognise it:
 *
 *   1. matched a stored row by `id = <the number before the "|">  OR  token = sha256(<the rest>)`, so "5|anything" authenticated as the OWNER of token
 *      row 5 without knowing its secret (Sanctum's own lookup also compares the secret; this did not);
 *   2. failing that, INSERTED a new thirty-day token row ("Session Token", abilities ["*"]) bound to `resolveAdminUser()`: the web-session user, else the
 *      user named by a request `user_id`, else an administrator of the tenant named by a request `sub_institute_id` / header, else any user at all;
 *   3. attached an administrator as the owner of any token whose owner row had gone missing;
 *   4. could CREATE TABLE `personal_access_tokens` from inside the authentication path (and `AppServiceProvider::boot()` called that on every request).
 *
 * So any string was a valid credential for an identity the CALLER chose. Before 23f7da09 every caller used `Laravel\Sanctum\PersonalAccessToken::findToken`
 * directly (a pure, hash-verified lookup). This class is now that lookup again, plus the checks every caller was repeating:
 *
 *   - an unknown, malformed or wrong-secret token             -> null
 *   - an expired token                                         -> null  (authMiddleware and the CSRF gate used to accept an expired token)
 *   - a token whose owner no longer exists                     -> null  (never "heal" to an administrator)
 *   - a token named "Session Token"                            -> null  (see PROVISIONED_TOKEN_NAME)
 *   - any database error (including a missing table)           -> null  (fail closed; nothing is created, altered or written)
 *
 * It never reads the request, the session or a header to decide WHO a token belongs to: the owner is the row's own `tokenable`.
 *
 * DEPLOYMENT PREREQUISITE: the `personal_access_tokens` table must come from the migration
 * (database/migrations/2025_04_30_053513_create_personal_access_tokens_table.php). Nothing in a request will create it any more; if it is missing every
 * token is refused (401), not provisioned.
 */
class PersonalAccessTokenResolver
{
    /**
     * The name the removed provisioning code gave every token it fabricated (no other code in the repository or its history uses this name; real logins are
     * named by DeviceLabel, e.g. "Chrome on Windows" or "API or script"). Rows already created by that code are still in the database after this fix.
     * They are refused HERE, i.e. by every caller that goes through this class; they are NOT refused by the many controllers that call Sanctum's
     * `PersonalAccessToken::findToken` directly (they never went through this class), nor by the `auth:sanctum` guard. Those need the data clean-up
     * (approval A-1b: review, then revoke the rows named "Session Token"). A person whose token was one of them simply signs in again.
     */
    public const PROVISIONED_TOKEN_NAME = 'Session Token';

    /**
     * @return PersonalAccessToken|null a stored, unexpired token whose owner exists; null for everything else
     */
    public static function findToken(?string $token): ?PersonalAccessToken
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        try {
            // Sanctum's own lookup: splits "id|secret" and verifies the SECRET against the stored hash (or, for a bare token, the hash itself).
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken === null) {
                return null;
            }

            if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
                return null;
            }

            if (strtolower(trim((string) $accessToken->name)) === strtolower(self::PROVISIONED_TOKEN_NAME)) {
                return null;
            }

            // The owner must still exist AND be the application's user model (every login issues its token on it). An orphaned token, or one owned by some
            // other model, is not an identity here and is never re-bound to anybody else.
            if (! ($accessToken->tokenable instanceof tbluserModel)) {
                return null;
            }

            return $accessToken;
        } catch (\Throwable $e) {
            // Class only: a message can carry SQL, table names or a token fragment.
            Log::warning('PersonalAccessTokenResolver: lookup failed (' . get_class($e) . '); token refused.');

            return null;
        }
    }
}
