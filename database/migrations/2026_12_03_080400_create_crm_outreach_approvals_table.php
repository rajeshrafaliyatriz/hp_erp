<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 CRM Automation — Core Revenue Loop:
 * Approval queue for outbound messages. Before a campaign message is sent to
 * a specific target, a row is created here with status=pending. A reviewer
 * approves or rejects; only approved rows may proceed to send, and the
 * CrmOutreachApprovalController::markSent() finalises them.
 *
 * This enables the .md requirement: "Keep human approval for outbound messages
 * and consequential actions until a workflow is measured, reliable and
 * explicitly approved."
 *
 * Status lifecycle: pending → approved|rejected → (approved only) → sent
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_080400_create_crm_outreach_approvals_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_080400_create_crm_outreach_approvals_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_outreach_approvals')) {
            return;
        }

        Schema::create('crm_outreach_approvals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('target_row_id')->nullable();  // crm_campaign_targets.id
            $table->string('target_type', 20)->nullable();            // lead|contact|organization
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('subject', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('channel', 30)->default('email');
            $table->string('status', 20)->default('pending');         // pending|approved|rejected|sent
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('campaign_id')->references('id')->on('crm_campaigns')->cascadeOnDelete();
            $table->foreign('target_row_id')->references('id')->on('crm_campaign_targets')->nullOnDelete();

            $table->index('campaign_id', 'crm_outreach_approvals_campaign_idx');
            $table->index('status', 'crm_outreach_approvals_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_outreach_approvals');
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
};
