<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\auth\tbluserModel;

/**
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
 */
class PersonalAccessTokenResolver
{
    /** The table is checked once per process (a request or an artisan run), not on every lookup. */
    private static bool $tableChecked = false;

    /**
     * The stored token that belongs to this bearer string, with its owner attached, or null.
     */
    public static function findToken(?string $token): ?PersonalAccessToken
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        static::ensureTable();

        $accessToken = null;
        try {
            $accessToken = PersonalAccessToken::findToken($token);
        } catch (\Throwable $e) {
            Log::warning('PersonalAccessTokenResolver findToken error: ' . $e->getMessage());
        }

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
    }
}
