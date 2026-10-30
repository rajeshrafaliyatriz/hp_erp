<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unify Sales & Marketing Intelligence into a single sequential process-oriented
 * lifecycle setup modeled after Talent Management (Hire -> Grow -> Exit -> Admin).
 *
 * This migration:
 * 1. Permanently deletes ghost screen 'Consent & Preferences' (/module/crm/marketing/consent, ID: 500)
 *    and removes its permissions from tblgroupwise_rights_g2g.
 * 2. Deactivates legacy container folders (Marketing [200], Sales [465], GTM Agents [441]) with status = 0.
 * 3. Deactivates redundant duplicate screens:
 *    - Outreach Approvals [499] (consolidated into GTM Outreach)
 *    - CRM Campaigns [453] (consolidated into GTM Multi-Channel Campaigns)
 *    - GTM Deals [456] (consolidated into Sales Pipeline & Deals)
 *    - GTM Reports [463] (consolidated into CRM Reports & Governance)
 *    with status = 0.
 * 4. Reparents Signal Inbox (501) into the Market Signals container (489).
 * 5. Re-parents all 28 sub-modules directly under Root 199 ('Sales & Marketing Intelligence')
 *    with level = 2 and sequential sort orders 1 to 28.
 */
return new class extends Migration
{
    private const ROOT_ID = 199;

    /**
     * Target Process-Oriented Lifecycle Order under Root 199.
     */
    private const LIFECYCLE_ORDER = [
        442 => ['sort' => 1,  'name' => 'Command Center'],
        489 => ['sort' => 2,  'name' => 'Market Signals'],
        443 => ['sort' => 3,  'name' => 'Prospecting'],
        454 => ['sort' => 4,  'name' => 'Playbooks'],
        452 => ['sort' => 5,  'name' => 'Organizations'],
        451 => ['sort' => 6,  'name' => 'Contacts'],
        460 => ['sort' => 7,  'name' => 'Campaigns'],
        457 => ['sort' => 8,  'name' => 'Outreach'],
        201 => ['sort' => 9,  'name' => 'Leads'],
        504 => ['sort' => 10, 'name' => 'Automation Rules'],
        466 => ['sort' => 11, 'name' => 'Opportunities & Deals'],
        459 => ['sort' => 12, 'name' => 'Forecast'],
        467 => ['sort' => 13, 'name' => 'Quotes'],
        468 => ['sort' => 14, 'name' => 'Products'],
        469 => ['sort' => 15, 'name' => 'Services'],
        471 => ['sort' => 16, 'name' => 'Price Books'],
        473 => ['sort' => 17, 'name' => 'Sales Orders'],
        475 => ['sort' => 18, 'name' => 'Invoices'],
        472 => ['sort' => 19, 'name' => 'Vendors'],
        474 => ['sort' => 20, 'name' => 'Purchase Orders'],
        461 => ['sort' => 21, 'name' => 'Customer Success'],
        470 => ['sort' => 22, 'name' => 'SMS Notifier'],
        455 => ['sort' => 23, 'name' => 'Agents'],
        503 => ['sort' => 24, 'name' => 'Agent Run History'],
        462 => ['sort' => 25, 'name' => 'Workflows'],
        487 => ['sort' => 26, 'name' => 'Reports'],
        464 => ['sort' => 27, 'name' => 'Monitoring'],
        202 => ['sort' => 28, 'name' => 'Master Fields'],
    ];

    public function up(): void
    {
        $conn = DB::connection($this->getConnection());

        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // 1. Delete Consent & Preferences (ID 500)
        $consentId = $conn->table('tblmenumaster_g2g')
            ->where('access_link', '/module/crm/marketing/consent')
            ->value('id');

        if ($consentId) {
            if ($this->tableExists('tblgroupwise_rights_g2g')) {
                $conn->table('tblgroupwise_rights_g2g')->where('menu_id', $consentId)->delete();
            }
            $conn->table('tblmenumaster_g2g')->where('id', $consentId)->delete();
        }

        // 2. Disable old containers Marketing (200), Sales (465), GTM (441)
        $conn->table('tblmenumaster_g2g')->whereIn('id', [200, 465, 441])->update(['status' => 0]);

        // 3. Disable duplicates: Outreach Approvals (499), CRM Campaigns (453), GTM Deals (456), GTM Reports (463)
        $conn->table('tblmenumaster_g2g')->whereIn('id', [499, 453, 456, 463])->update(['status' => 0]);

        // 4. Move Signal Inbox (501) under Signals parent (489)
        $conn->table('tblmenumaster_g2g')->where('id', 501)->update([
            'parent_id'  => 489,
            'level'      => 3,
            'sort_order' => 5,
        ]);

        // 5. Reparent and order items directly under Root (199)
        foreach (self::LIFECYCLE_ORDER as $id => $cfg) {
            $conn->table('tblmenumaster_g2g')->where('id', $id)->update([
                'parent_id'  => self::ROOT_ID,
                'level'      => 2,
                'sort_order' => $cfg['sort'],
                'menu_name'  => $cfg['name'],
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

        // 1. Re-enable legacy container folders
        $conn->table('tblmenumaster_g2g')->whereIn('id', [200, 465, 441])->update(['status' => 1]);

        // 2. Re-enable duplicate screens
        $conn->table('tblmenumaster_g2g')->whereIn('id', [499, 453, 456, 463])->update(['status' => 1]);

        // 3. Restore Marketing children back to parent 200
        $conn->table('tblmenumaster_g2g')->whereIn('id', [201, 451, 452, 453, 499, 501, 503, 504])->update([
            'parent_id' => 200,
            'level'     => 3,
        ]);

        // 4. Restore Sales children back to parent 465
        $conn->table('tblmenumaster_g2g')->whereIn('id', [466, 467, 468, 469, 470, 471, 472, 473, 474, 475])->update([
            'parent_id' => 465,
            'level'     => 3,
        ]);
        $conn->table('tblmenumaster_g2g')->where('id', 466)->update(['menu_name' => 'Opportunities']);

        // 5. Restore GTM children back to parent 441
        $conn->table('tblmenumaster_g2g')->whereIn('id', [442, 443, 454, 455, 456, 457, 459, 460, 461, 462, 463, 464])->update([
            'parent_id' => 441,
            'level'     => 3,
        ]);
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
