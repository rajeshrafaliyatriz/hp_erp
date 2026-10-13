<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The HR attendance desk gets its own sidebar entry.
 *
 * ── WHY A SEPARATE LEAF AND NOT A TAB ON ATTENDANCE TRACKING ────────────────
 *
 * Attendance Tracking (menu 100) is an employee's OWN screen: punch in, punch
 * out, see my month. The request was for the admin's screen to be separate from
 * it, and that is the right shape for a reason beyond preference - a tab cannot
 * express ownership. Everyone who can open Tracking sees their own data; this
 * screen shows everybody's and writes to it. One menu row, one grant, one
 * answer to "who can change somebody's hours".
 *
 * It is also how the self-service split was settled everywhere else in this
 * module (see Docs/hrit-audit on My Leave Requests vs Leave Approvals): a
 * separate menu row plus an endpoint that resolves its own subject.
 *
 * ── ID 433, AND WHY NOT THE OBVIOUS ONE ─────────────────────────────────────
 *
 * The next free id on 202.47.117.220 is 312. On 128.199.17.97 that id is TAKEN
 * by "Platform Services". This migration guards on the id, so pinning 312 would
 * have inserted on one host and silently skipped on the other, leaving the menu
 * missing on live with nothing in the output to say so. 433 was measured free on
 * both before this was written:
 *
 *   select id, menu_name from tblmenumaster_g2g where id = 433;   -- empty, both
 *
 * ── THE NAME ────────────────────────────────────────────────────────────────
 *
 * "Manage Employee Attendance", not "Attendance Management" - the PARENT is
 * already called that, and a leaf repeating its parent tells a first-time user
 * nothing. It also has to be distinguishable at a glance from its two siblings,
 * which are "Attendance Tracking" (mine) and "Attendance Reports" (read-only).
 * The verb is the distinction: this is the one that changes things.
 *
 * Rights: 2026_10_07_110100_grant_manage_employee_attendance_rights.php
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-07-attendance-admin-menu.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_07_110000_add_manage_employee_attendance_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_07_110000_add_manage_employee_attendance_menu.php
 */
return new class extends Migration
{
    private const ID        = 433;
    private const PARENT_ID = 93;     // Attendance Management
    private const LINK      = '/module/hrit-solutions/attendance-management/manage-employee-attendance';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // Idempotent on BOTH the id and the link: the id guard stops a re-run,
        // the link guard stops a second row appearing if somebody creates this
        // screen through the UI on one host first.
        $taken = DB::table('tblmenumaster_g2g')
            ->where('id', self::ID)
            ->orWhere('access_link', self::LINK)
            ->exists();

        if ($taken) {
            return;
        }

        $parent = DB::table('tblmenumaster_g2g')->where('id', self::PARENT_ID)->first();

        if (!$parent) {
            // A leaf whose parent does not exist never enters the sidebar -
            // displaySidebarMenu walks down from the module root. Better to
            // insert nothing than an orphan nobody can see or find.
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'               => self::ID,
            'menu_name'        => 'Manage Employee Attendance',
            'parent_id'        => self::PARENT_ID,
            'level'            => 3,            // matches its three siblings
            'page_type'        => 'page',
            'access_link'      => self::LINK,
            'icon'             => '',
            'status'           => 1,
            'sort_order'       => 4,            // after Monthly Attendance Report (3)
            // NULL, like every sibling under 93. The menu tree is shared; it is
            // tblgroupwise_rights_g2g that decides who sees a row, per tenant.
            'sub_institute_id' => null,
            'menu_type'        => '',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', self::ID)
            ->where('access_link', self::LINK)   // never delete somebody else's 433
            ->delete();
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
