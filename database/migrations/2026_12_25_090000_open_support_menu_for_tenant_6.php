<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opens the Support module (Tickets, FAQ, Service Contracts, Assets, SMS Notifier,
 * Contacts, Organizations, Email Templates, Reports) for organisation 6 only.
 *
 * On the owner's request, 2026-11-24. Run on one host at a time, one file:
 *   php artisan migrate --path=database/migrations/2026_12_25_090000_open_support_menu_for_tenant_6.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_25_090000_open_support_menu_for_tenant_6.php
 *
 * Two facts about both hosts (read 2026-11-24) decide the shape of this file:
 *
 *  1. The Support root (`/module/crm/support`) is status 0, so it is hidden from every sidebar.
 *     Its leaves are status 1.
 *  2. The Administrator profile of eleven OTHER organisations still holds can_view on the root and
 *     every leaf (the 2026_11_24_083800 grant), while organisation 6's Administrator holds none of
 *     them. So flipping the root to status 1 alone would open Support for those eleven
 *     organisations and not for organisation 6.
 *
 * So the root is opened AND scoped: `sub_institute_id = '6'` on the menu row, which
 * tblmenumaster_g2gModel::visibleToTenant() honours in both the sidebar and the Role & Permissions
 * matrix (a comma list, NULL or '' meaning everyone). The other organisations' rights stay in
 * place but cannot surface. Opening Support for everyone later is one statement: set the root's
 * sub_institute_id back to ''.
 *
 * The root is touched only while it is still hidden. A root that is already status 1 (open for
 * everyone, or already scoped) is never narrowed or widened by this file.
 *
 * Rights: the Administrator profile(s) of organisation 6, view/add/edit/delete, on the root and
 * its visible leaves, matched by profile id and menu id only (the rights table's own
 * sub_institute_id is text holding ids, NULLs and CSV strings, so it is never a filter). No other
 * profile is granted: the Administrator can tick Support for other roles in Role & Permissions
 * once the audience is counted (Docs/agentic-crm/DB-RULES.md, "Menu and rights").
 *
 * MariaDB 10.1.48 on live: existence is checked through information_schema, as in every menu
 * migration in this effort; sqlite (PHPUnit) has no information_schema.
 */
return new class extends Migration
{
    private const TENANT = 6;

    private const SUPPORT_LINK = '/module/crm/support';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $support = DB::table('tblmenumaster_g2g')->where('access_link', self::SUPPORT_LINK)->first(['id', 'status']);

        if ($support === null) {
            return;
        }

        if ((int) $support->status === 0) {
            DB::table('tblmenumaster_g2g')->where('id', $support->id)->update([
                'status' => 1,
                'sub_institute_id' => (string) self::TENANT,
                'updated_at' => now(),
            ]);
        }

        $this->grantTenantAdministrators($this->branchMenuIds((int) $support->id));
    }

    /**
     * Hides the root again and removes the rights this file added. It restores the shipped state
     * (status 0, no tenant list) only when the root still carries exactly this file's scope.
     */
    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $support = DB::table('tblmenumaster_g2g')->where('access_link', self::SUPPORT_LINK)->first(['id', 'sub_institute_id']);

        if ($support === null) {
            return;
        }

        if ((string) $support->sub_institute_id === (string) self::TENANT) {
            DB::table('tblmenumaster_g2g')->where('id', $support->id)->update([
                'status' => 0,
                'sub_institute_id' => '',
                'updated_at' => now(),
            ]);
        }

        $profileIds = $this->tenantAdministratorProfileIds();

        if ($profileIds !== [] && $this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')
                ->whereIn('profile_id', $profileIds)
                ->whereIn('menu_id', $this->branchMenuIds((int) $support->id))
                ->delete();
        }
    }

    /** The root plus its visible leaves. Support has no deeper level. */
    private function branchMenuIds(int $rootId): array
    {
        $leafIds = DB::table('tblmenumaster_g2g')
            ->where('parent_id', $rootId)
            ->where('status', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_merge([$rootId], $leafIds);
    }

    /** @return list<int> */
    private function tenantAdministratorProfileIds(): array
    {
        if (! $this->tableExists('tbluserprofilemaster')) {
            return [];
        }

        $ids = [];

        foreach (DB::table('tbluserprofilemaster')->where('sub_institute_id', self::TENANT)->get(['id', 'name', 'role_key']) as $profile) {
            if (RoleKey::fromProfile($profile) === 'administrator') {
                $ids[] = (int) $profile->id;
            }
        }

        return $ids;
    }

    private function grantTenantAdministrators(array $menuIds): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        foreach ($this->tenantAdministratorProfileIds() as $profileId) {
            foreach ($menuIds as $menuId) {
                $existing = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profileId)
                    ->exists();

                if ($existing) {
                    DB::table('tblgroupwise_rights_g2g')
                        ->where('menu_id', $menuId)
                        ->where('profile_id', $profileId)
                        ->update(['can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);

                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => self::TENANT,
                    'menu_id' => $menuId,
                    'profile_id' => $profileId,
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
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
