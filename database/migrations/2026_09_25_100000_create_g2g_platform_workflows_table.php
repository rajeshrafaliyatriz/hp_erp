<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval chains, per tenant, against the workflow points declared in
 * `config/platform_services.php`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THE NAME IS `g2g_platform_workflows` AND NOT `platform_workflows`
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This application already has four unrelated things called "workflow":
 *
 *   talent_workflows              Talent's own approval config
 *   hrms_leave_workflow_settings  Leave's, with an hourly escalation job
 *   agentic_workflows             the agent runtime's step graphs
 *   s_competency_mapping_reviews  Competency's approval path, by another name
 *
 * LMS K-12 has the same problem with three, and its own audit flags it. A fifth called
 * `platform_workflows` would be one more name nobody can disambiguate at a glance.
 * `g2g_` is this codebase's established prefix for cross-cutting platform services —
 * `g2g_event`, `g2g_audit_log`, `g2g_notification` — and it says exactly what this is.
 *
 * ── THIS TABLE IS CONFIGURATION, NOT STATE ──────────────────────────────────
 *
 * A chain defined here is a rule. An approval actually in flight is not stored here,
 * and must not be: mixing "what the rule is" with "where this particular request has
 * got to" is what makes a rule impossible to change without rewriting history.
 *
 * ── `flow_key` IS NOT UNIQUE, DELIBERATELY ──────────────────────────────────
 *
 * Several chains at one point is the design: "leave over 5 days" and "leave under 5
 * days" are two chains on `hrms.leave.approval`, chosen between by `condition`. A
 * unique index here would make that impossible and would look like a safety measure.
 *
 * ── `condition` IS STORED VERBATIM AND NEVER EVALUATED HERE ─────────────────
 *
 * It is free text the engine interprets. Nothing in this layer parses it, and nothing
 * anywhere should `eval` it. Empty means the chain always applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_platform_workflows')) {
            return;
        }

        Schema::create('g2g_platform_workflows', function (Blueprint $table) {
            $table->bigIncrements('id');

            // NOT NULL. This table holds every tenant's approval rules, so it is the
            // worst possible place to allow an unscoped row. Resolved from the caller's
            // identity at write time, never from request input.
            $table->unsignedBigInteger('sub_institute_id');

            // `module.component.flow`, validated against config('platform_services.workflows').
            $table->string('flow_key', 191);
            // Derived from flow_key at write time so the common filters need no parsing.
            $table->string('module', 64);
            $table->string('component', 128);

            $table->string('name', 191);
            $table->text('description')->nullable();

            // draft | active | disabled. A chain starts as a draft so experimenting with
            // one does not begin intercepting real records the moment it is saved.
            $table->string('status', 16)->default('draft');

            $table->string('condition', 255)->default('');

            /*
             * The ladder, as JSON text.
             *
             * A child table was considered and rejected: a chain is read and written
             * whole, no query wants one step of one chain, and a child table would buy
             * joins nobody needs in exchange for ordering bugs.
             *
             * ── `longText`, NOT `json` ──────────────────────────────────────────
             *
             * `$table->json()` emits a native JSON column, and MariaDB only gained
             * that type in 10.2. One of the deployments this application runs on is
             * older, and the CREATE fails outright there:
             *
             *   SQLSTATE[42000]: 1064 ... near 'json not null, `on_reject` ...'
             *
             * MariaDB's JSON type is an alias for LONGTEXT with a validation
             * constraint, so this is the same storage on a modern server and the only
             * thing that works on an old one. Nothing is lost: the column is written
             * with json_encode() and read with json_decode() by hand, never through a
             * JSON path query.
             */
            $table->longText('steps');

            $table->string('on_reject', 32)->default('return_to_requester');
            $table->boolean('notify_requester')->default(true);

            // Denormalised "Name (id)" so the record survives a rename or a
            // deactivation. An id alone stops resolving the day somebody leaves.
            $table->string('created_by', 191)->nullable();
            $table->string('updated_by', 191)->nullable();

            $table->timestamps();

            $table->index(['sub_institute_id', 'flow_key'], 'g2g_pw_tenant_flow_idx');
            $table->index(['sub_institute_id', 'module'], 'g2g_pw_tenant_module_idx');
            $table->index(['sub_institute_id', 'status'], 'g2g_pw_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_platform_workflows');
    }
};
