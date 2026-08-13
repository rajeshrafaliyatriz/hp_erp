<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * X-07a — `tenant_readiness_gate`. (`05-data-flow-contracts.md` §4)
 *
 * CREATES ONE TABLE. Alters nothing, drops nothing, and SEEDS NO ROWS.
 *
 * NO PRE-SEEDED STATE, BY DECISION. Rows are written by the recomputer for
 * whatever tenants it actually computes. A gate row inserted at migration time
 * would assert a state nobody measured - the seventh instance of the principle
 * that the system does not manufacture a claim nobody made. An absent row means
 * "never computed", which is true and legible; a row defaulted to `blocked`
 * would be indistinguishable from a real measurement that came out blocked.
 *
 * FIVE GATES, NOT SIX. `task_competency_link` IS DROPPED and gets no row ever.
 * Its map keys to `s_jobrole_task`, a GLOBAL seed library with no tenant column,
 * so it cannot be joined to `task` - the tenant's own work items - at all.
 * Gating a tenant's features on a global table's contents is meaningless: the
 * wrong-population error, appearing in a gate instead of a measurement. Golden
 * thread 2 does not need it; the instance link (`task.skill_id`) is the real
 * signal, and L-14 established that the catalogue link must be AUTHORED rather
 * than derived. If a gate is ever wanted there it gates on the authored
 * catalogue, which is X-08's territory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_readiness_gate', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            // reporting_coverage | task_hygiene | capability_coverage |
            // jobrole_definition | course_mapping
            $table->string('gate_key', 48);

            $table->enum('state', ['blocked', 'at_risk', 'ready'])->default('blocked');

            // ── UNIT. THE COLUMN §4 DID NOT HAVE, AND THE REASON IT IS HERE ──
            //
            // `jobrole_definition` is a COUNT, not a percentage. §4 gave it
            // 70%/55% thresholds without naming a population - there is no
            // "expected number of job roles" for an organisation, so no
            // denominator exists and inventing one would be this product
            // deciding a customer's standard for them.
            //
            // §4's 70%/55% for this gate are SUPERSEDED, not silently replaced:
            // enable at >= 10 roles defined, disable below 5, both
            // tenant-configurable, because a 12-person company and a hospital
            // group are not the same organisation.
            $table->enum('unit', ['percent', 'count'])->default('percent');

            // NULL = NEVER COMPUTED. Not zero. A zero here is a measurement that
            // came out zero, which is a different claim.
            $table->decimal('value', 10, 2)->nullable();

            // Hysteresis: enable and disable differ so a metric on the boundary
            // cannot flap. Per row, so a tenant can carry its own standard.
            $table->decimal('enable_threshold', 10, 2);
            $table->decimal('disable_threshold', 10, 2);

            // A gate enables only after passing this many consecutive recomputes,
            // so a mid-import spike does not switch a feature on.
            $table->unsignedTinyInteger('sustained_periods')->default(3);
            $table->unsignedTinyInteger('consecutive_passes')->default(0);

            // ── ASYMMETRIC SWITCHING (§4.1). ON may be automatic; OFF never is.
            // ready -> below threshold enters at_risk and the feature STAYS ON.
            // Disable requires the warning period to elapse AND an explicit
            // acknowledgement. These three columns are what makes that
            // enforceable rather than a convention.
            $table->dateTime('at_risk_since')->nullable();
            $table->unsignedTinyInteger('warning_days')->default(14);
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();

            // A feature disabled this way always states why. The remedy is shown
            // to the admin with the metric and its value.
            $table->string('remedy', 255)->nullable();

            $table->dateTime('computed_at')->nullable();
            $table->timestamps();

            // One row per gate per tenant. The recomputer upserts on this.
            $table->unique(['sub_institute_id', 'gate_key'], 'uniq_trg_tenant_gate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_readiness_gate');
    }
};
