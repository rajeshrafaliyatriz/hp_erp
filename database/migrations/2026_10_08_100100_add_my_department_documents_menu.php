<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every employee a way into their OWN department's document space.
 *
 * ── WHY A MENU ROW AND NOT A TAB ────────────────────────────────────────────
 *
 * The admin Department Management screen is getting its own "Documents" tab
 * (department-detail-page.tsx), scoped to whatever department is open. But
 * that screen's own access (`department-list` in types/role.ts) has no entry
 * for plain `employee` at all - granting the module so an employee could see
 * their own department's documents would also hand them Overview/Employees/
 * Job Roles/Process/Signals for every department they can reach. Exactly the
 * shape of mistake this codebase has already paid for once (see My
 * Certifications' own menu migration, 2026_09_30_110000, for the fuller
 * story) - a module right cannot distinguish "mine" from "everyone's," so
 * self-service gets its own row over its own subject-less endpoint, same as
 * My Learning, My Tasks, My HR, My Certifications.
 *
 * ── WHERE IT GOES ───────────────────────────────────────────────────────────
 *
 * Beside Department Management, under Organization Setup - this product's
 * established pattern of landing a "My X" in the same branch as the admin
 * screen it pairs with (My Certifications sits beside Certifications under
 * Talent Management, the same way).
 *
 * ── NO PINNED ID, UNLIKE MY CERTIFICATIONS' 401 ─────────────────────────────
 *
 * 401 was pinned because `content-map-m3.ts`'s entry for My Certifications
 * carries a `submenuId` FALLBACK used when `access_link` is blank - and app/
 * live were at different max ids, so an auto-increment id would differ per
 * environment and that fallback would point at different screens on each.
 * `content-map-m1.ts` (where this entry lands - see the matching frontend
 * change) has no such fallback on ANY of its entries - read in full before
 * writing this migration: all seven of its M1_CONTENT rows carry only
 * `accessLink` + `component`, no `submenuId` field anywhere, confirming its
 * own comment ("submenuId is kept only as a fallback in case a row's
 * access_link is ever missing/blank") describes a mechanism this file simply
 * never exercises. A plain auto-increment is safe here.
 *
 * Idempotent on `access_link`, not id - same reasoning as the precedent.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_10_08_100100_add_my_department_documents_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_08_100100_add_my_department_documents_menu.php
 */
return new class extends Migration
{
    private const LINK = '/module/organizational-management/organization-setup/my-department-documents';
    /** Department Management - the admin screen this pairs with. */
    private const AFTER_LINK = '/module/organizational-management/organization-setup/department-management';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        if (DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->exists()) {
            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('access_link', self::AFTER_LINK)->first();

        if (!$sibling) {
            // Department Management itself is missing on this database - do
            // not guess at a parent/level to land under.
            return;
        }

        $parentId = $sibling->parent_id;
        $level = $sibling->level;
        $position = (int) $sibling->sort_order + 1;

        // ONE TRANSACTION - see 2026_09_30_110000's own docblock for why a
        // non-atomic shift+insert once left mysql and live disagreeing.
        DB::transaction(function () use ($parentId, $level, $position) {
            DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('sort_order', '>=', $position)
                ->increment('sort_order');

            DB::table('tblmenumaster_g2g')->insert([
                'menu_name' => 'My Department Documents',
                'parent_id' => $parentId,
                'level' => $level,
                'access_link' => self::LINK,
                'icon' => 'mdi mdi-folder-account',
                'status' => 1,
                'sort_order' => $position,
                'sub_institute_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
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
