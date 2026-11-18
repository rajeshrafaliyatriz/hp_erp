<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\ColumnExists;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_data_connectors')) {
            Schema::table('g2g_data_connectors', function (Blueprint $table) {
                $table->string('url', 2000)->nullable()->change();
                if (! ColumnExists::has('g2g_data_connectors', 'credentials')) {
                    $table->longText('credentials')->nullable();
                }
                if (! ColumnExists::has('g2g_data_connectors', 'external_account_id')) {
                    $table->string('external_account_id', 191)->nullable();
                }
                if (! ColumnExists::has('g2g_data_connectors', 'account_name')) {
                    $table->string('account_name', 191)->nullable();
                }
                if (! ColumnExists::has('g2g_data_connectors', 'offer_id')) {
                    $table->string('offer_id', 191)->nullable();
                }
                if (! ColumnExists::has('g2g_data_connectors', 'customer_model')) {
                    $table->string('customer_model', 20)->default('b2b');
                }
                if (! ColumnExists::has('g2g_data_connectors', 'region_scope')) {
                    $table->longText('region_scope')->nullable();
                }
                if (! ColumnExists::has('g2g_data_connectors', 'sync_enabled')) {
                    $table->boolean('sync_enabled')->default(true);
                }
                if (! ColumnExists::has('g2g_data_connectors', 'schedule_enabled')) {
                    $table->boolean('schedule_enabled')->default(false);
                }
                if (! ColumnExists::has('g2g_data_connectors', 'schedule_interval_minutes')) {
                    $table->unsignedSmallInteger('schedule_interval_minutes')->default(60);
                }
                if (! ColumnExists::has('g2g_data_connectors', 'schedule_timezone')) {
                    $table->string('schedule_timezone', 64)->default('UTC');
                }
                if (! ColumnExists::has('g2g_data_connectors', 'next_sync_at')) {
                    $table->timestamp('next_sync_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('g2g_data_connector_syncs')) {
            Schema::table('g2g_data_connector_syncs', function (Blueprint $table) {
                if (! ColumnExists::has('g2g_data_connector_syncs', 'records_created')) {
                    $table->unsignedInteger('records_created')->default(0);
                }
                if (! ColumnExists::has('g2g_data_connector_syncs', 'records_duplicate')) {
                    $table->unsignedInteger('records_duplicate')->default(0);
                }
            });
        }

        if (Schema::hasTable('g2g_ingestion_sources')) {
            Schema::table('g2g_ingestion_sources', function (Blueprint $table) {
                if (! ColumnExists::has('g2g_ingestion_sources', 'connector_id')) {
                    $table->unsignedBigInteger('connector_id')->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'connector_sync_id')) {
                    $table->unsignedBigInteger('connector_sync_id')->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'source_platform')) {
                    $table->string('source_platform', 40)->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'external_content_id')) {
                    $table->string('external_content_id', 180)->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'published_at')) {
                    $table->timestamp('published_at')->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'offer_id')) {
                    $table->string('offer_id', 191)->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'customer_model')) {
                    $table->string('customer_model', 20)->nullable();
                }
                if (! ColumnExists::has('g2g_ingestion_sources', 'region_scope')) {
                    $table->longText('region_scope')->nullable();
                }
            });

            if (! $this->hasIndex('g2g_ingestion_sources', 'g2g_ingestion_connector_external')) {
                Schema::table('g2g_ingestion_sources', function (Blueprint $table) {
                    $table->unique(['sub_institute_id', 'connector_id', 'external_content_id'], 'g2g_ingestion_connector_external');
                });
            }
        }

        if (Schema::hasTable('g2g_ingestion_analyses')) {
            Schema::table('g2g_ingestion_analyses', function (Blueprint $table) {
                if (! ColumnExists::has('g2g_ingestion_analyses', 'opportunities_created')) {
                    $table->unsignedInteger('opportunities_created')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('g2g_data_connectors') && DB::table('g2g_data_connectors')->whereNull('url')->exists()) {
            throw new \RuntimeException('Cannot roll back social connectors while any connector has a null URL; resolve those connector rows first.');
        }

        if (Schema::hasTable('g2g_ingestion_analyses')) {
            Schema::table('g2g_ingestion_analyses', function (Blueprint $table) {
                if (ColumnExists::has('g2g_ingestion_analyses', 'opportunities_created')) {
                    $table->dropColumn('opportunities_created');
                }
            });
        }

        if (Schema::hasTable('g2g_ingestion_sources')) {
            if ($this->hasIndex('g2g_ingestion_sources', 'g2g_ingestion_connector_external')) {
                Schema::table('g2g_ingestion_sources', function (Blueprint $table) {
                    $table->dropUnique('g2g_ingestion_connector_external');
                });
            }

            Schema::table('g2g_ingestion_sources', function (Blueprint $table) {
                foreach (['connector_id', 'connector_sync_id', 'source_platform', 'external_content_id', 'published_at', 'offer_id', 'customer_model', 'region_scope'] as $column) {
                    if (ColumnExists::has('g2g_ingestion_sources', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('g2g_data_connector_syncs')) {
            Schema::table('g2g_data_connector_syncs', function (Blueprint $table) {
                foreach (['records_created', 'records_duplicate'] as $column) {
                    if (ColumnExists::has('g2g_data_connector_syncs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('g2g_data_connectors')) {
            Schema::table('g2g_data_connectors', function (Blueprint $table) {
                foreach (['credentials', 'external_account_id', 'account_name', 'offer_id', 'customer_model', 'region_scope', 'sync_enabled', 'schedule_enabled', 'schedule_interval_minutes', 'schedule_timezone', 'next_sync_at'] as $column) {
                    if (ColumnExists::has('g2g_data_connectors', $column)) {
                        $table->dropColumn($column);
                    }
                }
                $table->string('url', 2000)->nullable(false)->change();
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))->contains(fn (array $candidate) => ($candidate['name'] ?? null) === $index);
    }
};
