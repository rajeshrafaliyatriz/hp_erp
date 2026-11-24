<?php

namespace App\Support;

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
<<<<<<< HEAD
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
=======
 * Finds the personal access token a request carries, and the user it belongs to.
 *
 * THE RULE THIS CLASS MUST KEEP: a bearer string is a signed-in caller only if a token that was ISSUED for it exists (its hash is in `personal_access_tokens`)
 * and its owner still exists. An unknown string, a wrong secret behind a real id, or a token whose user is gone is NOT a caller: the answer is null, and nothing is
 * written. Nothing in the request (`user_id`, `sub_institute_id`, the session) may choose who a token belongs to.
 *
 * History: the first version of this class (commit 23f7da09, 19 Nov 2026) "healed" an unknown token by writing a new 30-day row for it and binding it to an
 * administrator, to the `user_id` named in the request, or to the first user in `tbluser`; its lookup of an existing row also matched on the id alone and ignored the
 * secret. Any string was therefore a signed-in caller, and any user could be impersonated. Real sign-ins issue Sanctum tokens (`createToken` in authController and
 * GoogleAuthController), which Sanctum finds by hash, so there is nothing to heal. Tests: tests/Feature/PersonalAccessTokenResolverTest.php.
>>>>>>> 990fe53908c36eb3a58c8ddf030316360dd0ba05
 */
class PersonalAccessTokenResolver
{
    /** The table is checked once per process (a request or an artisan run), not on every lookup. */
    private static bool $tableChecked = false;

    /**
<<<<<<< HEAD
     * The name the removed provisioning code gave every token it fabricated (no other code in the repository or its history uses this name; real logins are
     * named by DeviceLabel, e.g. "Chrome on Windows" or "API or script"). Rows already created by that code are still in the database after this fix.
     * They are refused HERE, i.e. by every caller that goes through this class; they are NOT refused by the many controllers that call Sanctum's
     * `PersonalAccessToken::findToken` directly (they never went through this class), nor by the `auth:sanctum` guard. Those need the data clean-up
     * (approval A-1b: review, then revoke the rows named "Session Token"). A person whose token was one of them simply signs in again.
     */
    public const PROVISIONED_TOKEN_NAME = 'Session Token';

    /**
     * @return PersonalAccessToken|null a stored, unexpired token whose owner exists; null for everything else
=======
     * The stored token that belongs to this bearer string, with its owner attached, or null.
>>>>>>> 990fe53908c36eb3a58c8ddf030316360dd0ba05
     */
    public static function findToken(?string $token): ?PersonalAccessToken
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

<<<<<<< HEAD
=======
        static::ensureTable();

        $accessToken = null;
>>>>>>> 990fe53908c36eb3a58c8ddf030316360dd0ba05
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

<<<<<<< HEAD
            return null;
        }
=======
        // Sanctum did not find it: look the row up ourselves, by the hash of the secret (never by the id alone).
        if (! $accessToken) {
            $accessToken = static::storedRow($token);
        }

        if (! $accessToken) {
            return null;
        }

        // The owner must be a real user. The row may name the owner under another model class, so it is read from tbluser by id; if there is none, the token is no caller.
        if (! $accessToken->relationLoaded('tokenable') || ! $accessToken->tokenable) {
            $userId = $accessToken->tokenable_id ?? null;
            $user = null;
            try {
                $user = $userId ? tbluserModel::find($userId) : null;
            } catch (\Throwable $e) {
                Log::warning('PersonalAccessTokenResolver owner lookup error: ' . $e->getMessage());
            }
            if (! $user) {
                return null;
            }
            $accessToken->setRelation('tokenable', $user);
        }

        // A suspended or deleted account's token is no caller either (sign-in refuses such an account, and suspending one revokes its tokens; this covers the token
        // that survived, for example a status set straight in the database). Only an EXPLICIT status other than 1 or a deletion counts: a row with no status is as
        // before.
        $owner = $accessToken->tokenable;
        if (isset($owner->status) && (int) $owner->status !== 1) {
            return null;
        }
        if (! empty($owner->deleted_at)) {
            return null;
        }

        return $accessToken;
    }

    /**
     * Ensure the personal_access_tokens table exists in the active (MySQL or MariaDB) database. Other drivers (the sqlite test database) have no information_schema and
     * make their own tables, so they are left alone. Checked once per process.
     */
    public static function ensureTable(): void
    {
        if (static::$tableChecked) {
            return;
        }
        static::$tableChecked = true;

        try {
            if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                return;
            }

            $tableExists = DB::selectOne(
                "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'personal_access_tokens'"
            );

            if ((int) ($tableExists->n ?? 0) === 0) {
                DB::statement("CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
                    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    `tokenable_type` varchar(191) NOT NULL,
                    `tokenable_id` bigint(20) unsigned NOT NULL,
                    `name` varchar(191) NOT NULL,
                    `token` varchar(64) NOT NULL,
                    `abilities` text DEFAULT NULL,
                    `last_used_at` timestamp NULL DEFAULT NULL,
                    `expires_at` timestamp NULL DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT NULL,
                    `updated_at` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
                    KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (\Throwable $e) {
            Log::warning('PersonalAccessTokenResolver::ensureTable error: ' . $e->getMessage());
        }
    }

    /**
     * The row whose stored hash is the hash of this string's secret. `12|secret` also has to be row 12: the id narrows the match, it never replaces the hash.
     */
    private static function storedRow(string $token): ?PersonalAccessToken
    {
        $id = null;
        $plain = $token;

        if (strpos($token, '|') !== false) {
            [$rawId, $plain] = explode('|', $token, 2);
            if (! ctype_digit($rawId)) {
                return null;   // like Sanctum: a prefix that is not a row number is no token
            }
            $id = (int) $rawId;
        }

        if ($plain === '') {
            return null;
        }

        try {
            $query = DB::table('personal_access_tokens')->where('token', hash('sha256', $plain));
            if ($id !== null) {
                $query->where('id', $id);
            }
            $row = $query->first();
        } catch (\Throwable) {
            return null;
        }

        if (! $row) {
            return null;
        }

        $model = new PersonalAccessToken((array) $row);
        $model->exists = true;

        return $model;
>>>>>>> 990fe53908c36eb3a58c8ddf030316360dd0ba05
    }
}
