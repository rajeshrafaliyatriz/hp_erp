<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_data_connectors')) {
            Schema::create('g2g_data_connectors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('type', 40);
                $table->string('name', 191);
                $table->string('url', 2000)->nullable();
                $table->string('product_key', 40);
                $table->string('connection_status', 20)->default('not_configured');
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('last_successful_sync_at')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['sub_institute_id', 'connection_status'], 'g2g_connector_tenant_status');
            });
        }

        if (! Schema::hasTable('g2g_data_connector_syncs')) {
            Schema::create('g2g_data_connector_syncs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('connector_id');
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('status', 20);
                $table->unsignedInteger('records_fetched')->default(0);
                $table->unsignedInteger('records_processed')->default(0);
                $table->unsignedInteger('records_skipped')->default(0);
                $table->unsignedInteger('records_failed')->default(0);
                $table->string('error_message', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['sub_institute_id', 'connector_id', 'id'], 'g2g_connector_sync_tenant_connector');
                $table->index(['sub_institute_id', 'status'], 'g2g_connector_sync_tenant_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_data_connector_syncs');
        Schema::dropIfExists('g2g_data_connectors');
    }
};
