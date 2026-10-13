<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket comments - replaces legacy's two parallel, simultaneously-live
 * comment mechanisms (a per-module `vtiger_ticketcomments` single-textarea
 * table, plus the generic threaded `vtiger_modcomments` widget bolted on
 * later) with one bespoke, flat table. No threading/replies (legacy's
 * ModComments `parent_comments` reply chain is intentionally not
 * replicated - a flatter, simpler mechanism than legacy strictly needs for
 * this app, matching this app's own existing comment-table precedent,
 * `task_management_comments`, which is also flat).
 *
 * No soft-deletes - append-only, same reasoning as every CRM status-
 * history table in this app (crm_opportunity_stage_history, etc.): a
 * comment carries nothing worth "undeleting," a remove is a real delete.
 *
 * `sub_institute_id` is denormalized onto this table (not just reachable
 * via a join through `ticket_id`) so a tenant-scoped query never needs to
 * join - matching `task_management_comments`' own exact shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_ticket_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->text('comment');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('crm_tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_ticket_comments');
    }
};
