<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the employee's assessment a way in.
 *
 * The screen has existed for a while and been effectively unreachable. Its only
 * mount point was a third-level tab inside /app/profile -> My Capability, and
 * it sat AFTER that page's early returns, so an unrelated failure fetching the
 * capability gap hid a published assessment entirely. The render coupling is
 * fixed in the component; this fixes the other half - nobody could find it
 * because it was not in any menu.
 *
 * Modelled exactly on "My Learning" (id 209): same parent, same level, the next
 * sort position. That is the row people already use to reach their own learning,
 * so their own assessment belongs beside it rather than in a new place.
 *
 * `sub_institute_id` is NULL, like every one of its siblings: this is a
 * platform menu, not one tenant's.
 *
 * ID 301 IS SET EXPLICITLY. Both databases sit at max(id) = 300, so an
 * auto-increment would give the same number today - but only by coincidence,
 * and a menu id that differs between environments makes the content map's
 * submenuId fallback point at different screens. The stable key is
 * `access_link`; the id is pinned so the fallback cannot drift.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_26_140000_add_my_assessment_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_26_140000_add_my_assessment_menu.php
 */
return new class extends Migration
{
    private const ID   = 301;
    private const LINK = '/module/lms/learning/my-assessment';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // Idempotent on the LINK, not the id: if a row for this screen already
        // exists under any id, adding a second would put the same page in the
        // menu twice.
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->exists();
        if ($existing) {
            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('id', 209)->first();

        DB::table('tblmenumaster_g2g')->insert([
            'id'               => self::ID,
            'menu_name'        => 'My Assessment',
            // Inherited from My Learning rather than hardcoded, so this lands in
            // the right place even if that branch has been rearranged.
            'parent_id'        => $sibling->parent_id ?? 74,
            'level'            => $sibling->level ?? 3,
            'access_link'      => self::LINK,
            'icon'             => 'ClipboardCheck',
            'status'           => 1,
            'sort_order'       => ($sibling->sort_order ?? 3) + 1,
            'sub_institute_id' => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function down(): void
    {
        if ($this->tableExists('tblmenumaster_g2g')) {
            DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->delete();
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
