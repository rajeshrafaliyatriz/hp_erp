<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized, module-wise email sending — turns `smtp_details` from "at
 * most one row per tenant" into "one row per (tenant, module)", `module_key`
 * defaulting to 'default' (the tenant's catch-all profile, read by
 * App\Support\MailSender when no module-specific profile exists).
 *
 * WHY 'default' AND NOT NULL AS THE SENTINEL
 *
 * MySQL does not treat NULL as equal to itself for a unique index's purposes
 * — two rows with the same sub_institute_id and a NULL module_key would NOT
 * violate unique(sub_institute_id, module_key), silently allowing more than
 * one "default" row per tenant. A real string sentinel is enforced properly.
 *
 * WHY `password` WIDENS TO text()
 *
 * It was `string` (varchar 255) - enough for a human app-password, not
 * enough for a Laravel encrypted payload (base64 JSON of iv/value/mac/tag,
 * routinely 300+ characters). Confirmed directly: the first run of this
 * migration failed encrypting the one real row with "Data too long for
 * column 'password'" before the column was widened.
 *
 * WHY EVERY STEP IS GUARDED
 *
 * MySQL's DDL is not transactional - ALTER TABLE statements commit
 * immediately and cannot roll back with the rest of the migration. The
 * first run of this file is exactly why: it added the 3 new columns and the
 * unique index successfully, then failed on the password-widening/encrypt
 * step, leaving the migration unrecorded so `artisan migrate` retries from
 * the top - which would otherwise fail a second time on "column already
 * exists". Every schema change here checks its own precondition first so
 * the file is safe to run from a clean table OR resume from that exact
 * partial state.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_26_080000_add_profile_columns_to_smtp_details_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_26_080000_add_profile_columns_to_smtp_details_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smtp_details', function (Blueprint $table) {
            if (! Schema::hasColumn('smtp_details', 'label')) {
                $table->string('label')->nullable()->after('id');
            }
            if (! Schema::hasColumn('smtp_details', 'module_key')) {
                $table->string('module_key', 60)->default('default')->after('label');
            }
            if (! Schema::hasColumn('smtp_details', 'from_name')) {
                $table->string('from_name')->nullable()->after('gmail');
            }
        });

        // Widen BEFORE encrypting - an encrypted payload will not fit the
        // original varchar(255).
        if ($this->columnType('smtp_details', 'password') !== 'text') {
            Schema::table('smtp_details', function (Blueprint $table) {
                $table->text('password')->change();
            });
        }

        // Existing rows get the sentinel explicitly (belt-and-braces alongside
        // the column default, which only applies to NEW inserts on some DB
        // engines/versions - never rely on a default alone for a backfill).
        DB::table('smtp_details')->whereNull('module_key')->update(['module_key' => 'default']);

        // `label` is new and every row that existed before it is NULL - and
        // the admin screen's own CRUD now requires one on every row it
        // writes, so a NULL here can only mean "this is the one row that
        // predates the feature". A blank name in a list the feature itself
        // introduced is a worse first impression than a generic one.
        DB::table('smtp_details')->whereNull('label')->update(['label' => 'Default sender']);

        if (! $this->indexExists('smtp_details', 'smtp_details_tenant_module_unique')) {
            Schema::table('smtp_details', function (Blueprint $table) {
                $table->unique(['sub_institute_id', 'module_key'], 'smtp_details_tenant_module_unique');
            });
        }

        // Encrypt the one real row's plaintext password in place. Guarded by
        // a decrypt-probe so this migration is safe to re-run against an
        // environment where it already happened (an encrypted payload is
        // base64 JSON, and a bare app-password string never decrypts as one).
        foreach (DB::table('smtp_details')->whereNotNull('password')->get(['id', 'password']) as $row) {
            if ($this->looksAlreadyEncrypted($row->password)) {
                continue;
            }

            DB::table('smtp_details')->where('id', $row->id)->update([
                'password' => Crypt::encryptString($row->password),
            ]);
        }
    }

    public function down(): void
    {
        if ($this->indexExists('smtp_details', 'smtp_details_tenant_module_unique')) {
            Schema::table('smtp_details', function (Blueprint $table) {
                $table->dropUnique('smtp_details_tenant_module_unique');
            });
        }

        Schema::table('smtp_details', function (Blueprint $table) {
            $columns = array_filter(['label', 'module_key', 'from_name'], fn ($c) => Schema::hasColumn('smtp_details', $c));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        // Deliberately does not narrow `password` back to varchar(255) or
        // decrypt it - a rollback is not an invitation to weaken or risk
        // truncating a stored secret, and App\Support\MailSender simply
        // stops existing in the rolled-back state, so nothing reads this
        // column as encrypted again until this migration re-runs.
    }

    private function looksAlreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE as type FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $row->type ?? null;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $indexName]
        ) !== [];
    }
};
