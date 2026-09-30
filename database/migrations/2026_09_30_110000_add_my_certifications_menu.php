<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the employee's own certifications a way in.
 *
 * ── WHY A MENU ROW AND NOT A TAB ────────────────────────────────────────────
 *
 * The Certification & Compliance Center already has a "My Certifications" tab.
 * Its filter is a `user_id` sent from the browser over an endpoint that accepts
 * any id it is handed, so the tab is a convenience and not a boundary - and the
 * screen it sits on can create, edit, verify, revoke and delete anyone's
 * records. An administrator therefore had no correct choice: grant the module
 * so an employee can see their own certificate and they can also edit a
 * colleague's; withhold it and they cannot see their own at all.
 *
 * A module right cannot distinguish "mine" from "everyone's". Only a separate
 * row over a separate endpoint can, which is why My Learning, My Assessment,
 * My Tasks and My HR are all separate rows rather than tabs.
 *
 * ── WHERE IT GOES ───────────────────────────────────────────────────────────
 *
 * Beside Certifications (id 158) under Talent Management, because that is this
 * product's pattern: every "My X" lives in the module X belongs to - My HR
 * under HR, My Tasks under Task Management, My Learning under LMS - not in one
 * shared self-service cluster.
 *
 * ── WHY THE SORT ORDERS SHIFT ───────────────────────────────────────────────
 *
 * displaySidebarMenu orders by `sort_order` ALONE, with no tie-breaker
 * (tblmenumasterG2gController:283). Two rows sharing a sort_order therefore
 * appear in whatever order MySQL happens to return - the same unordered-query
 * class of bug as F-151 in that very file. Parent 3's positions 1-16 are all
 * taken, so landing next to Certifications means moving the rows after it down
 * by one rather than creating a tie. Display order only; fully reversed by
 * down().
 *
 * ── ID 401 IS PINNED, AND HERE THAT IS NOT OPTIONAL ─────────────────────────
 *
 * The two databases are at DIFFERENT max ids - app 367, live 337 - so an
 * auto-increment would give this screen a different id on each, and the content
 * map's submenuId fallback would then point at different screens per
 * environment. 401 is free on both and above both. The idempotency key is
 * `access_link`; the id is pinned so the fallback cannot drift.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_09_30_110000_add_my_certifications_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110000_add_my_certifications_menu.php
 */
return new class extends Migration
{
    private const ID     = 401;
    private const LINK   = '/module/talent-management/my-certifications';
    private const PARENT = 3;    // Talent Management
    private const AFTER  = 158;  // Certifications - the admin screen this pairs with

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // Idempotent on the LINK, not the id: a row for this screen under any
        // id means the page is already in the menu, and a second would show it
        // twice. This guard also protects the sort_order shift below from
        // running more than once.
        if (DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->exists()) {
            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('id', self::AFTER)->first();

        // Inherited rather than hardcoded, so this lands in the right place
        // even if the Talent branch has been rearranged.
        $parentId = $sibling->parent_id ?? self::PARENT;
        $level    = $sibling->level ?? 2;
        $position = (int) ($sibling->sort_order ?? 7) + 1;

        /*
         * ONE TRANSACTION. Making room and inserting are two statements, and
         * run bare they can half-apply: the sibling migration 110400 hit a
         * duplicate id on insert, the shift stayed, and Talent's children ran
         * 1..8 then jumped to 11 on one database and not the other. Nothing
         * looked broken - a gap sorts the same as no gap - the damage was that
         * the two environments stopped agreeing. Either both happen or neither.
         */
        DB::transaction(function () use ($parentId, $level, $position) {
            // Everything at or after the target position moves down one.
            DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('sort_order', '>=', $position)
                ->increment('sort_order');

            DB::table('tblmenumaster_g2g')->insert([
                'id'               => self::ID,
                'menu_name'        => 'My Certifications',
                'parent_id'        => $parentId,
                'level'            => $level,
                'access_link'      => self::LINK,
                'icon'             => 'BadgeCheck',
                'status'           => 1,
                'sort_order'       => $position,
                // NULL like every sibling: this is a platform menu, not one
                // tenant's.
                'sub_institute_id' => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();
        if (!$row) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $row->id)->delete();

        // Close the gap the insert opened, so a down/up cycle is a no-op rather
        // than drifting every sibling one place further each time.
        DB::table('tblmenumaster_g2g')
            ->where('parent_id', $row->parent_id)
            ->where('sort_order', '>', $row->sort_order)
            ->decrement('sort_order');
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
