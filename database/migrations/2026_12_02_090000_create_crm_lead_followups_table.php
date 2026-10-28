<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CRM lead automation, Step 2: the lead follow-up cadence.
 *
 *   php artisan migrate --path=database/migrations/2026_12_02_090000_create_crm_lead_followups_table.php
 *
 * One row per lead that has entered a follow-up cadence. This is the ONE piece of state the existing tables cannot hold:
 * "which attempt are we on, when is the next one due, and why did it stop". Everything else a cadence produces lands in
 * existing places (crm_emails + crm_email_links, task_management_calendar_events, crm_lead_comments, crm_leads.lead_status).
 *
 * UNIQUE (lead_id): a lead is in at most one cadence, ever - a retried or duplicated event cannot start a second one.
 * `locked_until` is the claim: a worker takes a due row by moving it forward atomically, so two overlapping ticks never
 * send the same attempt twice.
 *
 * Intervals, attempt limit and business hours are copied onto the row from the workflow action that started it, so editing
 * the workflow later never changes a cadence that is already running.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if ($this->has('crm_lead_followups')) {
            return;
        }
        Schema::create('crm_lead_followups', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('workflow_id')->nullable();
            $t->unsignedBigInteger('workflow_run_id')->nullable();
            $t->string('state', 12)->default('active');           // active | paused | replied | completed | stopped
            $t->string('stop_reason', 40)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);       // emails PREPARED so far
            $t->unsignedTinyInteger('max_attempts');
            $t->string('intervals', 100);                          // days to wait after attempt 1, 2, ... e.g. "2,4,7"
            $t->char('window_start', 5)->default('09:00');
            $t->char('window_end', 5)->default('17:00');
            $t->string('timezone', 64);
            $t->boolean('weekdays_only')->default(true);
            $t->string('subject_template', 255);
            $t->text('body_template');
            $t->unsignedBigInteger('last_email_id')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('next_due_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->unique('lead_id', 'crm_lead_followups_lead_unique');
            $t->index(['state', 'next_due_at'], 'crm_lead_followups_due_idx');
            $t->index('sub_institute_id', 'crm_lead_followups_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_followups');
    }
};
