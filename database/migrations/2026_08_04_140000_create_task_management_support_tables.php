<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data stores for the task-management routes that were declared but never
 * given controllers or tables.
 *
 * routes/api.php has carried endpoints for comments, notifications, subtasks,
 * time tracking, templates, recurrence, attachment versions and audit logs
 * since the module was written - all 500ing because neither the controller
 * nor the table behind them ever existed. These tables follow the naming and
 * shape of the task_management_* tables that do exist (projects, workstreams,
 * dependencies, milestones): plain bigint ids, sub_institute_id tenancy, no
 * foreign key constraints (the legacy `task` table has none either).
 *
 * task_deadline_extensions backs the old frontend's deadline-extension
 * request/approve flow, which posted to /api/deadline-extension - an endpoint
 * that never existed in this repo. The flow is real (executor requests, the
 * observer approves or rejects); only the backend was missing.
 */
return new class extends Migration
{
    /** Skips tables a previously interrupted run already created. */
    private function createIfMissing(string $table, \Closure $definition): void
    {
        if (!Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    public function up(): void
    {
        $this->createIfMissing('task_management_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('author_id');
            $table->text('content');
            $table->timestamps();
            $table->index(['task_id', 'sub_institute_id']);
        });

        $this->createIfMissing('task_management_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');
            $table->string('event', 50);
            $table->unsignedBigInteger('actor_id')->nullable();
            // The pre-change values that matter for "who changed what".
            $table->json('before')->nullable();
            $table->timestamp('created_at');
            $table->index(['task_id']);
            $table->index(['sub_institute_id', 'created_at']);
        });

        $this->createIfMissing('task_management_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('type', 50);
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            // Named: the auto-generated name exceeds MySQL's 64-char limit.
            $table->index(['user_id', 'sub_institute_id', 'read_at'], 'tm_notifications_user_tenant_read_idx');
        });

        $this->createIfMissing('task_management_subtasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');
            $table->string('title');
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'sub_institute_id']);
        });

        $this->createIfMissing('task_management_time_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Denormalised on stop, so reports never re-derive durations.
            $table->unsignedInteger('minutes')->nullable();
            $table->timestamp('created_at');
            $table->index(['task_id', 'sub_institute_id']);
            $table->index(['user_id', 'ended_at']);
        });

        $this->createIfMissing('task_management_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('name');
            // The task fields the template pre-fills, as submitted.
            $table->json('payload');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index('sub_institute_id');
        });

        $this->createIfMissing('task_management_recurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id')->unique();
            $table->string('frequency', 20); // daily | weekly | monthly
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('until')->nullable();
            $table->timestamps();
        });

        $this->createIfMissing('task_management_attachment_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedInteger('version');
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_type', 100)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            // Set when this version was created by restoring an older one.
            $table->unsignedInteger('restored_from')->nullable();
            $table->timestamp('created_at');
            $table->unique(['task_id', 'version']);
        });

        $this->createIfMissing('task_management_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('idempotency_key', 100);
            $table->unsignedBigInteger('task_id');
            $table->timestamp('created_at');
            // Same key, same tenant -> same task, never a duplicate.
            // Named: the auto-generated name exceeds MySQL's 64-char limit.
            $table->unique(['sub_institute_id', 'idempotency_key'], 'tm_idem_tenant_key_unique');
        });

        $this->ensureNotificationsIndex();

        $this->createIfMissing('task_deadline_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('requested_by');
            $table->date('requested_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_on')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'status']);
            $table->index(['sub_institute_id', 'status']);
        });
    }

    /** Heals the half-created state an interrupted earlier run left behind. */
    private function ensureNotificationsIndex(): void
    {
        if (!Schema::hasTable('task_management_notifications')) {
            return;
        }

        $exists = collect(Schema::getIndexes('task_management_notifications'))
            ->contains(fn (array $index) => $index['name'] === 'tm_notifications_user_tenant_read_idx');

        if (!$exists) {
            Schema::table('task_management_notifications', function (Blueprint $table) {
                $table->index(['user_id', 'sub_institute_id', 'read_at'], 'tm_notifications_user_tenant_read_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_deadline_extensions');
        Schema::dropIfExists('task_management_idempotency_keys');
        Schema::dropIfExists('task_management_attachment_versions');
        Schema::dropIfExists('task_management_recurrences');
        Schema::dropIfExists('task_management_templates');
        Schema::dropIfExists('task_management_time_entries');
        Schema::dropIfExists('task_management_subtasks');
        Schema::dropIfExists('task_management_notifications');
        Schema::dropIfExists('task_management_audit_logs');
        Schema::dropIfExists('task_management_comments');
    }
};
