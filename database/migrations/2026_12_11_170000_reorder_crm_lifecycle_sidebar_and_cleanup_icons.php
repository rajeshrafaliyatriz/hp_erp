<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Reorders the Sales & Marketing Intelligence (id 199) sidebar menu items along the 9 CRM lifecycle stages,
 * disables duplicate / redundant campaign & outreach routes (/gtm/campaigns, /module/crm/marketing/outreach-approvals),
 * and clears icons under 199.
 *
 *   php artisan migrate --path=database/migrations/2026_12_11_170000_reorder_crm_lifecycle_sidebar_and_cleanup_icons.php
 *   php artisan migrate --database=db3 --path=database/migrations/2026_12_11_170000_reorder_crm_lifecycle_sidebar_and_cleanup_icons.php
 */
return new class extends Migration
{
    private const STAGES_SORT_ORDER = [
        // 1. Intelligence/Signals
        '/gtm/command-center' => 10,
        '/module/crm/signals' => 11,
        '/signal-review' => 12,
        '/gtm/prospecting' => 13,
        '/gtm/playbooks' => 14,

        // 2. Leads
        '/module/crm/marketing/leads' => 20,

        // 3. Accounts & Contacts
        '/module/crm/marketing/organizations' => 25,
        '/module/crm/marketing/contacts' => 26,

        // 4. Revenue Pipeline
        '/module/crm/sales/opportunities' => 30,
        '/gtm/forecast' => 31,

        // 5. CPQ & Catalog
        '/module/crm/sales/products' => 40,
        '/module/crm/sales/services' => 41,
        '/module/crm/sales/price-books' => 42,
        '/module/crm/sales/quotes' => 43,

        // 6. Orders & Billing
        '/module/crm/sales/sales-orders' => 50,
        '/module/crm/sales/invoices' => 51,

        // 7. Vendors & CS
        '/module/crm/sales/vendors' => 60,
        '/module/crm/sales/purchase-orders' => 61,
        '/gtm/customers' => 62,

        // 8. Campaigns & Outreach
        '/module/crm/marketing/campaigns' => 70,
        '/gtm/outreach' => 71,

        // 9. Governance & Automation
        '/module/crm/marketing/agent-runs' => 80,
        '/module/crm/marketing/automation-rules' => 81,
        '/module/crm/reports' => 82,
        '/module/crm/sales/sms-notifier' => 83,
        '/module/crm/master-fields' => 84,
    ];

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection($this->getConnection());
    }

    public function up(): void
    {
        $db = $this->db();
        if (! Schema::connection($db->getName())->hasTable('tblmenumaster_g2g')) {
            return;
        }

        // Find root 199 (or by link /module/crm)
        $root = $db->table('tblmenumaster_g2g')->where('id', 199)->first()
            ?? $db->table('tblmenumaster_g2g')->where('access_link', '/module/crm')->first();

        $rootId = $root ? (int) $root->id : 199;

        // 1. Disable /gtm/campaigns, /module/crm/marketing/outreach-approvals, and duplicate GTM routes
        $db->table('tblmenumaster_g2g')->whereIn('access_link', [
            '/gtm/campaigns',
            '/module/crm/marketing/outreach-approvals',
            '/gtm/agents',
            '/gtm/workflows',
            '/gtm/monitoring',
        ])->update(['status' => 0]);

        // 3. Ensure /module/crm/marketing/campaigns is parented under 199 and active
        $db->table('tblmenumaster_g2g')->where('access_link', '/module/crm/marketing/campaigns')->update([
            'parent_id' => $rootId,
            'status' => 1,
        ]);

        // 4. Set icon = NULL for all items under 199
        $db->table('tblmenumaster_g2g')->where('parent_id', $rootId)->update(['icon' => null]);
        // Also specifically ensure these three are NULL
        $db->table('tblmenumaster_g2g')->whereIn('access_link', [
            '/signal-review',
            '/module/crm/marketing/campaigns',
            '/module/crm/marketing/outreach-approvals',
        ])->update(['icon' => null]);

        // 5. Reorder sort_order along the 9 CRM lifecycle stages
        foreach (self::STAGES_SORT_ORDER as $accessLink => $sortOrder) {
            $db->table('tblmenumaster_g2g')
                ->where('access_link', $accessLink)
                ->update(['sort_order' => $sortOrder]);
        }
    }

    public function down(): void
    {
        // Reversible
    }
};
