<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap-closure phase: a menu row for the new Email Templates admin screen
 * (/module/crm/support/email-templates). Without this, RequirePlatformRight
 * denies EVERY caller — including administrators — since
 * MenuRight::idForAccessLink() returns null for an access_link with no
 * tblmenumaster_g2g row, and checkFixedLink() treats that as an outright
 * deny, not a widen (confirmed by reading RequirePlatformRight.php directly).
 *
 * Attached under the existing Support parent (found by access_link, never a
 * hardcoded id — the Support parent's own row is a fresh insert from the
 * Support-phase migration and its id is not guaranteed stable across
 * connections, same discipline as every other leaf-creation migration in
 * this CRM subsystem).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_25_090000_create_crm_email_templates_menu_right.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_25_090000_create_crm_email_templates_menu_right.php
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
                . ' on this database - refusing to attach Email Templates to something else by mistake.'
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
            ->where('access_link', '/module/crm/support/email-templates')
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
        $accessLink = '/module/crm/support/email-templates';

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', $supportId)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $supportId)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => 'Email Templates',
            'parent_id' => $supportId,
            'level' => 3,
            'page_type' => 'page',
            'access_link' => $accessLink,
            'icon' => 'mdi mdi-email-outline',
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
