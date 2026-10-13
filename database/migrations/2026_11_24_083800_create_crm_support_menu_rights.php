<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Support - the legacy CRM's "Support" tab: Tickets, FAQ, Service
 * Contracts, Assets, plus 3 cross-links to already-shipped screens (SMS
 * Notifier, Contacts, Organizations - vtiger's own "one module, several
 * parent tabs" mechanism, already used for Contacts/Organizations
 * appearing under Marketing). Same clean-insert situation as Sales itself
 * was - confirmed no disabled Support scaffolding exists anywhere under
 * CRM root 199 on either connection.
 *
 * The 3 cross-link leaves get their OWN distinct access_link
 * (/module/crm/support/{slug}) rather than literally reusing the Marketing/
 * Sales URL string - this is what gets correct active-state sidebar
 * highlighting and back-navigation when reached via Support (see the
 * plan's own reasoning). Their underlying data/API is entirely unchanged;
 * this migration only adds navigation surface. The companion rights fix
 * (routes/api.php's platformright middleware gaining an `|`-OR against
 * both access_links for organizations/contacts/sms-log) is a separate,
 * code-level change, not a migration concern - without it, a role granted
 * rights only on these new menu rows would see the item but get a 403.
 *
 * "Support" itself is a fresh row - its id is NOT guaranteed to match
 * between `mysql` and `live`, matching every leaf-creation migration in
 * this phase being access_link-matched, never a hardcoded id.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_24_083800_create_crm_support_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_24_083800_create_crm_support_menu_rights.php
 */
return new class extends Migration
{
    private const CRM_ID = 199;

    /** Leaf label => flat trailing slug under /module/crm/support/. */
    private const LEAVES = [
        'Tickets' => 'tickets',
        'FAQ' => 'faq',
        'Service Contracts' => 'service-contracts',
        'Assets' => 'assets',
        'SMS Notifier' => 'sms-notifier',
        'Contacts' => 'contacts',
        'Organizations' => 'organizations',
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $crm = DB::table('tblmenumaster_g2g')->where('id', self::CRM_ID)->first(['id', 'menu_name', 'access_link']);

        if ($crm === null || $crm->menu_name !== 'CRM' || $crm->access_link !== '/module/crm') {
            throw new RuntimeException(
                'tblmenumaster_g2g id ' . self::CRM_ID . ' is not the expected CRM root row on this '
                . 'database - refusing to attach a Support branch to something else by mistake.'
            );
        }

        $supportId = $this->createSupportParent();

        $leafIds = [];

        foreach (self::LEAVES as $label => $slug) {
            $leafIds[] = $this->createLeaf($supportId, $label, $slug);
        }

        $this->topUpAdminRights(array_merge([$supportId], $leafIds));
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $supportId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', '/module/crm/support')
            ->value('id');

        if ($supportId === null) {
            return;
        }

        $leafIds = DB::table('tblmenumaster_g2g')->where('parent_id', $supportId)->pluck('id');
        $allIds = $leafIds->push($supportId);

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $allIds)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $allIds)->delete();
    }

    private function createSupportParent(): int
    {
        $accessLink = '/module/crm/support';

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::CRM_ID)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => 'Support',
            'parent_id' => self::CRM_ID,
            'level' => 2,
            'page_type' => 'page',
            'access_link' => $accessLink,
            'icon' => 'mdi mdi-lifebuoy',
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => '',
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLeaf(int $supportId, string $label, string $slug): int
    {
        $accessLink = '/module/crm/support/' . $slug;

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', $supportId)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $supportId)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $label,
            'parent_id' => $supportId,
            'level' => 3,
            'page_type' => 'page',
            'access_link' => $accessLink,
            'icon' => $this->iconFor($label),
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => '',
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function iconFor(string $label): string
    {
        return match ($label) {
            'Tickets' => 'mdi mdi-ticket-confirmation-outline',
            'FAQ' => 'mdi mdi-help-circle-outline',
            'Service Contracts' => 'mdi mdi-file-sign',
            'Assets' => 'mdi mdi-laptop',
            'SMS Notifier' => 'mdi mdi-message-text-outline',
            'Contacts' => 'mdi mdi-account-outline',
            'Organizations' => 'mdi mdi-domain',
            default => 'mdi mdi-lifebuoy',
        };
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
