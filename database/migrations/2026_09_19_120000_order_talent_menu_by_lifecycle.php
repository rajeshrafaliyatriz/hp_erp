<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put Talent Management's sub-modules in the order a person actually moves
 * through them: hire -> grow -> exit, with settings last.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * The sidebar sorts on `tblmenumaster_g2g.sort_order` ASC (tblmenumasterG2gController
 * @index), and that column had drifted into three separate problems:
 *
 *   1. Employee Profiles, Certifications and Development & Career Paths were
 *      moved into Talent on 2026-08-18 and CARRIED THEIR OLD COMPETENCY NUMBERS
 *      (10, 11, 12) across. So the four screens a user needs most - who the
 *      people are, how they are growing, what they have earned - sat after
 *      Administration, which is settings.
 *   2. Slots 5 and 6 were holes left by disabled rows, and three pairs collided
 *      on the same number (3, 4 and 8 each used twice) with no tiebreaker - so
 *      those positions were decided by whatever order MySQL happened to return.
 *   3. The two databases disagreed: the app host had Certifications at 12 and
 *      Development at 13, the live host had 11 and 12.
 *
 * ── WHY THIS MIGRATION HAS TO EXIST ─────────────────────────────────────────
 *
 * The 2026-08-18 move was applied as a hand-run UPDATE with only its UNDO
 * committed (_local-backups/REVERSAL-menu-move-*.sql). There is no forward
 * script anywhere in the repo, so a fresh database reproduces neither the move
 * nor the ordering - it shows seven sub-modules instead of eleven. This is that
 * missing forward step, written so it is replayable and identical on both hosts.
 *
 * ── SAFE TO RE-RUN ──────────────────────────────────────────────────────────
 *
 * Keyed on menu id and scoped to parent_id = 3. Rows that are not present are
 * skipped rather than created: this migration orders what exists, it does not
 * invent menu rows, and it never touches `status` - a row disabled on purpose
 * stays disabled, it just holds a sensible number if it is ever switched on.
 */
return new class extends Migration
{
    /**
     * The lifecycle. Disabled rows are included so that enabling one later does
     * not drop it into a gap or a tie.
     *
     * @var array<int, int> menu id => sort_order
     */
    private const ORDER = [
        46  => 1,   // Talent Dashboard
        47  => 2,   // Recruitment
        48  => 3,   // Onboarding
        156 => 4,   // Employee Profiles
        49  => 5,   // Performance Reviews & Appraisals
        157 => 6,   // Development & Career Paths
        158 => 7,   // Certifications
        303 => 8,   // Capability Progress
        52  => 9,   // Mobility & Succession
        171 => 10,  // Offboarding
        178 => 11,  // Administration
        // Disabled today, numbered so they cannot collide if re-enabled.
        50  => 12,  // Compensation
        179 => 13,  // Talent Onboarding Dashboard
        180 => 14,  // Talent Onboarding
        198 => 15,  // HR Template Engine
        169 => 16,  // Employee Onboarding (duplicate door, closed separately)
    ];

    private const PARENT = 3;

    public function up(): void
    {
        foreach (self::ORDER as $menuId => $sortOrder) {
            DB::table('tblmenumaster_g2g')
                ->where('id', $menuId)
                ->where('parent_id', self::PARENT)
                ->update(['sort_order' => $sortOrder]);
        }
    }

    /**
     * Restores the numbering this migration replaced, as measured on both hosts
     * before it ran. Not a guess: read from tblmenumaster_g2g on 2026-09-19.
     *
     * The app and live hosts disagreed on three rows; the app host's values are
     * used, because that is the one the application connects to by default.
     */
    public function down(): void
    {
        $previous = [
            46 => 1, 47 => 2, 48 => 3, 179 => 3, 49 => 4, 180 => 4, 50 => 5,
            52 => 7, 171 => 8, 198 => 8, 178 => 9, 156 => 10, 158 => 12,
            157 => 13, 303 => 14,
        ];

        foreach ($previous as $menuId => $sortOrder) {
            DB::table('tblmenumaster_g2g')
                ->where('id', $menuId)
                ->where('parent_id', self::PARENT)
                ->update(['sort_order' => $sortOrder]);
        }
    }
};
