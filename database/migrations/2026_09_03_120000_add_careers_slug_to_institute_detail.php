<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A public, stable name for each organisation's careers page.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_120000_add_careers_slug_to_institute_detail.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_120000_add_careers_slug_to_institute_detail.php
 *
 * ── WHY NOT organization_code ───────────────────────────────────────────────
 *
 * It looks like the obvious candidate and it cannot be used:
 *
 *   - it is varchar(191), and the house rule forbids indexing one (767-byte
 *     prefix cap on live's ROW_FORMAT=Compact);
 *   - it is NOT unique on live - 5 rows, 4 distinct values - so two tenants
 *     already share a code;
 *   - it is operational data an administrator may edit, and a public URL that
 *     changes when somebody corrects a typo is a broken link.
 *
 * So: a dedicated varchar(64), uniquely indexed, derived once from the
 * organisation name and thereafter left alone.
 *
 * ── TENANTS WITHOUT AN institute_detail ROW ─────────────────────────────────
 *
 * Only 10 of the tenants holding users have a row here, so the rest simply have
 * no careers page until somebody fills in their organisation detail. That is the
 * correct behaviour - a public page for an organisation whose name we do not
 * know is not something to invent - and the API returns 404 for an unknown slug.
 */
return new class extends Migration
{
    private const TABLE = 'institute_detail';

    public function up(): void
    {
        if ($this->hasColumn('careers_slug')) {
            return;
        }

        // varchar(64): 64 x 4 bytes under utf8mb4 = 256, well inside the 767-byte
        // index prefix cap that live's Compact row format imposes.
        DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `careers_slug` VARCHAR(64) NULL AFTER `organization_code`');

        $this->backfill();

        // Named explicitly. Laravel would generate
        // `institute_detail_careers_slug_unique` (36 chars, fine) but the habit is
        // what keeps a longer name from failing later.
        DB::statement('ALTER TABLE `' . self::TABLE . '` ADD UNIQUE KEY `inst_careers_slug_unique` (`careers_slug`)');
    }

    public function down(): void
    {
        if (!$this->hasColumn('careers_slug')) {
            return;
        }

        DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `inst_careers_slug_unique`');
        DB::statement('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `careers_slug`');
    }

    /**
     * One slug per organisation, derived from its name and made unique.
     *
     * Collisions are resolved by appending the tenant id rather than a counter, so
     * the value is stable: re-running against the same data produces the same
     * slug, and a new tenant cannot take a name that changes an existing URL.
     */
    private function backfill(): void
    {
        $rows = DB::table(self::TABLE)
            ->select('id', 'sub_institute_id', 'organization_name')
            ->orderBy('id')
            ->get();

        $taken = [];

        foreach ($rows as $row) {
            $base = Str::slug((string) $row->organization_name);
            $base = $base !== '' ? Str::limit($base, 50, '') : 'organisation';

            $slug = $base;
            if (isset($taken[$slug])) {
                $slug = Str::limit($base, 50, '') . '-' . $row->sub_institute_id;
            }

            // Still taken (two rows, same tenant, same name) - fall back to the row id.
            if (isset($taken[$slug])) {
                $slug = Str::limit($base, 45, '') . '-' . $row->id;
            }

            $taken[$slug] = true;

            DB::table(self::TABLE)->where('id', $row->id)->update(['careers_slug' => $slug]);
        }
    }

    /** Schema::hasColumn() throws on live (MariaDB 10.1.48); read the catalogue. */
    private function hasColumn(string $column): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, $column]
        )->c ?? 0) > 0;
    }
};
