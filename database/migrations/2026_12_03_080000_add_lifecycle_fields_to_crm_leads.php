<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 CRM Automation — Core Revenue Loop:
 * Adds three fields that track where a lead sits in the automation pipeline,
 * separate from the picklist-driven `lead_status` (which mirrors vtiger's own
 * vocabulary). These three columns are owned by the G2G workflow layer.
 *
 *   lifecycle_status — machine-readable stage: new|contacted|qualified|
 *                      unqualified|converted|lost
 *   next_action      — free-text field for the rep to note what to do next
 *   follow_up_due_date — when the follow-up is due; drives the Command Center
 *                        "My Tasks" list and SLA alerts
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_080000_add_lifecycle_fields_to_crm_leads.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_080000_add_lifecycle_fields_to_crm_leads.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('crm_leads')) {
            return;
        }

        Schema::table('crm_leads', function (Blueprint $table) {
            if (! $this->columnExists('crm_leads', 'lifecycle_status')) {
                $table->string('lifecycle_status', 50)->nullable()->default('new')->after('lead_status');
            }
            if (! $this->columnExists('crm_leads', 'next_action')) {
                $table->text('next_action')->nullable()->after('lifecycle_status');
            }
            if (! $this->columnExists('crm_leads', 'follow_up_due_date')) {
                $table->timestamp('follow_up_due_date')->nullable()->after('next_action');
            }
        });
    }

    public function down(): void
    {
        if (! $this->tableExists('crm_leads')) {
            return;
        }

        Schema::table('crm_leads', function (Blueprint $table) {
            $cols = [];
            foreach (['follow_up_due_date', 'next_action', 'lifecycle_status'] as $col) {
                if ($this->columnExists('crm_leads', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
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
