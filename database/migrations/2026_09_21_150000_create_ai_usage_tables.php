<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metering and quotas for Usage & Cost.
 *
 * ── WHY THIS IS NEEDED AND WHY IT WAS MISSING ──────────────────────────────
 *
 * `AiModelClient` already knows everything worth recording: which module asked,
 * which provider and model answered, how many tokens each way, how long it took, and
 * whether it failed. Until this migration all of that was thrown away the moment the
 * call returned. The Usage & Cost panel read `hpbrain_ai_executions`, a table another
 * application writes, so seven real model calls made through this layer produced a
 * spend report of zero.
 *
 * That is worse than an empty screen: it is a meter that reads zero while money is
 * being spent, and an administrator would reasonably conclude nothing was running.
 *
 * ── WHY A TABLE OF ITS OWN RATHER THAN `hpbrain_ai_executions` ─────────────
 *
 * Same reason conversations got their own: that table belongs to another application,
 * keys on a `tenant_id` string with UUID ids, and nothing here can add a column to it
 * safely. Its nine existing rows stay readable — the panel reports their count beside
 * ours rather than pretending they do not exist — but this layer meters into a table
 * it owns.
 *
 * ── WHY THE COST COLUMN IS NULLABLE AND USUALLY NULL ───────────────────────
 *
 * Cost is computed from `ai_models.input_cost_per_1k` / `output_cost_per_1k`, and
 * those are null for every seeded row because the migration that seeded them declined
 * to assert vendor pricing. So `estimated_cost_usd` is null until somebody fills in a
 * rate, and the screen shows tokens with a blank money column. A guessed rate on a
 * screen an administrator uses to explain an invoice is worse than no rate at all.
 *
 * ── QUOTAS REFUSE BEFORE THE CALL, NOT AFTER ───────────────────────────────
 *
 * A quota checked after a request has already been sent is an accounting entry, not a
 * limit. `AiUsageMeter::guard()` runs before `AiModelClient` reaches the network, so
 * "a runaway agent hits a quota instead of an invoice" is literally what happens.
 *
 * SAFE TO RE-RUN. Both creates are guarded.
 *
 *   php artisan migrate --path=database/migrations/2026_09_21_150000_create_ai_usage_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createUsageEvents();
        $this->createQuotas();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_quotas');
        Schema::dropIfExists('ai_usage_events');
    }

    /** One row per model call, successful or not. */
    private function createUsageEvents(): void
    {
        if (Schema::hasTable('ai_usage_events')) {
            return;
        }

        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();

            // Which AI module asked — an `AiModuleRegistry` key. This is the dimension
            // that makes the bill attributable: "what did Assessment AI cost" is
            // unanswerable without it, and it is the first question asked.
            $table->string('ai_module', 64)->index();
            $table->string('provider', 40)->index();
            $table->string('model', 120)->nullable();

            // Which precedence step resolved the credential — `module`, `pool`, `env`
            // and so on. Kept because "why did this call go to that provider" is the
            // second question, and re-deriving it later reads the configuration as it
            // is now rather than as it was then.
            $table->string('source', 24)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();

            // Six decimals: a single call is routinely a fraction of a cent.
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();

            // `success` | `failed` | `refused`. Failures are metered too: a provider
            // that rejects a request has usually still charged for the prompt, and a
            // meter that counts only successes under-reports exactly when something is
            // going wrong.
            $table->string('outcome', 16)->default('success')->index();
            $table->string('finish_reason', 40)->nullable();
            $table->text('error')->nullable();

            // What the call was for, when the caller knows. A conversation id, an
            // evaluation id. Deliberately loose: this table must never be the reason a
            // new caller needs a migration.
            $table->string('related_type', 80)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamps();

            // The two reads this table exists to serve: spend for an organisation over
            // a period, and spend for one module over a period.
            $table->index(['sub_institute_id', 'created_at'], 'ai_usage_tenant_time_idx');
            $table->index(['sub_institute_id', 'ai_module', 'created_at'], 'ai_usage_tenant_module_time_idx');
        });
    }

    /**
     * A token ceiling per organisation per period.
     *
     * One row per (organisation, period, module) with a null module meaning "the whole
     * organisation". That shape lets a general cap and a tighter per-module cap
     * coexist, and `AiUsageMeter` applies the narrowest that matches — the same
     * precedence every other part of this feature uses.
     */
    private function createQuotas(): void
    {
        if (Schema::hasTable('ai_usage_quotas')) {
            return;
        }

        Schema::create('ai_usage_quotas', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sub_institute_id')->index();
            // NULL = applies to every module in the organisation.
            $table->string('ai_module', 64)->nullable()->index();

            // `day` | `month`. Not an arbitrary window: a quota somebody has to reason
            // about in rolling hours is a quota nobody sets correctly.
            $table->string('period', 16)->default('month');
            $table->unsignedBigInteger('token_limit');

            // Warn before refusing. A cap that goes from silent to hard-stop with no
            // intermediate state takes a feature down without notice; this is the
            // percentage at which the screen starts saying so.
            $table->unsignedTinyInteger('warn_at_percent')->default(80);

            $table->boolean('status')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['sub_institute_id', 'ai_module', 'period'], 'ai_usage_quotas_scope_unique');
        });
    }
};
