<?php

use App\Support\ColumnExists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only (nothing is changed or dropped), and every column is nullable, so rows written before this migration stay valid and the
 * code keeps working before it is applied (it checks for the columns).
 *
 *  - g2g_ingestion_analyses: the decision made for one post (a structured code, a plain reason, the exact quote when it qualified) and the
 *    sync that made it, so each sync can show a per-post report.
 *  - g2g_data_connectors.monitoring_settings: the organisation's own keywords, exclusions, accounts, limits and content types for the connector.
 *
 * Uses ColumnExists (SHOW COLUMNS) because the live MariaDB 10.1 cannot run Schema::hasColumn().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_ingestion_analyses')) {
            Schema::table('g2g_ingestion_analyses', function (Blueprint $table) {
                if (! ColumnExists::has('g2g_ingestion_analyses', 'connector_sync_id')) {
                    $table->unsignedBigInteger('connector_sync_id')->nullable()->index('g2g_ingest_ana_sync');
                }
                if (! ColumnExists::has('g2g_ingestion_analyses', 'decision_code')) {
                    $table->string('decision_code', 40)->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_analyses', 'decision_detail')) {
                    $table->string('decision_detail', 500)->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_analyses', 'decision_quote')) {
                    $table->text('decision_quote')->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_analyses', 'decision_organisation')) {
                    $table->string('decision_organisation', 191)->nullable();
                }
            });
        }

        if (Schema::hasTable('g2g_data_connectors') && ! ColumnExists::has('g2g_data_connectors', 'monitoring_settings')) {
            Schema::table('g2g_data_connectors', function (Blueprint $table) {
                $table->longText('monitoring_settings')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('g2g_ingestion_analyses')) {
            Schema::table('g2g_ingestion_analyses', function (Blueprint $table) {
                if (ColumnExists::has('g2g_ingestion_analyses', 'connector_sync_id')) {
                    $table->dropIndex('g2g_ingest_ana_sync');
                }
            });
            Schema::table('g2g_ingestion_analyses', function (Blueprint $table) {
                foreach (['connector_sync_id', 'decision_organisation', 'decision_quote', 'decision_detail', 'decision_code'] as $column) {
                    if (ColumnExists::has('g2g_ingestion_analyses', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
        if (Schema::hasTable('g2g_data_connectors') && ColumnExists::has('g2g_data_connectors', 'monitoring_settings')) {
            Schema::table('g2g_data_connectors', function (Blueprint $table) {
                $table->dropColumn('monitoring_settings');
            });
        }
    }
};
