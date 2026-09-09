<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEVEN ORGANIZATION SCREENS THAT ARE ON, UNDER PARENTS THAT ARE OFF.
 *
 *   php artisan migrate --path=database/migrations/2026_09_08_130000_close_orphaned_organization_menus.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_08_130000_close_orphaned_organization_menus.php
 *
 * ── FOUND BY RE-AUDIT, NOT BY REPORT ────────────────────────────────────────
 *
 * Cross-checking every Organizational Management menu against the frontend's
 * content maps turned up seven leaf rows with `status = 1` that map to NO
 * COMPONENT AT ALL:
 *
 *     27  Send SMS to User            under 9   Communication Tools  (status 0)
 *     28  Send Notification User      under 9   Communication Tools  (status 0)
 *     29  Send Email User             under 9   Communication Tools  (status 0)
 *     30  Send Email Other User       under 9   Communication Tools  (status 0)
 *     31  Send WhatsApp User          under 9   Communication Tools  (status 0)
 *     32  Template Management         under 10  Template Management  (status 0)
 *     33  Complaint Mgmt              under 11  Complaint Mgmt       (status 0)
 *
 * All three parents are disabled, so the sidebar drops the branch and nobody
 * has ever seen these. They are legacy Blade screens whose parents were turned
 * off deliberately; the children were left on.
 *
 * ── WHAT THAT IS DOING TO THE PERMISSION DATA ───────────────────────────────
 *
 *     dev    7 grants across the seven
 *     live   203 GRANTS, every one with can_view = 1
 *
 * Two hundred and three rows that read, in the Role & Permissions matrix, as
 * "this role can open Send Email User". None of them can, and the screen would
 * render blank if they could. It is the same defect as menu 169 (closed in
 * 2026_09_08_120000), seven times over.
 *
 * ── THIS IS PART OF A LARGER PATTERN, DELIBERATELY NOT FIXED HERE ───────────
 *
 * The same query across the WHOLE catalogue finds 46 orphaned screens carrying
 * 1,311 inert grants on live. The other 39 sit under Reports, HRIT and CRM -
 * outside this module, and inside another work stream's active area. Closing
 * them is the same one-line change and should be a deliberate decision, not a
 * side effect of an Organization migration.
 *
 * Re-run the finding at any time with:
 *
 *     SELECT m.id, m.menu_name, m.parent_id
 *       FROM tblmenumaster_g2g m
 *       JOIN tblmenumaster_g2g p ON p.id = m.parent_id
 *      WHERE m.status = 1 AND p.status = 0;
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────
 *
 * It does not delete the 203 rights rows. They are inert once the menus are off,
 * and they are production permission data - removing rows that cannot be
 * restored, to tidy a table, is not a schema fix.
 *
 * ── LIVE ────────────────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48. One UPDATE to one column on at most seven rows. Every row is
 * re-checked against the shape described above before it is touched, so a
 * renumbered or re-purposed catalogue is left alone.
 */
return new class extends Migration
{
    /** menu id => the parent it must still hang off, and its access_link. */
    private const ORPHANS = [
        27 => [9,  '/module/organizational-management/communication-tools/send-sms-to-user'],
        28 => [9,  '/module/organizational-management/communication-tools/send-notification-user'],
        29 => [9,  '/module/organizational-management/communication-tools/send-email-user'],
        30 => [9,  '/module/organizational-management/communication-tools/send-email-other-user'],
        31 => [9,  '/module/organizational-management/communication-tools/send-whatsapp-user'],
        32 => [10, '/module/organizational-management/template-management/template-management'],
        33 => [11, '/module/organizational-management/complaint-mgmt/complaint-mgmt'],
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (self::ORPHANS as $menuId => [$parentId, $accessLink]) {
            $menu = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first();

            // Still the row this migration was written about?
            if (!$menu
                || (int) $menu->parent_id !== $parentId
                || trim((string) $menu->access_link) !== $accessLink
                || (int) $menu->status === 0) {
                continue;
            }

            // And is the parent still closed? If somebody has re-enabled
            // Communication Tools since this was written, they meant to, and
            // this must not quietly undo half of it.
            $parentStatus = DB::table('tblmenumaster_g2g')->where('id', $parentId)->value('status');

            if ($parentStatus === null || (int) $parentStatus !== 0) {
                continue;
            }

            DB::table('tblmenumaster_g2g')
                ->where('id', $menuId)
                ->update(['status' => 0, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (self::ORPHANS as $menuId => [, $accessLink]) {
            DB::table('tblmenumaster_g2g')
                ->where('id', $menuId)
                ->where('access_link', $accessLink)
                ->update(['status' => 1, 'updated_at' => now()]);
        }
    }

    /** information_schema, not Schema::hasTable() - that throws on live's 10.1. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
