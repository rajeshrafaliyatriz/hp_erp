<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A real `tblmenumaster_g2g` row for the new Capability Gap Inbox screen
 * (g2gv0: components/domain/lms/gap-inbox/capability-gap-inbox.tsx, registered
 * at accessLink '/module/lms/training-and-records/capability-gap-inbox' in
 * hooks/content-map-m4.ts). Without this row the screen exists and is
 * reachable by a direct URL / a notification's action_url, but never appears
 * in the sidebar and has no rights of its own.
 *
 * Same shape as its three siblings under Training & Records (parent_id 75:
 * Assignments id 81, Sessions & Calendar id 82, Certifications & Records
 * id 83) — level 3, page_type '', icon '', status 1, sub_institute_id null,
 * sort_order one past the current max under that parent.
 *
 * Rights: granted to every (profile_id, sub_institute_id) pair that already
 * has can_view=1 on the sibling Assignments row on THIS SAME connection —
 * the inbox is gated `profile:admin,hr` at the route exactly like Assignments
 * already is, so copying its view grants matches the route gate without
 * widening anyone's actual access.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_14_100400_create_capability_gap_inbox_menu_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_14_100400_create_capability_gap_inbox_menu_row.php
 */
return new class extends Migration
{
    private const PARENT_ID = 75;
    private const ACCESS_LINK = '/module/lms/training-and-records/capability-gap-inbox';
    private const SIBLING_ACCESS_LINK = '/module/lms/training-and-records/assignments';
    private const LABEL = 'Capability Gap Inbox';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = $this->createMenuRow();

        if ($menuId === null || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $siblingId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::SIBLING_ACCESS_LINK)
            ->value('id');

        if ($siblingId === null) {
            return;
        }

        $siblingGrants = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $siblingId)
            ->where('can_view', 1)
            ->get();

        foreach ($siblingGrants as $grant) {
            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->where('profile_id', $grant->profile_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $grant->sub_institute_id,
                'menu_id' => $menuId,
                'profile_id' => $grant->profile_id,
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'dashboard_right' => 0,
                'is_mobile' => $grant->is_mobile,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($menuId === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    private function createMenuRow(): ?int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => self::LABEL,
            'parent_id' => self::PARENT_ID,
            'level' => 3,
            /*
             * `page_type` is `enum('blade','page','link')`, nullable. The
             * sibling rows (81-83) hold an empty string, which only an older,
             * non-strict-mode insert could have produced — this connection
             * runs STRICT_TRANS_TABLES and turns that same '' into
             * "Data truncated for column 'page_type'" (measured against this
             * row at migration time). NULL is the closest honest equivalent
             * the enum actually accepts, and every reader of this table
             * already treats a non-'page'/'blade'/'link' value as "ordinary
             * route, no special handling" either way.
             */
            'page_type' => null,
            'access_link' => self::ACCESS_LINK,
            'icon' => '',
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => null,
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
