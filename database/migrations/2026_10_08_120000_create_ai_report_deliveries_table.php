<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (report, recipient) send attempt. Additive and guarded.
 *
 * `dedupe_key` is the double-submit guard: it is claimed (unique) BEFORE the mail is handed to
 * the transport, and cleared when the attempt fails so a retry is allowed.
 * `recipient_email` is a snapshot - the address the mail was actually addressed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_report_deliveries')) {
            return;
        }

        Schema::create('ai_report_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->string('recipient_email', 190);
            $table->string('recipient_name', 190)->nullable();
            $table->string('channel', 20)->default('email');
            $table->string('status', 12)->default('queued'); // queued | sent | failed
            $table->text('error')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('dedupe_key', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['report_id', 'sub_institute_id'], 'ai_rd_report_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_report_deliveries');
    }
};
