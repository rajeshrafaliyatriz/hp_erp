<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mobile app menus: carry the rights the OLD app menus had over to the new menu
 * tree, so the app can read its menu from /user/ajax_sidebar_menu_g2g.
 *
 * ── WHY THIS IS NEEDED ──────────────────────────────────────────────────────
 *
 * The app used to read tblmenumaster + tblgroupwise_rights (is_mobile = 1). The
 * sidebar API reads tblmenumaster_g2g + tblgroupwise_rights_g2g, where almost
 * nothing carried is_mobile, so switching the app to it emptied its menu.
 *
 * ── MATCHED BY NAME, NEVER BY ID ────────────────────────────────────────────
 *
 * The two trees reuse ids for different things (id 23 is "Task Activity Stream"
 * in one and "Role & Permissions" in the other), so copying by id would hand out
 * rights to the wrong screens. Each OLD menu is found by its exact `menu_name`
 * and sent to the NEW row found by `access_link`; ids are never compared, so it
 * also holds on a database whose ids differ.
 *
 * Only the screens the app actually has are carried over (MAP below). The old
 * "Task Activity Stream" has no counterpart in the new tree, so it stays behind.
 *
 * ── WHAT IT WRITES, FOR EACH PROFILE THAT HAD THE OLD MOBILE RIGHT ──────────
 *
 *   • new menu row exists   → is_mobile = 1, only if the profile can already view it
 *   • no row yet            → inserted with the old can_view/add/edit/delete, is_mobile = 1
 *   • its parent groups     → can_view = 1 inserted if missing (the sidebar API drops
 *                             a menu whose parent the profile cannot view)
 *
 * Rows it inserts carry created_at = SENTINEL so down() removes exactly those.
 * Rows it only flagged is_mobile are left flagged by down(): it cannot know whether
 * an admin has since chosen that, and clearing it would undo their choice.
 *
 * Idempotent. RUN ON EACH DATABASE, AFTER the g2g menu tree exists:
 *   php artisan migrate --path=database/migrations/2026_11_22_100300_copy_legacy_mobile_menu_rights_to_g2g.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_22_100300_copy_legacy_mobile_menu_rights_to_g2g.php
 */
return new class extends Migration
{
    private const SENTINEL = '2026-11-22 10:03:00';

    /** OLD menu_name => NEW access_link. */
    private const MAP = [
        'My Attendance' => '/module/hrit-solutions/attendance-management/attendance-tracking',
        'Attendance Report' => '/module/hrit-solutions/attendance-management/monthly-attendance-report',
        'Apply Leave' => '/module/hrit-solutions/leave-management/leave-dashboard',
        'My Leave' => '/module/hrit-solutions/leave-management/leave-requests',
        'Courses List' => '/module/lms/learning/learning-catalog',
        'My Learning Dashboard' => '/module/lms/learning/my-learning',
        'Assessment List' => '/module/lms/learning/my-assessment',
        'Task Assignment & Progress' => '/module/task-management/my-tasks',
        'Library & Taxonomy' => '/module/capability-intelligence/capability-library',
        'Add Organization Detail' => '/module/organizational-management/organization-setup/organization-profile',
    ];

    public function up(): void
    {
        foreach (['tblmenumaster', 'tblgroupwise_rights', 'tblmenumaster_g2g', 'tblgroupwise_rights_g2g', 'tbluserprofilemaster'] as $t) {
            if (!$this->tableExists($t)) {
                return;
            }
        }

        $profileTenant = DB::table('tbluserprofilemaster')->pluck('sub_institute_id', 'id');

        DB::transaction(function () use ($profileTenant) {
            foreach (self::MAP as $oldName => $newLink) {
                $new = DB::table('tblmenumaster_g2g')->where('access_link', $newLink)->first();
                if (!$new) {
                    continue;   // that screen is not in this database's new tree
                }

                $oldIds = DB::table('tblmenumaster')->where('menu_name', $oldName)->pluck('id');
                if ($oldIds->isEmpty()) {
                    continue;
                }

                $oldRights = DB::table('tblgroupwise_rights')
                    ->whereIn('menu_id', $oldIds)
                    ->where('is_mobile', 1)
                    ->where('can_view', 1)
                    ->get();

                foreach ($oldRights->unique('profile_id') as $old) {
                    if (!isset($profileTenant[$old->profile_id])) {
                        continue;   // a rights row for a profile that no longer exists
                    }

                    $existing = DB::table('tblgroupwise_rights_g2g')
                        ->where('menu_id', $new->id)
                        ->where('profile_id', $old->profile_id)
                        ->get();

                    if ($existing->isNotEmpty()) {
                        DB::table('tblgroupwise_rights_g2g')
                            ->where('menu_id', $new->id)
                            ->where('profile_id', $old->profile_id)
                            ->where('can_view', 1)
                            ->update(['is_mobile' => 1]);

                        continue;
                    }

                    DB::table('tblgroupwise_rights_g2g')->insert([
                        'menu_id' => $new->id,
                        'profile_id' => $old->profile_id,
                        'can_view' => 1,
                        'can_add' => (int) $old->can_add,
                        'can_edit' => (int) $old->can_edit,
                        'can_delete' => (int) $old->can_delete,
                        'dashboard_right' => 0,
                        'is_mobile' => 1,
                        'sub_institute_id' => $profileTenant[$old->profile_id],
                        'created_at' => self::SENTINEL,
                    ]);

                    $this->viewAncestors($new, (int) $old->profile_id, $profileTenant[$old->profile_id]);
                }
            }
        });
    }

    public function down(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->where('created_at', self::SENTINEL)->delete();
    }

    /** Let the profile see each group above the menu, or the sidebar API drops the menu. */
    private function viewAncestors($menu, int $profileId, $tenantId): void
    {
        $parentId = (int) $menu->parent_id;

        while ($parentId > 0) {
            $parent = DB::table('tblmenumaster_g2g')->where('id', $parentId)->first();
            if (!$parent) {
                return;
            }

            $row = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $parentId)->where('profile_id', $profileId)->first();
            if (!$row) {
                DB::table('tblgroupwise_rights_g2g')->insert([
                    'menu_id' => $parentId,
                    'profile_id' => $profileId,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'sub_institute_id' => $tenantId,
                    'created_at' => self::SENTINEL,
                ]);
            }

            $parentId = (int) $parent->parent_id;
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
