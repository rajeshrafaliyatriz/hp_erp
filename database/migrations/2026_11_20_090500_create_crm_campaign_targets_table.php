<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One polymorphic pivot replacing the legacy's 3 separate junction tables
 * (vtiger_campaignleadrel/campaigncontrel/campaignaccountrel).
 *
 * `response_status` is the legacy vtiger_campaignrelstatus values
 * (--None--, Contacted - Successful, Contacted - Unsuccessful, Contacted -
 * Never Contact Again), confirmed as a mutable current-state field, not an
 * append-only history log - if an auditable status-change history is wanted
 * later, that is new functionality, not a port of existing behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_campaign_targets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('campaign_id');
            $table->string('target_type', 20); // lead|contact|organization
            $table->unsignedBigInteger('target_id');
            $table->string('response_status', 30)->default('none');
            $table->timestamp('created_at')->nullable();

            $table->foreign('campaign_id')->references('id')->on('crm_campaigns')->cascadeOnDelete();
            $table->unique(['campaign_id', 'target_type', 'target_id'], 'crm_campaign_targets_unique');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaign_targets');
    }
};
