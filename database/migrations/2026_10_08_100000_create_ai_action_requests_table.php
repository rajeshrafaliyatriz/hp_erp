<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval ledger for actions the assistant proposes from a page.
 *
 * One row per proposal. The browser executes the action through the application's own API
 * clients (so the requester's permissions apply), but only after an approver has said yes,
 * and the row records every step so the lifecycle is never inferred:
 *
 *   pending -> approved -> executing -> completed | failed
 *   pending -> rejected | cancelled
 *
 * Tenant-scoped by sub_institute_id. SAFE TO RE-RUN: the create is guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_action_requests')) {
            return;
        }

        Schema::create('ai_action_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('module_key', 120)->nullable()->index();
            $table->string('action_key', 120);
            $table->unsignedBigInteger('requested_by')->index();
            $table->longText('payload');
            $table->longText('preview')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 2000)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->longText('result')->nullable();
            $table->timestamps();

            $table->index(['sub_institute_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_requests');
    }
};
