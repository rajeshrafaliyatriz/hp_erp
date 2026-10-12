<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail, mirroring legacy's `vtiger_potstagehistory` -
 * which tracks amount, stage, probability AND closedate together, not stage
 * alone. A row is written whenever ANY of those four changes, so this needs
 * all four snapshot columns, not just `to_stage`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunity_stage_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunity_id')->index();

            $table->string('from_stage', 100)->nullable();
            $table->string('to_stage', 100)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('probability', 5, 2)->nullable();
            $table->date('closing_date')->nullable();

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_stage_history');
    }
};
