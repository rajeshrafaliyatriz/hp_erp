<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The campaign a lead FIRST came from (slice 9 of the agentic CRM, Docs/agentic-crm/slices/S09-campaigns-to-leads.md).
 *
 *   campaign_id   BIGINT UNSIGNED NULL   the CRM campaign (crm_campaigns.id) that first touched the lead. A loose pointer, no foreign key. First-touch: it is
 *                                        set when the lead is created (the caller names the campaign) and by `campaigns:backfill-links`; it is never
 *                                        overwritten by a later touch. The older free-text `campaign_text` is left exactly as it is.
 *
 * Indexed with the organisation first, `(sub_institute_id, campaign_id)`, because every read of a lead is organisation-scoped and crm_leads.sub_institute_id
 * is nullable on older rows: every query this slice adds filters it explicitly.
 *
 * ADDITIVE: one nullable column at the end of the table and one index. Every existing row keeps NULL. Guarded by information_schema checks (never
 * ColumnExists::has: its bound SHOW COLUMNS ... LIKE ? form fails on the live MariaDB 10.1, see DB-RULES.md), so a second run does nothing and a host that
 * lacks the table skips it. down() removes the index and the column up() added, only if they are there.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_140200_add_campaign_id_to_crm_leads.php
 */
return new class extends Migration
{
    private const TABLE = 'crm_leads';

    /** @var array<string, string> column => the index that starts with the organisation */
    private const COLUMNS = ['campaign_id' => 'crm_leads_tenant_campaign'];

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(string $column): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('COLUMN_NAME', $column)->exists();
        }

        return Schema::hasColumn(self::TABLE, $column);
    }

    private function hasIndex(string $index): bool
    {
        if ($this->isMariaDb()) {
            return DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', self::TABLE)->where('INDEX_NAME', $index)->exists();
        }

        return Schema::hasIndex(self::TABLE, $index);
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column => $index) {
            if (! $this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` ADD COLUMN `{$column}` BIGINT UNSIGNED NULL");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable());
                }
            }
            if (! $this->hasIndex($index)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` ADD INDEX `{$index}` (`sub_institute_id`, `{$column}`)");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->index(['sub_institute_id', $column], $index));
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column => $index) {
            if ($this->hasIndex($index)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` DROP INDEX `{$index}`");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex($index));
                }
            }
            if ($this->hasColumn($column)) {
                if ($this->isMariaDb()) {
                    DB::statement('ALTER TABLE `' . self::TABLE . "` DROP COLUMN `{$column}`");
                } else {
                    Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
