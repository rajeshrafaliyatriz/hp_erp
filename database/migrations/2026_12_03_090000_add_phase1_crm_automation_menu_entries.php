<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 CRM Automation: add Outreach Approvals and Consent & Preferences
 * menu rows under the Marketing branch (/module/crm/marketing, level 2).
 *
 * Both screens were added as part of the Phase 1 automation build
 * (2026_12_03_080000–080400). Neither had a menu row yet.
 *
 * Grants mirror the existing CRM Marketing tier:
 *   administrator           : view / add / edit / delete
 *   hr_manager, hr_executive: view / add / edit (no delete)
 *   reporting_manager       : view only
 *   everyone else           : no grant
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_090000_add_phase1_crm_automation_menu_entries.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_090000_add_phase1_crm_automation_menu_entries.php
 */
return new class extends Migration
{
    private const MARKETING_LINK = '/module/crm/marketing';

    private const PAGES = [
        ['Outreach Approvals',   '/module/crm/marketing/outreach-approvals', 'mdi mdi-check-circle-outline'],
        ['Consent & Preferences', '/module/crm/marketing/consent',           'mdi mdi-shield-check-outline'],
    ];

    /** role_key => [can_view, can_add, can_edit, can_delete] */
    private const GRANTS = [
        'administrator'    => [1, 1, 1, 1],
        'hr_manager'       => [1, 1, 1, 0],
        'hr_executive'     => [1, 1, 1, 0],
        'reporting_manager' => [1, 0, 0, 0],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $parentId = (int) DB::table('tblmenumaster_g2g')
            ->where('access_link', self::MARKETING_LINK)
            ->value('id');

        if ($parentId === 0) {
            throw new \RuntimeException(
                'tblmenumaster_g2g row for access_link=' . self::MARKETING_LINK . ' not found — run earlier CRM migrations first.'
            );
        }

        $ids = [];
        foreach (self::PAGES as $i => [$name, $link, $icon]) {
            $ids[] = $this->ensureMenu($name, $link, $parentId, 3, $icon, $i + 1);
        }

        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $profiles = DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name']);

        foreach ($profiles as $profile) {
            $grant = self::GRANTS[RoleKey::fromProfile($profile)] ?? null;
            if ($grant === null) {
                continue;
            }

            foreach ($ids as $menuId) {
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $profile->sub_institute_id,
                    'menu_id'          => $menuId,
                    'profile_id'       => $profile->id,
                    'can_view'         => $grant[0],
                    'can_add'          => $grant[1],
                    'can_edit'         => $grant[2],
                    'can_delete'       => $grant[3],
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'created_at'       => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $links = array_column(self::PAGES, 1);
        $ids   = DB::table('tblmenumaster_g2g')->whereIn('access_link', $links)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function ensureMenu(string $name, string $link, int $parentId, int $level, ?string $icon, ?int $sort = null): int
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $sortOrder = $sort ?? ((int) DB::table('tblmenumaster_g2g')->where('parent_id', $parentId)->max('sort_order') + 1);

        return (int) DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name'        => $name,
            'parent_id'        => $parentId,
            'level'            => $level,
            'access_link'      => $link,
            'icon'             => $icon,
            'page_type'        => 'page',
            'status'           => 1,
            'sort_order'       => $sortOrder,
            'sub_institute_id' => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function tableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
