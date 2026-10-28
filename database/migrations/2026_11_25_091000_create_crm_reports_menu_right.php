<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap-closure phase, Reports (Decision 6): a menu row for the new Reports
 * entry point (/module/crm/support/reports), the same deny-everyone-without-
 * it situation as the Email Templates menu row added in the prior migration
 * - see that file's own docblock for the full reasoning, not repeated here.
 *
 * Note this menu row only gates the CrmReportsView screen's own visibility/
 * navigation entry - the underlying AI Stack endpoints it embeds
 * (routes/ai.php) are separately, unconditionally gated to
 * profile:admin regardless of what right a role holds here. That is true for
 * every module's own AI Stack today, not something this migration changes.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_25_091000_create_crm_reports_menu_right.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_25_091000_create_crm_reports_menu_right.php
 */
return new class extends Migration
{
    private const CRM_ID = 199;

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $supportId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', '/module/crm/support')
            ->value('id');

        if ($supportId === null) {
            throw new RuntimeException(
                'CRM Support parent (/module/crm/support) not found under menu id ' . self::CRM_ID
                . ' on this database - refusing to attach Reports to something else by mistake.'
            );
        }

        $leafId = $this->createLeaf((int) $supportId);

        $this->topUpAdminRights([$leafId]);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $leafId = DB::table('tblmenumaster_g2g')
            ->where('access_link', '/module/crm/support/reports')
            ->value('id');

        if ($leafId === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $leafId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $leafId)->delete();
    }

    private function createLeaf(int $supportId): int
    {
        $accessLink = '/module/crm/support/reports';

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', $supportId)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $supportId)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => 'Reports',
            'parent_id' => $supportId,
            'level' => 3,
            'page_type' => 'page',
            'access_link' => $accessLink,
            'icon' => 'mdi mdi-chart-box-outline',
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => '',
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function topUpAdminRights(array $menuIds): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $profiles = DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']);

        foreach ($menuIds as $menuId) {
            foreach ($profiles as $profile) {
                if (RoleKey::fromProfile($profile) !== 'administrator') {
                    continue;
                }

                $existing = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->first();

                if ($existing !== null) {
                    DB::table('tblgroupwise_rights_g2g')
                        ->where('menu_id', $menuId)
                        ->where('profile_id', $profile->id)
                        ->update(['can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);

                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $profile->sub_institute_id,
                    'menu_id' => $menuId,
                    'profile_id' => $profile->id,
                    'can_view' => 1,
                    'can_add' => 1,
                    'can_edit' => 1,
                    'can_delete' => 1,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);
            }
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
