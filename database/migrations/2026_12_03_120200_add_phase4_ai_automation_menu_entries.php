<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 AI Agents & Automation: adds menu rows for Run History and
 * Automation Rules under Sales & Marketing Intelligence > GTM Agents branch,
 * and a dedicated "CRM Automation" sub-section under Marketing.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_120200_add_phase4_ai_automation_menu_entries.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_120200_add_phase4_ai_automation_menu_entries.php
 */
return new class extends Migration
{
    /** [name, access_link, parent_access_link, level, icon] */
    private const PAGES = [
        // Under Marketing (L2) → L3 children
        ['Agent Run History',    '/module/crm/marketing/agent-runs',       '/module/crm/marketing', 3, 'mdi mdi-history'],
        ['Automation Rules',     '/module/crm/marketing/automation-rules',  '/module/crm/marketing', 3, 'mdi mdi-cog-transfer-outline'],
    ];

    private const GRANTS = [
        'administrator'     => [1, 1, 1, 1],
        'hr_manager'        => [1, 1, 1, 0],
        'hr_executive'      => [1, 1, 1, 0],
        'reporting_manager' => [1, 0, 0, 0],
        'executive'         => [1, 0, 0, 0],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $ids = [];
        foreach (self::PAGES as [$name, $link, $parentLink, $level, $icon]) {
            $parentId = (int) DB::table('tblmenumaster_g2g')->where('access_link', $parentLink)->value('id');
            if (! $parentId) {
                continue;
            }
            $ids[] = $this->ensureMenu($name, $link, $parentId, $level, $icon);
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
                    ->where('menu_id', $menuId)->where('profile_id', $profile->id)->exists();
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
        $ids = DB::table('tblmenumaster_g2g')->whereIn('access_link', $links)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }
        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function ensureMenu(string $name, string $link, int $parentId, int $level, string $icon): int
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $sort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $parentId)->max('sort_order') + 1;
        return (int) DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name'        => $name,
            'parent_id'        => $parentId,
            'level'            => $level,
            'access_link'      => $link,
            'icon'             => $icon,
            'page_type'        => 'page',
            'status'           => 1,
            'sort_order'       => $sort,
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
