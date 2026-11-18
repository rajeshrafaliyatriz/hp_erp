<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unify Sales & Marketing Intelligence into a single sequential process-oriented
 * lifecycle setup modeled after Talent Management (Hire -> Grow -> Exit -> Admin).
 *
 * Matching is performed strictly by access_link (not auto-increment ids, which
 * differ across connections).
 *
 * 1. Permanently deletes ghost screen 'Consent & Preferences' (/module/crm/marketing/consent)
 *    and removes its permissions from tblgroupwise_rights_g2g.
 * 2. Deactivates legacy container folders with status = 0:
 *    - Marketing (/module/crm/marketing)
 *    - Sales (/module/crm/sales)
 *    - GTM Agents (/gtm)
 *    - Platform Services (/platform-services/workflow?module=crm)
 * 3. Deactivates redundant duplicate screens with status = 0:
 *    - Outreach Approvals (/module/crm/marketing/outreach-approvals)
 *    - CRM Campaigns (/module/crm/marketing/campaigns)
 *    - GTM Deals (/gtm/deals)
 *    - GTM Reports (/gtm/reports)
 * 4. Deactivates nested sub-items under Market Signals from sidebar (/signals, /ingestion, /portfolio,
 *    /partners, /module/crm/marketing/signal-inbox) with status = 0, so Market Signals renders
 *    as a clean, flat, un-nested sidebar navigation item (top tabs inside signals-header.tsx handle deep navigation).
 * 5. Re-parents and orders all 28 canonical sub-modules directly under Root 199 ('Sales & Marketing Intelligence')
 *    with level = 2, status = 1, and sequential sort orders 1 to 28.
 * 6. Restores any inadvertently misassigned non-CRM / Support items.
 */
return new class extends Migration
{
    private const ROOT_LINK = '/module/crm';

    private const CANONICAL_LIFECYCLE = [
        '/gtm/command-center'                   => ['sort' => 1,  'name' => 'Command Center'],
        '/module/crm/signals'                   => ['sort' => 2,  'name' => 'Market Signals'],
        '/gtm/prospecting'                      => ['sort' => 3,  'name' => 'Prospecting'],
        '/gtm/playbooks'                        => ['sort' => 4,  'name' => 'Playbooks'],
        '/module/crm/marketing/organizations'   => ['sort' => 5,  'name' => 'Organizations'],
        '/module/crm/marketing/contacts'        => ['sort' => 6,  'name' => 'Contacts'],
        '/gtm/campaigns'                        => ['sort' => 7,  'name' => 'Campaigns'],
        '/gtm/outreach'                         => ['sort' => 8,  'name' => 'Outreach'],
        '/module/crm/marketing/leads'           => ['sort' => 9,  'name' => 'Leads'],
        '/module/crm/marketing/automation-rules' => ['sort' => 10, 'name' => 'Automation Rules'],
        '/module/crm/sales/opportunities'       => ['sort' => 11, 'name' => 'Opportunities & Deals'],
        '/gtm/forecast'                         => ['sort' => 12, 'name' => 'Forecast'],
        '/module/crm/sales/quotes'              => ['sort' => 13, 'name' => 'Quotes'],
        '/module/crm/sales/products'            => ['sort' => 14, 'name' => 'Products'],
        '/module/crm/sales/services'            => ['sort' => 15, 'name' => 'Services'],
        '/module/crm/sales/price-books'         => ['sort' => 16, 'name' => 'Price Books'],
        '/module/crm/sales/sales-orders'        => ['sort' => 17, 'name' => 'Sales Orders'],
        '/module/crm/sales/invoices'            => ['sort' => 18, 'name' => 'Invoices'],
        '/module/crm/sales/vendors'             => ['sort' => 19, 'name' => 'Vendors'],
        '/module/crm/sales/purchase-orders'     => ['sort' => 20, 'name' => 'Purchase Orders'],
        '/gtm/customers'                        => ['sort' => 21, 'name' => 'Customer Success'],
        '/module/crm/sales/sms-notifier'        => ['sort' => 22, 'name' => 'SMS Notifier'],
        '/gtm/agents'                           => ['sort' => 23, 'name' => 'Agents'],
        '/module/crm/marketing/agent-runs'      => ['sort' => 24, 'name' => 'Agent Run History'],
        '/gtm/workflows'                        => ['sort' => 25, 'name' => 'Workflows'],
        '/module/crm/reports'                   => ['sort' => 26, 'name' => 'Reports'],
        '/gtm/monitoring'                       => ['sort' => 27, 'name' => 'Monitoring'],
        '/module/crm/master-fields'             => ['sort' => 28, 'name' => 'Master Fields'],
    ];

    private const FOLDER_CONTAINERS = [
        '/module/crm/marketing',
        '/module/crm/sales',
        '/gtm',
        '/platform-services/workflow?module=crm',
    ];

    private const DUPLICATE_LINKS = [
        '/module/crm/marketing/outreach-approvals',
        '/module/crm/marketing/campaigns',
        '/gtm/deals',
        '/gtm/reports',
    ];

    private const NESTED_SIGNALS_SUBITEMS = [
        '/signals',
        '/ingestion',
        '/portfolio',
        '/partners',
        '/module/crm/marketing/signal-inbox',
    ];

    public function up(): void
    {
        $conn = DB::connection($this->getConnection());

        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $root = $conn->table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->first();
        if (! $root) {
            return;
        }
        $rootId = $root->id;

        // 1. Permanently delete ghost screen 'Consent & Preferences'
        $consent = $conn->table('tblmenumaster_g2g')->where('access_link', '/module/crm/marketing/consent')->first();
        if ($consent) {
            if ($this->tableExists('tblgroupwise_rights_g2g')) {
                $conn->table('tblgroupwise_rights_g2g')->where('menu_id', $consent->id)->delete();
            }
            $conn->table('tblmenumaster_g2g')->where('id', $consent->id)->delete();
        }

        // 2. Restore any non-CRM / Support items that were touched
        $agentWorkspace = $conn->table('tblmenumaster_g2g')->where('access_link', '/module/agentic-ai/agent-workspace')->first();
        if ($agentWorkspace) {
            $conn->table('tblmenumaster_g2g')->where('id', $agentWorkspace->id)->update([
                'menu_name' => 'Agent Workspace',
                'parent_id' => 186,
                'level'     => 2,
                'sort_order'=> 2,
                'status'    => 1,
            ]);
        }

        $capGap = $conn->table('tblmenumaster_g2g')->where('access_link', '/module/lms/training-and-records/capability-gap-inbox')->first();
        if ($capGap) {
            $conn->table('tblmenumaster_g2g')->where('id', $capGap->id)->update([
                'menu_name' => 'Capability Gap Inbox',
                'parent_id' => 75,
                'level'     => 3,
                'sort_order'=> 3,
                'status'    => 1,
            ]);
        }

        $supportRoot = $conn->table('tblmenumaster_g2g')->where('access_link', '/module/crm/support')->first();
        if ($supportRoot) {
            $supportItems = [
                '/module/crm/support/tickets'          => ['name' => 'Tickets', 'sort' => 1],
                '/module/crm/support/faq'              => ['name' => 'FAQ', 'sort' => 2],
                '/module/crm/support/service-contracts'=> ['name' => 'Service Contracts', 'sort' => 3],
                '/module/crm/support/assets'           => ['name' => 'Assets', 'sort' => 4],
                '/module/crm/support/sms-notifier'     => ['name' => 'SMS Notifier', 'sort' => 5],
                '/module/crm/support/contacts'         => ['name' => 'Contacts', 'sort' => 6],
                '/module/crm/support/organizations'    => ['name' => 'Organizations', 'sort' => 7],
                '/module/crm/support/email-templates'  => ['name' => 'Email Templates', 'sort' => 8],
                '/module/crm/support/reports'          => ['name' => 'Reports', 'sort' => 9],
            ];
            foreach ($supportItems as $link => $sCfg) {
                $conn->table('tblmenumaster_g2g')->where('access_link', $link)->update([
                    'menu_name'  => $sCfg['name'],
                    'parent_id'  => $supportRoot->id,
                    'level'      => 2,
                    'sort_order' => $sCfg['sort'],
                    'status'     => 1,
                ]);
            }
        }

        // 3. Disable nested folder containers
        $conn->table('tblmenumaster_g2g')->whereIn('access_link', self::FOLDER_CONTAINERS)->update(['status' => 0]);
        $conn->table('tblmenumaster_g2g')->whereIn('id', [200, 446, 438, 441, 465])
            ->where('access_link', '!=', '/gtm/campaigns')
            ->update(['status' => 0]);

        // 4. Disable duplicates
        $conn->table('tblmenumaster_g2g')->whereIn('access_link', self::DUPLICATE_LINKS)->update(['status' => 0]);

        // 5. Disable nested sub-items under Market Signals to eliminate nested sidebar navigation
        $conn->table('tblmenumaster_g2g')->whereIn('access_link', self::NESTED_SIGNALS_SUBITEMS)->update(['status' => 0]);

        // 6. Re-parent and configure 28 canonical sub-modules directly under Root
        foreach (self::CANONICAL_LIFECYCLE as $link => $cfg) {
            $conn->table('tblmenumaster_g2g')->where('access_link', $link)->update([
                'parent_id'  => $rootId,
                'level'      => 2,
                'sort_order' => $cfg['sort'],
                'menu_name'  => $cfg['name'],
                'icon'       => null,
                'status'     => 1,
            ]);
        }
    }

    public function down(): void
    {
        $conn = DB::connection($this->getConnection());

        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // Re-enable containers
        $conn->table('tblmenumaster_g2g')->whereIn('access_link', self::FOLDER_CONTAINERS)->update(['status' => 1]);
        $conn->table('tblmenumaster_g2g')->whereIn('access_link', self::DUPLICATE_LINKS)->update(['status' => 1]);
        $conn->table('tblmenumaster_g2g')->whereIn('access_link', self::NESTED_SIGNALS_SUBITEMS)->update(['status' => 1]);

        $mktg = $conn->table('tblmenumaster_g2g')->where('access_link', '/module/crm/marketing')->first();
        if ($mktg) {
            $conn->table('tblmenumaster_g2g')->whereIn('access_link', [
                '/module/crm/marketing/leads',
                '/module/crm/marketing/contacts',
                '/module/crm/marketing/organizations',
                '/module/crm/marketing/campaigns',
                '/module/crm/marketing/outreach-approvals',
                '/module/crm/marketing/signal-inbox',
                '/module/crm/marketing/agent-runs',
                '/module/crm/marketing/automation-rules',
            ])->update(['parent_id' => $mktg->id, 'level' => 3]);
        }

        $sales = $conn->table('tblmenumaster_g2g')->where('access_link', '/module/crm/sales')->first();
        if ($sales) {
            $conn->table('tblmenumaster_g2g')->whereIn('access_link', [
                '/module/crm/sales/opportunities',
                '/module/crm/sales/quotes',
                '/module/crm/sales/products',
                '/module/crm/sales/services',
                '/module/crm/sales/price-books',
                '/module/crm/sales/sales-orders',
                '/module/crm/sales/invoices',
                '/module/crm/sales/vendors',
                '/module/crm/sales/purchase-orders',
                '/module/crm/sales/sms-notifier',
            ])->update(['parent_id' => $sales->id, 'level' => 3]);
        }

        $gtm = $conn->table('tblmenumaster_g2g')->where('access_link', '/gtm')->first();
        if ($gtm) {
            $conn->table('tblmenumaster_g2g')->whereIn('access_link', [
                '/gtm/command-center',
                '/gtm/prospecting',
                '/gtm/playbooks',
                '/gtm/agents',
                '/gtm/deals',
                '/gtm/outreach',
                '/gtm/forecast',
                '/gtm/campaigns',
                '/gtm/customers',
                '/gtm/workflows',
                '/gtm/reports',
                '/gtm/monitoring',
            ])->update(['parent_id' => $gtm->id, 'level' => 3]);
        }
    }

    private function tableExists(string $t): bool
    {
        $conn = DB::connection($this->getConnection());

        if ($conn->getDriverName() === 'sqlite') {
            return Schema::connection($this->getConnection())->hasTable($t);
        }

        return $conn->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$t]
        )->c > 0;
    }
};
