<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 CRM Automation — Core Revenue Loop:
 * Adds explicit consent/suppression tracking columns to crm_leads and
 * crm_contacts. The existing `email_opt_out` boolean is preserved; these
 * three new columns provide a richer model:
 *
 *   consent_status   — unknown|opted_in|opted_out|suppressed
 *                      'unknown' = no consent record captured yet (the safe
 *                      default — callers must confirm before outreach)
 *   suppressed_at    — when the record was suppressed (hard-stop for outreach)
 *   suppressed_reason — why (bounced, complaint, legal hold, manual, etc.)
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_080100_add_consent_fields_to_crm_leads_contacts.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_080100_add_consent_fields_to_crm_leads_contacts.php
 */
return new class extends Migration
{
    private const TABLES = ['crm_leads', 'crm_contacts'];

    private const COLUMNS = [
        'consent_status'   => 'addColumn',
        'suppressed_at'    => 'addColumn',
        'suppressed_reason' => 'addColumn',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tbl) {
            if (! $this->tableExists($tbl)) {
                continue;
            }

            Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                if (! $this->columnExists($tbl, 'consent_status')) {
                    $table->string('consent_status', 30)->nullable()->default('unknown')->after('email_opt_out');
                }
                if (! $this->columnExists($tbl, 'suppressed_at')) {
                    $table->timestamp('suppressed_at')->nullable()->after('consent_status');
                }
                if (! $this->columnExists($tbl, 'suppressed_reason')) {
                    $table->string('suppressed_reason', 191)->nullable()->after('suppressed_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tbl) {
            if (! $this->tableExists($tbl)) {
                continue;
            }

            Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                $cols = [];
                foreach (['suppressed_reason', 'suppressed_at', 'consent_status'] as $col) {
                    if ($this->columnExists($tbl, $col)) {
                        $cols[] = $col;
                    }
                }
                if ($cols) {
                    $table->dropColumn($cols);
                }
            });
        }
    }

    private function tableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasColumn($table, $column);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c > 0;
    }
};
