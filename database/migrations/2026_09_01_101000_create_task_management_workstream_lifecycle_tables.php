<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The eight tables a workstream needs to be a plan rather than a label.
 *
 * ── THE NINE FIELDS, AND WHERE EACH ONE LANDS ───────────────────────────────
 *
 *   1 Purpose               workstreams.purpose          (previous migration)
 *   2 Primary contributors  ..._members                  (+ per-person `lane`)
 *   3 Responsibilities      ..._statements  RESPONSIBILITY
 *   4 Primary deliverables  ..._deliverables
 *   5 Timeline/milestones   ..._checkpoints  + deliverables.checkpoint_id
 *                           + the workstream's own start_date/due_date
 *   6 Dependencies          ..._links (workstream->workstream)
 *                           + ..._dependencies (external, free text)
 *   7 Success metrics       ..._kpis
 *   8 Scope boundaries      ..._statements  IN_SCOPE / OUT_OF_SCOPE
 *   9 Risks & mitigations   ..._risks
 *
 * ── TABLE-VS-COLUMN, AND WHY NOT ONE POLYMORPHIC TABLE ──────────────────────
 *
 * A thing earns a table when it has its own state, its own owner, or must be
 * counted and filtered in SQL. All six record types qualify.
 *
 * The tempting shortcut is one `..._items` table with a `kind` and a payload
 * column. It is rejected: a KPI has target/unit/direction, a risk has
 * probability/impact/mitigation, a deliverable has acceptance criteria, a
 * checkpoint has a gate date. Merged, the table is ~70% NULL and inevitably grows
 * a JSON payload column — and on this project a `json` column is the single most
 * repeated failure, because live is MariaDB 10.1.48 which has no native JSON type
 * and currently carries zero of them. Narrow tables stay queryable.
 *
 * Responsibilities and scope share one table because they ARE structurally
 * identical — an ordered list of one-line statements. They differ only in `kind`,
 * which is exactly what a discriminator is for. The alternative, three
 * LONGTEXT-JSON columns, would make every edit a read-modify-write over the whole
 * list: the same shape as the destructive project-task sync this release is
 * removing.
 *
 * ── NO TENANT COLUMNS, DELIBERATELY ─────────────────────────────────────────
 *
 * Not one of these tables carries `sub_institute_id` or `syear`. Tenancy is
 * inherited workstream -> project -> task_management_projects, exactly as
 * task_management_project_members and task_management_project_departments already
 * do, and the latter's migration states the reason: a tenant column here would be
 * a second place for it to be wrong.
 *
 * The consequence is that isolation depends ENTIRELY on every query joining up to
 * the project. `ResolvesWorkstreamScope::workstreamScope()` is the one helper that
 * does it, and it is the only thing standing between a guessed id and another
 * organisation's plan.
 *
 * ── IDENTIFIER LENGTH IS A REAL CONSTRAINT HERE ─────────────────────────────
 *
 * MySQL caps identifiers at 64 characters. Left to Laravel, the foreign key on
 * the links table would be named
 *
 *   task_management_workstream_links_predecessor_workstream_id_foreign
 *
 * which is 66 characters and fails outright. Every index and constraint below is
 * therefore named explicitly and kept short.
 *
 * Index WIDTH matters too: live creates tables with ROW_FORMAT=Compact, capping
 * an index prefix at 767 bytes. The widest key here is tm_ws_link_unique at
 * 8 + 8 + 80 = 96 bytes. Nothing indexes a VARCHAR(191).
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_09_01_101000_create_task_management_workstream_lifecycle_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_01_101000_create_task_management_workstream_lifecycle_tables.php
 */
return new class extends Migration
{
    private const PARENT = 'task_management_workstreams';

    public function up(): void
    {
        if (! $this->tableExists(self::PARENT)) {
            return;
        }

        /*
         * 2 — PRIMARY CONTRIBUTORS.
         *
         * A clone of task_management_project_members, which is the house pattern
         * for "one accountable owner as a scalar on the parent, many contributors
         * in a join table". `workstreams.owner_id` remains THE accountable person
         * and is kept in step with this table by the controller.
         *
         * `lane` carries what the source model calls technical ownership — "Backend,
         * APIs, database, integrations". It exists because the model is explicit
         * that Frontend / Backend / AI must NOT become three workstreams:
         *
         *   "do not create Frontend, Backend and AI as three independent
         *    workstreams for such a small team. They are technical lanes inside
         *    one delivery workstream."
         *
         * Without somewhere to record a lane, the pressure to split WS02 into
         * three returns immediately.
         */
        if (! $this->tableExists('task_management_workstream_members')) {
            Schema::create('task_management_workstream_members', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->unsignedBigInteger('user_id')->index('tm_ws_member_user_idx');
                $t->string('role', 30)->default('CONTRIBUTOR');
                $t->string('lane', 191)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();

                $t->unique(['workstream_id', 'user_id'], 'tm_ws_member_unique');
                $t->foreign('workstream_id', 'tm_ws_member_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }

        /*
         * 3 + 8 — RESPONSIBILITIES AND SCOPE BOUNDARIES.
         *
         * `kind` is RESPONSIBILITY | IN_SCOPE | OUT_OF_SCOPE.
         *
         * OUT_OF_SCOPE is the one the source model singles out — "explicit lists
         * of what is in-scope and, critically, what is out-of-scope to prevent
         * scope creep" — so it is a first-class kind, not an afterthought, and the
         * UI gives it equal width.
         *
         * VARCHAR(500) rather than TEXT: these are single statements, and a bounded
         * column keeps the list a list rather than becoming a second description
         * field.
         */
        if (! $this->tableExists('task_management_workstream_statements')) {
            Schema::create('task_management_workstream_statements', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->string('kind', 20);
                $t->string('body', 500);
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['workstream_id', 'kind', 'sort_order'], 'tm_ws_stmt_idx');
                $t->foreign('workstream_id', 'tm_ws_stmt_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }

        /*
         * 5 — CRITICAL CHECKPOINTS.
         *
         * Created BEFORE deliverables, which reference it.
         *
         * The source model asks for "start dates, target completion dates, and
         * critical checkpoints for major deliverables" — three distinct things.
         * The first two are the workstream's own start_date/due_date, now made
         * editable. This table is the third.
         *
         * A checkpoint is NOT a project milestone. A milestone is a project-level
         * event that other workstreams and dependencies hang off;a checkpoint is
         * internal to one workstream and usually gates one deliverable. Reusing
         * task_management_milestones would have meant every internal gate showing
         * up on the project's milestone rail, which is why that table has stayed
         * empty of workstream rows.
         */
        if (! $this->tableExists('task_management_workstream_checkpoints')) {
            Schema::create('task_management_workstream_checkpoints', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->string('name', 191);
                $t->text('description')->nullable();
                $t->date('target_date')->nullable();
                $t->string('status', 30)->default('UPCOMING');
                // "CRITICAL checkpoints" is the model's own word. A flag rather
                // than a separate table: it is one boolean per checkpoint.
                $t->boolean('is_critical')->default(false);
                $t->date('completed_at')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['workstream_id', 'target_date'], 'tm_ws_chk_idx');
                $t->foreign('workstream_id', 'tm_ws_chk_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }

        /*
         * 4 — PRIMARY DELIVERABLES.
         *
         * Full records, not a checklist: each carries its own status, owner,
         * dates and acceptance criteria, which is what lets a workstream report
         * "3 of 5 delivered" and lets the roll-up compute progress honestly.
         *
         * `checkpoint_id` is nullOnDelete — removing a gate must not delete the
         * work it was gating. `milestone_id` gets an index but NO foreign key,
         * matching task_management_milestones, which has no foreign keys at all;
         * the controller validates it instead.
         */
        if (! $this->tableExists('task_management_workstream_deliverables')) {
            Schema::create('task_management_workstream_deliverables', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->unsignedBigInteger('checkpoint_id')->nullable();
                $t->unsignedBigInteger('milestone_id')->nullable()->index('tm_ws_deliv_ms_idx');
                $t->string('name', 191);
                $t->text('description')->nullable();
                $t->text('acceptance_criteria')->nullable();
                $t->unsignedBigInteger('owner_id')->nullable()->index('tm_ws_deliv_owner_idx');
                $t->string('status', 30)->default('NOT STARTED');
                $t->date('due_date')->nullable();
                $t->date('delivered_at')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['workstream_id', 'status'], 'tm_ws_deliv_idx');
                $t->foreign('workstream_id', 'tm_ws_deliv_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
                $t->foreign('checkpoint_id', 'tm_ws_deliv_chk_fk')
                    ->references('id')->on('task_management_workstream_checkpoints')->nullOnDelete();
            });
        }

        /*
         * 7 — SUCCESS METRICS (KPIs).
         *
         * Shape harvested from s_performance_goals, semantics from
         * tenant_readiness_gate. Two decisions carry the weight:
         *
         * VALUES ARE STRINGS WITH A SEPARATE `unit`, deliberately not numeric.
         * The model's own examples are "15% reduction in latency" and "100 units
         * produced"; a DECIMAL column cannot hold the first, and "Zero P1
         * incidents" is a legitimate target that is not a number at all. This is
         * the same call s_performance_goals made, for the same reason.
         *
         * `current_value IS NULL` MEANS NEVER MEASURED, NOT ZERO, and `status`
         * starts UNMEASURED for exactly that reason. An unmeasured KPI is not
         * off-track — it is unmeasured, which is a third state. The competency gap
         * engine already draws this distinction (met | gap | unmeasured, where an
         * unmeasured item is NOT counted as a shortfall) and the readiness gates
         * render a null value as "not yet computed" rather than 0.
         *
         * `direction` is what lets on-track be judged without forcing every metric
         * to be bigger-is-better: latency going DOWN is success.
         */
        if (! $this->tableExists('task_management_workstream_kpis')) {
            Schema::create('task_management_workstream_kpis', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->string('name', 191);
                $t->string('metric', 191)->nullable();
                $t->string('unit', 30)->nullable();
                $t->string('direction', 10)->default('UP');
                $t->string('baseline_value', 100)->nullable();
                $t->string('target_value', 100)->nullable();
                $t->string('current_value', 100)->nullable();
                $t->date('measured_at')->nullable();
                $t->string('status', 20)->default('UNMEASURED');
                $t->decimal('weightage', 5, 2)->default(0);
                $t->string('source', 191)->nullable();
                $t->unsignedBigInteger('owner_id')->nullable()->index('tm_ws_kpi_owner_idx');
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['workstream_id', 'status'], 'tm_ws_kpi_idx');
                $t->foreign('workstream_id', 'tm_ws_kpi_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }

        /*
         * 9 — RISKS & MITIGATIONS.
         *
         * Column shape harvested from the hpbrain_risks design (probability /
         * impact / derived score / mitigation / status) but written to nothing but
         * this table: hpbrain is a separate product sharing the database, and the
         * standing decision is to build natively and keep integration API-only.
         *
         * `probability` is Low|Medium|High. `impact` and `severity` reuse
         * TaskExecutionClassifier::RISK_CLASSES — Low|Medium|High|Regulated — so
         * the frontend renders them with the existing RISK_STYLE map instead of
         * inventing a second risk palette that would drift from the first.
         *
         * `severity` is STORED, computed by the controller from a probability x
         * impact matrix, not derived at read time — so the register can ORDER BY
         * it in SQL and the roll-up can count "high or above" in one grouped query.
         */
        if (! $this->tableExists('task_management_workstream_risks')) {
            Schema::create('task_management_workstream_risks', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->string('title', 191);
                $t->text('description')->nullable();
                $t->string('category', 30)->nullable();
                $t->string('probability', 10)->default('Medium');
                $t->string('impact', 10)->default('Medium');
                $t->string('severity', 10)->default('Medium');
                $t->text('mitigation')->nullable();
                $t->text('contingency')->nullable();
                $t->unsignedBigInteger('owner_id')->nullable()->index('tm_ws_risk_owner_idx');
                $t->string('status', 20)->default('OPEN');
                $t->date('due_date')->nullable();
                $t->date('closed_at')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['workstream_id', 'status'], 'tm_ws_risk_idx');
                $t->index(['workstream_id', 'severity'], 'tm_ws_risk_sev_idx');
                $t->foreign('workstream_id', 'tm_ws_risk_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }

        /*
         * 6a — EXTERNAL DEPENDENCIES.
         *
         * The model defines dependencies as "upstream requirements (what this team
         * needs before they can start) and downstream impacts (who is waiting on
         * this workstream's output)".
         *
         * Much of that is NOT another workstream. It is a customer sign-off, a
         * vendor API key, a budget approval, a decision from a committee. Modelling
         * dependencies only as workstream-to-workstream edges would silently drop
         * the half that most often blocks a team, so this table carries the free
         * text kind and ..._links carries the graph kind. The UI shows both
         * together under one heading, because to the reader they are one question.
         */
        if (! $this->tableExists('task_management_workstream_dependencies')) {
            Schema::create('task_management_workstream_dependencies', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('workstream_id');
                $t->string('direction', 20)->default('UPSTREAM');
                $t->string('description', 500);
                // Who or what it comes from / goes to. Free text on purpose: a
                // vendor or a committee is not a row in this database.
                $t->string('source', 191)->nullable();
                $t->date('needed_by')->nullable();
                $t->string('status', 20)->default('OPEN');
                $t->boolean('is_blocking')->default(false);
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['workstream_id', 'direction'], 'tm_ws_dep_idx');
                $t->foreign('workstream_id', 'tm_ws_dep_ws_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }

        /*
         * 6b — THE WORKSTREAM GRAPH. This is what makes the lifecycle diagram data
         * rather than a picture drawn in JSX.
         *
         * `link_type`:
         *   FLOW      the delivery chain      WS01->WS02, WS02->WS04
         *   FEEDBACK  the loop                WS04->WS01
         *   GOVERNS   the horizontal layer    WS03->WS01, WS03->WS02, WS03->WS04
         *
         * GOVERNS is the customer's decision expressed as data: WS03 coordinates
         * the others rather than being a fourth stage between them.
         *
         * `label` carries the model's own edge captions — "WHAT + WHY", "WORKING
         * PRODUCT", "USER FEEDBACK", "Scope", "Delivery", "Release" — so the
         * diagram reads them instead of hardcoding them.
         *
         * CYCLES ARE REFUSED FOR FLOW ONLY. WS04 -> WS01 as FEEDBACK is the entire
         * point of a 360 model; a validator that refused every cycle would make the
         * customer's own diagram unrepresentable.
         *
         * `project_id` is denormalised onto the row so both ends can be validated
         * as belonging to one project in a single place, and the whole graph
         * fetched with one indexed query rather than two joins per edge.
         */
        if (! $this->tableExists('task_management_workstream_links')) {
            Schema::create('task_management_workstream_links', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('project_id');
                $t->unsignedBigInteger('predecessor_workstream_id');
                $t->unsignedBigInteger('successor_workstream_id');
                $t->string('link_type', 20)->default('FLOW');
                $t->string('label', 100)->nullable();
                $t->text('note')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();

                $t->unique(
                    ['predecessor_workstream_id', 'successor_workstream_id', 'link_type'],
                    'tm_ws_link_unique'
                );
                $t->index(['project_id', 'link_type'], 'tm_ws_link_project_idx');

                // NAMED, and not optionally. Laravel would generate
                // task_management_workstream_links_predecessor_workstream_id_foreign
                // at 66 characters, over MySQL's 64-char identifier limit.
                $t->foreign('predecessor_workstream_id', 'tm_ws_link_pred_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
                $t->foreign('successor_workstream_id', 'tm_ws_link_succ_fk')
                    ->references('id')->on(self::PARENT)->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Reverse creation order: deliverables reference checkpoints.
        foreach ([
            'task_management_workstream_links',
            'task_management_workstream_dependencies',
            'task_management_workstream_risks',
            'task_management_workstream_kpis',
            'task_management_workstream_deliverables',
            'task_management_workstream_checkpoints',
            'task_management_workstream_statements',
            'task_management_workstream_members',
        ] as $table) {
            if ($this->tableExists($table)) {
                Schema::drop($table);
            }
        }
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue. */
    private function tableExists(string $table): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->c ?? 0) > 0;
    }
};
