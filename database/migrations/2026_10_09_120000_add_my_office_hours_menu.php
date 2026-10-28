<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "My Office Hours" - the employee's own way to ask for different working days.
 *
 * ── WHY ITS OWN MENU ROW AND NOT A TAB ON THE HR DESK ───────────────────────
 *
 * Manage Employee Attendance (433) is gated `profile:admin,hr`. A tab on it
 * cannot be the employee's screen, because a module right cannot express
 * "mine" - granting the module so an employee could propose their own hours
 * would also hand them every other employee's attendance and the correction
 * controls. That is the same reasoning that gave My Certifications, My
 * Learning, My Tasks, My HR and My Department Documents each their own row
 * over their own subject-less endpoint, and it is written up fullest in
 * 2026_09_30_110000.
 *
 * The matching endpoint takes no user id at all: EmployeeScheduleRequestController
 * ::store() reads `$context['user_id']` and nothing else, so there is no
 * parameter an employee could point at somebody else.
 *
 * ── ID 434 IS PINNED, AND WHY THAT IS NOT OPTIONAL HERE ─────────────────────
 *
 * `content-map-m5.ts` carries a `submenuId` on every entry as the fallback used
 * when a row's `access_link` is blank - unlike content-map-m1.ts, where the
 * field does not appear at all. An auto-increment id would therefore differ
 * between the two hosts and that fallback would resolve to a different screen
 * on each. 433 was pinned for Manage Employee Attendance for this same reason.
 *
 * 434 was measured free on BOTH hosts before this was written, not assumed:
 *
 *   select max(id) from tblmenumaster_g2g;              -- 433 on both
 *   select id, menu_name from tblmenumaster_g2g
 *     where id between 430 and 450;                     -- 434-450 free on both
 *
 * ── SORT ORDER 6, APPENDED, WITH NO SHIFT ───────────────────────────────────
 *
 * `sort_order` under parent 93 is NOT unique and already collides on both
 * hosts - 309 and 162 both sit at 3, 163 and 433 both sit at 4. So the
 * shift-then-insert dance the other menu migrations do (increment every
 * sibling at or past the target) would be reordering a list whose order is
 * already ambiguous, for no gain. Appending at 6, one past the current
 * maximum, is deterministic on both hosts and touches no existing row.
 *
 * What an employee actually sees under Attendance Management is two items -
 * Attendance Tracking and this - because the reports between them are granted
 * to admin/hr only. The position reads worse in this table than it does on
 * screen.
 *
 * Idempotent on `access_link`, and down() deletes only a 434 that carries
 * THIS link - never somebody else's 434.
 *
 * Reversal: Docs/hrit-audit/_reversals/REVERSAL-2026-10-09-my-office-hours-menu.sql
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_09_120000_add_my_office_hours_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_120000_add_my_office_hours_menu.php
 */
return new class extends Migration
{
    private const ID = 434;
    private const LINK = '/module/hrit-solutions/attendance-management/my-office-hours';
    /** Attendance Management, the branch Attendance Tracking already lives in. */
    private const PARENT_ID = 93;

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->exists();

        if ($existing) {
            return;
        }

        if (DB::table('tblmenumaster_g2g')->where('id', self::ID)->exists()) {
            /*
             * 434 was free on both hosts when this was written. If it is taken
             * now, something else claimed it - stop rather than insert at an
             * auto-increment id, which would make the content map's submenuId
             * fallback point somewhere different on each host.
             */
            throw new RuntimeException(
                'Menu id ' . self::ID . ' is already taken by another row. '
                . 'Pick a new free id on BOTH hosts and update content-map-m5.ts to match.'
            );
        }

        $parent = DB::table('tblmenumaster_g2g')->where('id', self::PARENT_ID)->first();

        if (!$parent) {
            // Attendance Management itself is missing here - do not guess at a
            // level or a parent to land under.
            return;
        }

        DB::table('tblmenumaster_g2g')->insert([
            'id'               => self::ID,
            'menu_name'        => 'My Office Hours',
            'parent_id'        => self::PARENT_ID,
            'level'            => (int) $parent->level + 1,
            'access_link'      => self::LINK,
            'icon'             => 'mdi mdi-clock-edit-outline',
            'status'           => 1,
            // One past the current maximum under this parent. See the docblock -
            // sort_order already collides here, so no sibling is shifted.
            'sort_order'       => 1 + (int) DB::table('tblmenumaster_g2g')
                ->where('parent_id', self::PARENT_ID)->max('sort_order'),
            'sub_institute_id' => null,
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
            ->where('access_link', self::LINK)   // never delete somebody else's 434
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
