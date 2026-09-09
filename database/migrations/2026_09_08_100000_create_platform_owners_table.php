<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WHO IS ALLOWED TO CREATE AN ORGANISATION.
 *
 *   php artisan migrate --path=database/migrations/2026_09_08_100000_create_platform_owners_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_08_100000_create_platform_owners_table.php
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE PRODUCT HAD NOBODY WHO COULD DO THIS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every profile in `tbluserprofilemaster` carries a `sub_institute_id`. The most
 * senior role the platform defines is `administrator`, and an administrator is
 * an administrator OF ONE ORGANISATION. There is no row anywhere that means
 * "this person acts above the tenants", which is why `POST /api/school-setup`
 * ended up anonymous: there was no identity to gate it with, so it was gated
 * with nothing.
 *
 * ── WHY A TABLE AND NOT A COLUMN ────────────────────────────────────────────
 *
 * `tbluser.is_admin` already exists and was the obvious candidate. It is not
 * usable:
 *
 *   - It is TENANT-scoped. Seven users have is_admin = 1 on dev and every one of
 *     them is the administrator of a single organisation.
 *   - It is overloaded. GoogleAuthController branches on `is_admin == 1` AND
 *     `is_admin == 2` as different things.
 *   - IT IS SETTABLE FROM A REQUEST BODY. UserSignupController::store() reads
 *     `intval($data['is_admin'] ?? 0)` straight off an unauthenticated POST. A
 *     permission that a caller can grant themselves is not a permission.
 *
 * A tenth `role_key` was the other candidate and is wrong for a different
 * reason: `RoleKey::ALL` is the vocabulary of roles WITHIN an organisation, and
 * every consumer of it - RequireProfile, the leave matrix, the navigation rules -
 * reads it as such.
 *
 * So: a separate table. Nothing mass-assigns into it, no existing controller
 * writes to it, and membership is one row that a human deliberately created.
 *
 * ── REVOCATION IS A COLUMN, NOT A DELETE ────────────────────────────────────
 *
 * `revoked_at` rather than removing the row, because "who used to be able to
 * create organisations, and when did that stop" is a question somebody will ask
 * after the fact. A deleted row cannot answer it.
 *
 * ── AGAINST LIVE'S LIMITS ───────────────────────────────────────────────────
 *
 * MariaDB 10.1.48, InnoDB, ROW_FORMAT=Compact: 767-byte index prefix, 64-char
 * identifiers. Widest index here is 8 bytes (user_id). Longest identifier is
 * `platform_owners_user_id_unique`, 30 characters. No json, no ENUM.
 *
 * Guarded on table existence, so re-running is a no-op.
 */
return new class extends Migration
{
    /**
     * The first owner, by EMAIL rather than id.
     *
     * `tbluser.email` is UNIQUE across the whole table, so it identifies the same
     * human on both databases; ids do not have to agree between them and relying
     * on one would be how the wrong account gets the keys to tenant creation.
     * (They do agree here - #28 on both - but that is luck, not a guarantee.)
     *
     * Seeded rather than left empty because a gate nobody can pass is a feature
     * nobody can use, and the alternative - shipping it open until somebody
     * remembers to grant - is how the anonymous endpoint happened in the first
     * place. Change it with `php artisan platform:owner`.
     */
    private const FIRST_OWNER_EMAIL = 'scholarclone@gmail.com';

    public function up(): void
    {
        if (!$this->tableExists('platform_owners')) {
            DB::statement(
                'CREATE TABLE `platform_owners` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `note` VARCHAR(191) NULL,
                    `granted_by` BIGINT UNSIGNED NULL,
                    `granted_at` TIMESTAMP NULL,
                    `revoked_by` BIGINT UNSIGNED NULL,
                    `revoked_at` TIMESTAMP NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `platform_owners_user_id_unique` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        /*
         * No foreign key on user_id, deliberately.
         *
         * tbluser carries 260 inbound FKs already and this table has to be
         * writable on a database where that user row may be being repaired. The
         * middleware joins to tbluser anyway and a row pointing at a user who no
         * longer exists resolves to "not an owner", which is the safe answer.
         */

        $owner = DB::table('tbluser')
            ->where('email', self::FIRST_OWNER_EMAIL)
            ->whereNull('deleted_at')
            ->value('id');

        if (!$owner) {
            // Not fatal. A database without this account is a database where
            // somebody runs `platform:owner grant` by hand, which is the correct
            // outcome rather than a migration that refuses to finish.
            return;
        }

        $already = DB::table('platform_owners')->where('user_id', $owner)->exists();

        if ($already) {
            return;
        }

        DB::table('platform_owners')->insert([
            'user_id' => $owner,
            'note' => 'Seeded by 2026_09_08_100000. The first platform owner.',
            // granted_by is NULL: nobody granted this one, the migration did, and
            // recording a person who did not make the decision would be a lie in
            // the audit column.
            'granted_by' => null,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!$this->tableExists('platform_owners')) {
            return;
        }

        DB::statement('DROP TABLE `platform_owners`');
    }

    /** information_schema, not Schema::hasTable() - that throws on live's 10.1. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
