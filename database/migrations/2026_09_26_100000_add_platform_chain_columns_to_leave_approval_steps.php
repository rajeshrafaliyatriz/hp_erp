<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a leave approval step describe the chain it was frozen from.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THE STEP HAS TO CARRY ITS OWN RULE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `openFor()` freezes the chain at submission, deliberately: if HR turns the
 * department head off tomorrow, requests already in flight keep the chain they
 * entered under, because changing the rules must not retroactively approve or
 * strand anything.
 *
 * That freeze used to be complete, because the whole chain was three booleans on
 * `hrms_leave_workflow_settings` and a step only had to remember which of three
 * roles it was. A platform chain is richer — a step has a name, an SLA, a breach
 * action, and possibly a named person — and, unlike the settings row, a platform
 * chain CAN BE DELETED. A step that resolved its rule by looking the chain up
 * would lose its rule the moment somebody tidied the console, and a request in
 * flight would become undecidable.
 *
 * So the rule travels on the row. Every column below is a copy taken at submit
 * time and never refreshed.
 *
 * ── `approver_role` IS STILL WRITTEN FOR EVERY STEP ─────────────────────────
 *
 * Including for a named-person step. `LeaveRequestApiController` reads the chain
 * as `array_column($this->workflow->stepsFor($id), 'approver_role')` in two
 * places, and a null there would silently produce a chain with holes in it. A
 * named-person step records the person's own role alongside the user id, so that
 * column keeps meaning what it has always meant.
 *
 * Strictly additive and idempotent. Existing rows keep working untouched:
 * everything added here is nullable, and `source` defaults to the settings path
 * that every existing row came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hrms_leave_approval_steps')) {
            return;
        }

        Schema::table('hrms_leave_approval_steps', function (Blueprint $table) {
            /*
             * A step that names one person rather than a role.
             *
             * NULL is the normal case and means "whoever holds approver_role".
             * When set, only that person may decide the step - see roleMayDecide().
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'approver_user_id')) {
                $table->unsignedBigInteger('approver_user_id')->nullable()->after('approver_role');
            }

            /*
             * The step's own label, e.g. "Head of department sign-off".
             *
             * The settings path has no names - its steps are just roles - so this
             * stays null there and the UI falls back to the role, as it does today.
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'step_name')) {
                $table->string('step_name', 120)->nullable()->after('approver_user_id');
            }

            /*
             * Per-step SLA, in hours. 0 or NULL means this step has no deadline.
             *
             * The settings path has ONE tenant-wide escalation_time instead, so
             * these stay null there and escalateOverdue() falls back to it.
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'sla_hours')) {
                $table->unsignedSmallInteger('sla_hours')->nullable()->after('step_name');
            }

            /*
             * What happens when that SLA passes: none | remind | escalate |
             * auto_approve | auto_reject.
             *
             * The last two DECIDE THE REQUEST WITH NOBODY HAVING READ IT, which is
             * a capability this product did not previously have - escalation used
             * to widen who may decide and never decide anything itself. They are
             * only ever reachable from a platform chain that explicitly asks for
             * them, and never from the settings path.
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'on_breach')) {
                $table->string('on_breach', 20)->nullable()->after('sla_hours');
            }

            /*
             * Which configuration produced this step.
             *
             * 'settings'  hrms_leave_workflow_settings - every row that exists today
             * 'platform'  a g2g_platform_workflows chain
             *
             * Not cosmetic: escalateOverdue() uses it to decide whether a step's
             * deadline comes from its own sla_hours or from the tenant-wide
             * setting, and the timeline uses it to explain where a rule came from.
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'source')) {
                $table->string('source', 20)->default('settings')->after('on_breach');
            }

            /*
             * The chain this step was frozen from, for provenance only.
             *
             * Deliberately NOT a foreign key: the chain may be deleted while a
             * request that came from it is still in flight, and that must not
             * cascade. A dangling id here is expected and means "the chain this
             * came from is gone", which is worth being able to say.
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'workflow_id')) {
                $table->unsignedBigInteger('workflow_id')->nullable()->after('source');
            }

            /*
             * Set when the SLA sweeper decided this step itself.
             *
             * An auto-decision must never be mistakable for a human one. The
             * existing `approver_id` / `approver_name` stay NULL for these - nobody
             * decided it - and this column carries the reason, so the timeline can
             * say "approved automatically: no response within 48h" rather than
             * showing an approval with no approver.
             */
            if (! self::hasColumn('hrms_leave_approval_steps', 'auto_decided_reason')) {
                $table->string('auto_decided_reason', 191)->nullable()->after('decided_at');
            }
        });

        /*
         * The sweeper's index.
         *
         * It now scans pending steps by their own sla_hours as well as by
         * pending_since, and the existing (status, pending_since) index cannot
         * serve that on its own.
         */
        if (! self::hasIndex('hrms_leave_approval_steps', 'hrms_leave_steps_sla_index')) {
            Schema::table('hrms_leave_approval_steps', function (Blueprint $table) {
                $table->index(['status', 'sla_hours', 'pending_since'], 'hrms_leave_steps_sla_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hrms_leave_approval_steps')) {
            return;
        }

        if (self::hasIndex('hrms_leave_approval_steps', 'hrms_leave_steps_sla_index')) {
            Schema::table('hrms_leave_approval_steps', function (Blueprint $table) {
                $table->dropIndex('hrms_leave_steps_sla_index');
            });
        }

        foreach ([
            'approver_user_id', 'step_name', 'sla_hours', 'on_breach',
            'source', 'workflow_id', 'auto_decided_reason',
        ] as $column) {
            if (self::hasColumn('hrms_leave_approval_steps', $column)) {
                Schema::table('hrms_leave_approval_steps', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Whether an index exists, without relying on `doctrine/dbal`.
     *
     * `Schema::hasIndex()` needs DBAL on this Laravel line. SHOW INDEX is the
     * portable answer and cannot fail on a missing information_schema column.
     */
    private static function hasIndex(string $table, string $index): bool
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::select(
                'SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?',
                [$index]
            );

            return $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a column exists, on ANY MySQL or MariaDB version.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * WHY NOT `Schema::hasColumn()`
     * ═══════════════════════════════════════════════════════════════════════
     *
     * It compiles to a SELECT against `information_schema.columns` that asks for
     * `generation_expression` — a column MariaDB only gained in 10.2. On an older
     * engine the introspection query itself errors before it can answer anything:
     *
     *   SQLSTATE[42S22]: 1054 Unknown column 'generation_expression' in 'field list'
     *
     * This application already carries `LegacyMariaDbSchemaGrammar` for exactly
     * that, registered on the `ConnectionEstablished` event in `AppServiceProvider`.
     * It did not save this migration: the connection is established during console
     * bootstrapping, before the provider's `boot()` has attached the listener, so
     * the override is registered too late for the very first connection — which is
     * the one a migration runs on.
     *
     * A migration cannot depend on a listener having been attached in time. This
     * asks information_schema the same question naming ONLY `column_name`, which
     * every version has, so it behaves identically on 10.1 and on 10.11.
     *
     * ── NOT `SHOW COLUMNS ... LIKE ?` ───────────────────────────────────────
     *
     * The obvious alternative, and it does not work: MariaDB rejects a placeholder
     * in a SHOW statement's LIKE clause with a 1064 syntax error. Verified against
     * a real 10.1.48 server rather than assumed — the first version of this fix
     * used it and was broken in exactly the situation it was written for.
     */
    private static function hasColumn(string $table, string $column): bool
    {
        try {
            $rows = \Illuminate\Support\Facades\DB::select(
                'select column_name from information_schema.columns '
                . 'where table_schema = database() and table_name = ? and column_name = ?',
                [$table, $column]
            );

            return $rows !== [];
        } catch (\Throwable) {
            // A missing table answers "no column", which is what the guards above
            // want — they are already skipped by the hasTable() check.
            return false;
        }
    }
};
