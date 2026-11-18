<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Defect D9 (Docs/agentic-crm/_evidence/s3-design-gate-checks-2026-11-03.md): crm_organizations.email / secondary_email and crm_contacts.email /
 * secondary_email are varchar(100) on dev AND live, while validation accepts 191 characters. The live database is not strict, so a longer address is
 * silently CUT OFF at 100 characters and stored as a different, undeliverable address.
 *
 * Widening varchar(100) to varchar(191) keeps the length prefix at two bytes under utf8mb4 (both exceed 255 bytes), so MariaDB does it in place without
 * rebuilding the table. Each column is changed only when it is currently narrower than 191, so a second run does nothing. NOT NULL-ness and defaults are
 * kept: every column is `NULL` today (checked on both hosts) and stays so.
 *
 * Not reversible on purpose: narrowing the columns again could truncate addresses saved since. The sqlite test database does not enforce varchar length,
 * so there is nothing to change there.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_09_090100_widen_crm_email_columns_to_191.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_09_090100_widen_crm_email_columns_to_191.php
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = ['crm_organizations' => ['email', 'secondary_email'], 'crm_contacts' => ['email', 'secondary_email']];

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $row = DB::selectOne(
                    'SELECT CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS nullable, DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );
                if ($row === null || strtolower((string) $row->type) !== 'varchar' || (int) $row->len >= 191) {
                    continue;
                }
                $null = strtoupper((string) $row->nullable) === 'YES' ? 'NULL' : 'NOT NULL';
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(191) {$null}");
            }
        }
    }

    public function down(): void
    {
        // Left in place on purpose: narrowing would cut off any address longer than 100 characters saved since.
    }
};
