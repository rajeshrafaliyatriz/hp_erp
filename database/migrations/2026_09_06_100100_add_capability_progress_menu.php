<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Talent Management sub-menu for the capability development record.
 *
 * ── WHY A MENU ROW AND NOT ANOTHER TAB ──────────────────────────────────────
 *
 * The cheaper option was a fourth tab inside Development & Career Paths, and
 * that is the pattern this codebase has used before. It is the wrong shape
 * here: that screen is entirely forward-looking - plans, career paths, learning
 * assignments - and this is the only screen in the product that reports an
 * OUTCOME. Burying "did anybody actually improve" inside "what we plan to do"
 * is how it stops being asked.
 *
 * ── THE ID COMES FROM THE INSERT ────────────────────────────────────────────
 *
 * Never MAX(id) + 1. content-map-m2.ts documents that exact trap: the two
 * databases have drifted, so an id computed on one is a different row on the
 * other, and the content map is keyed by submenu id. This inserts, reads back
 * what the database assigned, and reports it - the content map entry has to
 * match, and a mismatch is silent (the sidebar row renders and lands on
 * "under construction").
 *
 * ── RIGHTS ARE COPIED FROM A SIBLING, NOT INVENTED ──────────────────────────
 *
 * A menu row with status=1 and no tblgroupwise_rights_g2g row is invisible -
 * displaySidebarMenu requires a can_view row for the caller's profile. Rights
 * are copied from menu 157 (Development & Career Paths), which is the audience
 * that should see this: whoever can see the plans should see whether they
 * worked. Dev carries 81 such rows and live 35; copying rather than hardcoding
 * keeps each database's own audience.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_06_100100_add_capability_progress_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_06_100100_add_capability_progress_menu.php
 */
return new class extends Migration
{
    private const PARENT = 3;                 // Talent Management
    private const SIBLING = 157;              // Development & Career Paths
    private const LINK = '/module/talent-management/capability-progress';
    private const NAME = 'Capability Progress';

    public function up(): void
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id');

        if ($existing) {
            echo "  Menu row already present as id {$existing}\n";
            $this->grantRights((int) $existing);

            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('id', self::SIBLING)->first();

        if (!$sibling) {
            echo "  SKIPPED: sibling menu " . self::SIBLING . " not found on this database.\n";

            return;
        }

        $sort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::PARENT)->max('sort_order');

        $menuId = DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => self::NAME,
            'parent_id' => self::PARENT,
            'level' => 2,
            'access_link' => self::LINK,
            'page_type' => $sibling->page_type ?? 'page',
            'status' => 1,
            // NULL = visible to every tenant, matching its siblings. A tenant id
            // here would hide it from everyone else.
            'sub_institute_id' => $sibling->sub_institute_id,
            'menu_type' => $sibling->menu_type ?? null,
            'icon' => $sibling->icon ?? null,
            'sort_order' => $sort + 1,
        ]);

        echo "  Menu row created with id {$menuId} — the content map must use this submenuId.\n";

        $this->grantRights($menuId);
    }

    /** One can_view row per profile that can already see the sibling. */
    private function grantRights(int $menuId): void
    {
        $already = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->count();

        if ($already > 0) {
            echo "  Rights already present ({$already} row(s)).\n";

            return;
        }

        $siblingRights = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::SIBLING)
            ->where('can_view', 1)
            ->get();

        if ($siblingRights->isEmpty()) {
            echo "  WARNING: sibling " . self::SIBLING . " has no rights rows, so none were copied. "
                . "The menu will not appear for anybody until rights are granted.\n";

            return;
        }

        $now = now();
        $rows = [];

        foreach ($siblingRights as $right) {
            $row = (array) $right;
            unset($row['id']);

            $row['menu_id'] = $menuId;
            $row['can_view'] = 1;

            // Reading somebody's development record is not licence to edit it,
            // and this screen writes nothing.
            foreach (['can_add', 'can_edit', 'can_delete'] as $write) {
                if (array_key_exists($write, $row)) {
                    $row[$write] = 0;
                }
            }

            foreach (['created_at', 'updated_at'] as $stamp) {
                if (array_key_exists($stamp, $row)) {
                    $row[$stamp] = $now;
                }
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tblgroupwise_rights_g2g')->insert($chunk);
        }

        echo '  Granted can_view to ' . count($rows) . " profile(s), copied from menu " . self::SIBLING . ".\n";
    }

    public function down(): void
    {
        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->value('id');

        if (!$menuId) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }
};
