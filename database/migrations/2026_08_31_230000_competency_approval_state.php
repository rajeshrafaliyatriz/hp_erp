<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the Competency Library a real approval state, and repair two status columns
 * that were written past their own API.
 *
 * ── 1. `competency.approve_status` — THE COLUMN THE WORKFLOW NEEDED ─────────
 *
 * ApprovalController has a complete submit/review workflow, and for
 * subject_type='competency' it targets `s_users_skills`. The Competency Library
 * screen was moved onto the `competency` table. Nobody moved the workflow.
 *
 * The two tables' ids overlap completely — `competency` runs 21-447,
 * `s_users_skills` runs 1-5458 — so a submission from the Library would have set
 * `approve_status` on an unrelated skill row that merely shared an id. It writes
 * a real value to a real row belonging to somebody else's record.
 *
 * That has never fired, and only by luck: the Library's "Submit for Approval"
 * button is gated on `statusLabel(...) !== 'Pending'`, and a display bug made
 * every competency read Pending, so the button has been permanently invisible.
 * Two bugs cancelling out. Fixing the display bug ALONE would have unblocked the
 * corruption, which is why the column, the repoint and the display fix are one
 * change.
 *
 * NULL is the honest default. It means "never submitted", which is a different
 * fact from Approved and from Pending, and none of the 231/232 existing rows has
 * been through this workflow. Backfilling them to 'Approved' would record 231
 * decisions nobody made.
 *
 * ── 2. `status = '1'` — SEEDED PAST THE API, ON BOTH DATABASES ──────────────
 *
 * `s_competency_frameworks.status` is validated `in:draft,active,archived` by
 * FrameworkController and defaults to 'draft', so nothing reachable can produce
 * '1'. Nine of tenant 6's frameworks carry it anyway (ids 342-349, 354), on both
 * databases — a seeding script wrote straight to the table.
 *
 * The cost lands on the showcase tenant: CommandCenterService and
 * StudioController both filter `where('status','active')`, so tenant 6's Command
 * Center reads "Active Frameworks: 0" while nine exist, and `active_framework` —
 * the anchor of the whole Framework & Role Mapping screen — resolves to null.
 *
 * '1' becomes 'active' because that is what a boolean-true status meant to
 * whoever wrote it, and because these are the tenant's live frameworks, not
 * drafts. One `competency` row on live carries the same '1' and is corrected the
 * same way.
 *
 * ── 3. THE ORPHANED APPROVAL ────────────────────────────────────────────────
 *
 * Live holds one pending approval: subject_type='competency', subject_id=5832,
 * 'test8', submitted by kalpesh sheth on 2026-08-04 and never actionable because
 * the screen that reviews it has never been reachable. Its subject is an
 * `s_users_skills` row; there is no `competency` named 'test8' on either database.
 *
 * Once the workflow points at `competency`, that row would resolve against the
 * wrong table. It is retyped rather than deleted — a real person really did
 * submit it, and update() already answers 422 "Unknown approval subject" for an
 * unrecognised type, so it stays visible as history and cannot be actioned into
 * the wrong record.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_230000_competency_approval_state.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_230000_competency_approval_state.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('competency') && ! $this->columnExists('competency', 'approve_status')) {
            // Raw DDL rather than Schema::table(): the live server is MariaDB
            // 10.1.48, where the schema builder's introspection throws.
            DB::statement("ALTER TABLE `competency`
                ADD COLUMN `approve_status` VARCHAR(20) NULL DEFAULT NULL AFTER `status`");
        }

        if ($this->tableExists('competency')) {
            DB::table('competency')->where('status', '1')->update(['status' => 'active']);
        }

        if ($this->tableExists('s_competency_frameworks')) {
            DB::table('s_competency_frameworks')->where('status', '1')->update(['status' => 'active']);
        }

        if ($this->tableExists('s_competency_approvals')) {
            /*
             * Only rows whose subject_id cannot be a competency. Matched by
             * absence in the target table rather than by the known id 5832, so a
             * database that never had that row is untouched and one that has
             * others is fully covered.
             */
            $orphans = DB::table('s_competency_approvals as a')
                ->leftJoin('competency as c', 'c.id', '=', 'a.subject_id')
                ->where('a.subject_type', 'competency')
                ->whereNull('c.id')
                ->pluck('a.id');

            if ($orphans->isNotEmpty()) {
                DB::table('s_competency_approvals')
                    ->whereIn('id', $orphans)
                    ->update(['subject_type' => 'competency_legacy', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if ($this->tableExists('s_competency_approvals')) {
            DB::table('s_competency_approvals')
                ->where('subject_type', 'competency_legacy')
                ->update(['subject_type' => 'competency', 'updated_at' => now()]);
        }

        if ($this->tableExists('competency') && $this->columnExists('competency', 'approve_status')) {
            DB::statement('ALTER TABLE `competency` DROP COLUMN `approve_status`');
        }

        /*
         * The status values are NOT reverted. '1' was never a legal value of
         * either column — the API that owns them has always rejected it — so
         * restoring it would mean deliberately reintroducing data that breaks
         * the screens reading it. Rolling back leaves them correct.
         */
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue directly. */
    private function tableExists(string $table): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }
};
