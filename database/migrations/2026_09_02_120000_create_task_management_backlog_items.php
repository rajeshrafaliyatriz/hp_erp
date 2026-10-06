<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A backlog: work written down before it has an owner.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_02_120000_create_task_management_backlog_items.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_02_120000_create_task_management_backlog_items.php
 *
 * ── WHY A NEW TABLE AND NOT A TASK STATE ────────────────────────────────────
 *
 * The obvious shortcut is a `task` row with status DRAFT and no assignee. Both
 * halves of that are impossible here, measured rather than assumed:
 *
 *   - `TaskOptionSetService::CATEGORIES` is a CLOSED set — PENDING, IN-PROGRESS,
 *     ON HOLD, COMPLETED. A tenant's custom statuses must each map onto one of
 *     those four, so DRAFT cannot be invented.
 *   - ZERO of the 2,801 task rows across every tenant have a null
 *     `task_allocated_to`. "Unassigned" is not a state this product has ever
 *     expressed, and introducing it would hand a row with no owner to every
 *     board, report, approval path and notification that has never seen one.
 *
 * A backlog item is also a genuinely different thing: it has no assignee, no
 * observer, no due date and no completion, and it exists precisely so that
 * somebody can write "post on social media" without answering any of those
 * questions yet.
 *
 * ── WHY IT CARRIES ITS OWN TENANCY ──────────────────────────────────────────
 *
 * The workstream child tables deliberately carry no `sub_institute_id`, because
 * tenancy is inherited by joining up to the project. This table cannot follow
 * that rule: `project_id` is NULLABLE by design — an item captured on the task
 * dashboard has no project yet — so an unfiled row would have no parent to
 * inherit from and therefore no tenant boundary at all. It is a root aggregate
 * and holds `sub_institute_id` + `syear` itself.
 *
 * ── LIVE CONSTRAINTS ────────────────────────────────────────────────────────
 *
 * Live is MariaDB 10.1.48, InnoDB, ROW_FORMAT=Compact.
 *
 *   - NO json column. Live carries zero and has no native JSON type.
 *   - Every index is named EXPLICITLY and kept short. Laravel's generated name
 *     for the project foreign key would be
 *     `task_management_backlog_items_project_id_foreign` — 47 characters here,
 *     which fits, but the same generator has already produced a 66-character
 *     name elsewhere in this module and failed outright. Naming them is the
 *     habit, not the exception.
 *   - ROW_FORMAT=Compact caps an index prefix at 767 bytes. Nothing below
 *     indexes a VARCHAR(191); the widest key is 8 + 50 + 4 = 62 bytes.
 *   - `type`, `priority` and `status` are VARCHAR + a controller const, never
 *     ENUM: adding a value later would otherwise mean an ALTER TABLE rebuild.
 *
 * ── RANK IS SPARSE, ON PURPOSE ──────────────────────────────────────────────
 *
 * `rank` is seeded in steps of 1000 rather than 1, 2, 3. Dropping an item
 * between two others then writes the midpoint of its neighbours — ONE row, one
 * UPDATE. A dense rank would have to renumber every row between the source and
 * the target on every single drag, which on a forty-item backlog is forty
 * writes to move one card. The controller renormalises a list only when a gap
 * closes below 2, which after 1000-step seeding takes about ten drops into the
 * same slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_management_backlog_items')) {
            return;
        }

        Schema::create('task_management_backlog_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Root aggregate: an unfiled item has no parent to inherit from.
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('syear', 50);

            // Both optional. An idea can be captured before anybody knows where
            // it belongs, and deleting a project must not delete the idea — so
            // these null out rather than cascade.
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('workstream_id')->nullable();

            // The only thing required to write something down.
            $table->string('title', 255);
            $table->text('notes')->nullable();

            /*
             * Domain-neutral, because this has to read for a property or
             * clinical team as well as an engineering one. Story / Epic / Bug
             * are software words and would make the field meaningless to half
             * the tenants. Each of these completes the sentence "this is…":
             * something new · something broken · something that could be better
             * · something somebody asked for · housekeeping.
             */
            $table->string('type', 20)->default('REQUEST');       // NEW|FIX|IMPROVE|REQUEST|ADMIN
            $table->string('priority', 20)->default('Medium');    // High|Medium|Low — the existing vocabulary
            $table->string('status', 20)->default('OPEN');        // OPEN|ASSIGNED|DONE|DROPPED

            $table->integer('rank')->default(0);

            // Which task this became. Deliberately NOT a foreign key: the legacy
            // `task` table carries none of its own, and a hard constraint here
            // would make deleting a task fail because an old note points at it.
            $table->unsignedBigInteger('task_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_institute_id', 'syear'], 'tm_backlog_tenant_idx');
            $table->index(['project_id'], 'tm_backlog_project_idx');
            $table->index(['sub_institute_id', 'syear', 'rank'], 'tm_backlog_rank_idx');

            $table->foreign('project_id', 'tm_backlog_project_fk')
                ->references('id')->on('task_management_projects')->nullOnDelete();
            $table->foreign('workstream_id', 'tm_backlog_ws_fk')
                ->references('id')->on('task_management_workstreams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_management_backlog_items');
    }
};
