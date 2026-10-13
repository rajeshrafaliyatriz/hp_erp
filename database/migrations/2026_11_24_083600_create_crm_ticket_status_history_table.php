<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket status-change audit trail - same bespoke, inline-written pattern
 * as every other CRM status-history table (crm_opportunity_stage_history,
 * crm_sales_order_status_history, etc.), continuing the established
 * precedent of NOT using the generic `g2g_event`/EventRecorder mechanism
 * (real, but used by a different subsystem - Task Management/Platform
 * Workflow/Talent - never by CRM). Column names are `from_status`/
 * `to_status` (matching the ticket's own real column name, `status`) -
 * not `from_stage`/`to_stage`, which is specific to the order-to-cash
 * entities whose tracked column is literally named "stage".
 *
 * Replaces legacy's `update_log` denormalized text-blob approach
 * entirely - see crm_tickets' own migration docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_ticket_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ticket_id')->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('ticket_id')->references('id')->on('crm_tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_ticket_status_history');
    }
};
