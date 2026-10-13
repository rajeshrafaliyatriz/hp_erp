<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Order-to-cash suite - legacy CRM's Vendors, SalesOrder, PurchaseOrder,
 * Invoice modules. All 4 leaves attach under the existing Sales parent
 * (created in 2026_11_24_080000_create_crm_sales_menu_rights.php) -
 * same mechanics as that migration and the Price Books gap-closing leaf
 * that followed it.
 */
return new class extends Migration
{
    private const CRM_ID = 199;

    /** @var array<string, string> access-link suffix => [label, icon] */
    private const LEAVES = [
        'vendors' => ['Vendors', 'mdi mdi-truck-outline'],
        'sales-orders' => ['Sales Orders', 'mdi mdi-cart-check'],
        'purchase-orders' => ['Purchase Orders', 'mdi mdi-cart-arrow-down'],
        'invoices' => ['Invoices', 'mdi mdi-receipt-text-outline'],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $salesId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::CRM_ID)
            ->where('access_link', '/module/crm/sales')
            ->value('id');

        if ($salesId === null) {
            throw new RuntimeException(
                'No existing Sales menu row under CRM root ' . self::CRM_ID . ' - expected '
                . '2026_11_24_080000_create_crm_sales_menu_rights.php to have created it already.'
            );
        }

        $leafIds = [];
        foreach (self::LEAVES as $slug => [$label, $icon]) {
            $leafIds[] = $this->createLeaf((int) $salesId, $slug, $label, $icon);
        }

        $this->topUpAdminRights($leafIds);
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (array_keys(self::LEAVES) as $slug) {
            $leafId = DB::table('tblmenumaster_g2g')
                ->where('access_link', '/module/crm/sales/' . $slug)
                ->value('id');

            if ($leafId === null) {
                continue;
            }

            if ($this->tableExists('tblgroupwise_rights_g2g')) {
                DB::table('tblgroupwise_rights_g2g')->where('menu_id', $leafId)->delete();
            }

            DB::table('tblmenumaster_g2g')->where('id', $leafId)->delete();
        }
    }

    private function createLeaf(int $salesId, string $slug, string $label, string $icon): int
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
            'icon' => $icon,
            'status' => 1,
            'sort_order' => ++$nextSort,
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
