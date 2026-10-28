<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 CRM Automation — Core Revenue Loop:
 * Extends crm_campaign_targets with per-event timestamps so campaign
 * attribution can follow a contact from first touch to meeting to opportunity.
 *
 * The existing `response_status` mutable field is kept — it mirrors the
 * vtiger legacy (Contacted-Successful/Unsuccessful/NeverAgain) and is still
 * used by the current Campaign detail UI. These seven new columns are
 * append-once: once set they are only updated if the event happens again
 * (e.g., replied again). The CrmCampaignController::recordTargetEvent()
 * method sets them via a dedicated route.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_080300_add_touchpoint_events_to_crm_campaign_targets.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_080300_add_touchpoint_events_to_crm_campaign_targets.php
 */
return new class extends Migration
{
    private const NEW_COLUMNS = [
        'sent_at', 'opened_at', 'replied_at',
        'meeting_booked_at', 'opted_out_at', 'converted_at',
        'notes',
    ];

    public function up(): void
    {
        if (! $this->tableExists('crm_campaign_targets')) {
            return;
        }

        Schema::table('crm_campaign_targets', function (Blueprint $table) {
            if (! $this->columnExists('crm_campaign_targets', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
            if (! $this->columnExists('crm_campaign_targets', 'opened_at')) {
                $table->timestamp('opened_at')->nullable();
            }
            if (! $this->columnExists('crm_campaign_targets', 'replied_at')) {
                $table->timestamp('replied_at')->nullable();
            }
            if (! $this->columnExists('crm_campaign_targets', 'meeting_booked_at')) {
                $table->timestamp('meeting_booked_at')->nullable();
            }
            if (! $this->columnExists('crm_campaign_targets', 'opted_out_at')) {
                $table->timestamp('opted_out_at')->nullable();
            }
            if (! $this->columnExists('crm_campaign_targets', 'converted_at')) {
                $table->timestamp('converted_at')->nullable();
            }
            if (! $this->columnExists('crm_campaign_targets', 'notes')) {
                $table->text('notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! $this->tableExists('crm_campaign_targets')) {
            return;
        }

        Schema::table('crm_campaign_targets', function (Blueprint $table) {
            $cols = array_filter(self::NEW_COLUMNS, fn ($c) => $this->columnExists('crm_campaign_targets', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
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
