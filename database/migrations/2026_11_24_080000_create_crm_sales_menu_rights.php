<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unlike Marketing, there is no disabled Sales scaffolding to reactivate -
 * confirmed by direct query on both connections (no id range under CRM root
 * 199 references Opportunities/Quotes/Products/Services/SMS/Sales anywhere).
 * This is a clean insert: a new "Sales" parent (sibling to "Marketing",
 * child of CRM root 199) plus 5 leaves under it.
 *
 * Because "Sales" itself is a fresh row (not a reactivated, pre-existing one
 * like Marketing/200 was), its auto-increment id is NOT guaranteed to match
 * between `mysql` and `live` - nothing anywhere may hardcode it. The 5
 * leaves are matched by `access_link` string only, exactly like
 * Contacts/Organizations/Campaigns already are under Marketing.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_24_080000_create_crm_sales_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_24_080000_create_crm_sales_menu_rights.php
 */
return new class extends Migration
{
    private const CRM_ID = 199;

    /** Leaf label => flat trailing slug under /module/crm/sales/. */
    private const LEAVES = [
        'Opportunities' => 'opportunities',
        'Quotes' => 'quotes',
        'Products' => 'products',
        'Services' => 'services',
        'SMS Notifier' => 'sms-notifier',
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
                . 'database - refusing to attach a Sales branch to something else by mistake.'
            );
        }

        $salesId = $this->createSalesParent();

        $leafIds = [];

        foreach (self::LEAVES as $label => $slug) {
            $leafIds[] = $this->createLeaf($salesId, $label, $slug);
        }

        $this->topUpAdminRights(array_merge([$salesId], $leafIds));
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $salesId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', '/module/crm/sales')
            ->value('id');

        if ($salesId === null) {
            return;
        }

        $leafIds = DB::table('tblmenumaster_g2g')->where('parent_id', $salesId)->pluck('id');
        $allIds = $leafIds->push($salesId);

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $allIds)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $allIds)->delete();
    }

    private function createSalesParent(): int
    {
        $accessLink = '/module/crm/sales';

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::CRM_ID)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => 'Sales',
            'parent_id' => self::CRM_ID,
            'level' => 2,
            'page_type' => 'page',
            'access_link' => $accessLink,
            'icon' => 'mdi mdi-cash-multiple',
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => '',
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLeaf(int $salesId, string $label, string $slug): int
    {
        $accessLink = '/module/crm/sales/' . $slug;

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', $salesId)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $salesId)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $label,
            'parent_id' => $salesId,
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
            'Opportunities' => 'mdi mdi-target',
            'Quotes' => 'mdi mdi-file-document-outline',
            'Products' => 'mdi mdi-package-variant-closed',
            'Services' => 'mdi mdi-briefcase-outline',
            'SMS Notifier' => 'mdi mdi-message-text-outline',
            default => 'mdi mdi-cash-multiple',
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
