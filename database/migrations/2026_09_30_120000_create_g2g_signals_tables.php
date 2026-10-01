<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * AI Signals Engine storage. Two tables, both tenant-owned by sub_institute_id:
 *
 *  - g2g_signal_runs: one row per generation attempt (scheduled or manual).
 *  - g2g_signals:     the signals themselves. Never deleted by a later run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_signal_runs')) {
            Schema::create('g2g_signal_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->string('trigger', 20);              // scheduled | manual
                $table->string('status', 20);               // running | success | partial | failed | skipped
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->unsignedSmallInteger('signals_generated')->default(0);
                $table->unsignedSmallInteger('signals_duplicate')->default(0);
                $table->unsignedSmallInteger('signals_rejected')->default(0);
                $table->string('provider', 40)->nullable();
                $table->string('model', 120)->nullable();
                $table->string('error_code', 40)->nullable();
                // Safe text only: never a prompt, never organisational records.
                $table->string('error_message', 500)->nullable();
                $table->timestamps();

                $table->index(['sub_institute_id', 'started_at'], 'g2g_signal_runs_tenant_started');
                $table->index(['sub_institute_id', 'status'], 'g2g_signal_runs_tenant_status');
            });
        }

        if (! Schema::hasTable('g2g_signals')) {
            Schema::create('g2g_signals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('department_id')->nullable();   // null = organisation-wide
                $table->unsignedBigInteger('run_id')->nullable();
                $table->string('title', 191);
                $table->string('summary', 500);
                $table->text('explanation');
                $table->string('signal_type', 40);
                $table->text('why_it_matters');
                $table->text('recommended_action');
                $table->string('priority', 10);                            // High | Medium | Low
                $table->json('evidence')->nullable();
                $table->json('sources')->nullable();
                $table->string('origin', 10)->default('internal');         // internal | external | mixed
                $table->char('fingerprint', 64);
                $table->string('status', 10)->default('New');              // New | Reviewed | Dismissed
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('generated_at')->useCurrent();
                $table->timestamps();

                $table->index(['sub_institute_id', 'status', 'priority'], 'g2g_signals_tenant_status_priority');
                $table->index(['sub_institute_id', 'department_id'], 'g2g_signals_tenant_department');
                $table->index(['sub_institute_id', 'fingerprint', 'generated_at'], 'g2g_signals_tenant_fingerprint');
                $table->index(['sub_institute_id', 'generated_at'], 'g2g_signals_tenant_generated');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_signals');
        Schema::dropIfExists('g2g_signal_runs');
    }
};
