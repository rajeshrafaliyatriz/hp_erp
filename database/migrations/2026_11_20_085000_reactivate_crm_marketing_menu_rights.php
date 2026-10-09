<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reactivates a disabled, never-finished CRM menu tree rather than creating a
 * new, colliding one.
 *
 * `tblmenumaster_g2g` already has, on BOTH databases, since 2025-06-18:
 *   199 CRM (root, parent_id=0, status=0/hidden)
 *   200 Marketing (parent=199)
 *   201 Leads (parent=200)
 *   202 Master Fields (parent=199)
 * An internal audit (Docs/phase3/10-open-questions.md, Q-A4) reviewed this in
 * 2026-08 and found no application code anywhere for it - confirmed again
 * here by exhaustive git-history search across every branch/ref in both
 * repos. It is safe, abandoned scaffolding, not live functionality to
 * preserve rights-wise.
 *
 * This migration: flips 199's status 0->1; adds 3 new leaves under Marketing
 * (200) for Contacts/Organizations/Campaigns, matching Leads (201)'s exact
 * shape (nested access_link under /module/crm/marketing/..., NOT the flat
 * 2-segment convention used elsewhere - this reused tree already established
 * its own nesting style, and it is kept rather than fought); and tops up
 * administrator rights (full CRUD) on all 7 ids on both connections, without
 * touching the existing non-admin view-only rows already present on `live`
 * for 200/201 (pre-existing policy, not this migration's to revoke).
 *
 * No id-pinning needed anywhere here: 199/200/201/202 already exist
 * identically on both databases (verified directly), and the 3 new leaves
 * are matched by `access_link` string by the frontend, never by numeric id.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_20_085000_reactivate_crm_marketing_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_20_085000_reactivate_crm_marketing_menu_rights.php
 */
return new class extends Migration
{
    private const CRM_ID = 199;
    private const MARKETING_ID = 200;
    private const LEADS_ID = 201;
    private const MASTER_FIELDS_ID = 202;

    /** Leaf label => flat trailing slug under /module/crm/marketing/. */
    private const NEW_LEAVES = [
        'Contacts' => 'contacts',
        'Organizations' => 'organizations',
        'Campaigns' => 'campaigns',
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
                . 'database - refusing to reactivate/modify something else by mistake.'
            );
        }

        DB::table('tblmenumaster_g2g')->where('id', self::CRM_ID)->update([
            'status' => 1,
            'updated_at' => now(),
        ]);

        $newIds = [];

        foreach (self::NEW_LEAVES as $label => $slug) {
            $newIds[] = $this->createLeaf($label, $slug);
        }

        $this->topUpAdminRights(array_merge(
            [self::CRM_ID, self::MARKETING_ID, self::LEADS_ID, self::MASTER_FIELDS_ID],
            $newIds
        ));
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        // Re-disable the root rather than deleting it - it predates this
        // migration and other things may reference it; only the 3 leaves
        // THIS migration created are removed.
        DB::table('tblmenumaster_g2g')->where('id', self::CRM_ID)->update([
            'status' => 0,
            'updated_at' => now(),
        ]);

        $links = array_map(fn ($slug) => '/module/crm/marketing/' . $slug, self::NEW_LEAVES);

        $ids = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::MARKETING_ID)
            ->whereIn('access_link', $links)
            ->pluck('id');

        if ($ids->isNotEmpty() && $this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        if ($ids->isNotEmpty()) {
            DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
        }

        // Admin top-up rights on the 4 pre-existing ids are intentionally
        // left in place on down() - they predate this migration's own
        // concerns and removing them could strip access a human granted
        // separately in the meantime.
    }

    private function createLeaf(string $label, string $slug): int
    {
        $accessLink = '/module/crm/marketing/' . $slug;

        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::MARKETING_ID)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', self::MARKETING_ID)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $label,
            'parent_id' => self::MARKETING_ID,
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
            'Contacts' => 'mdi mdi-account-box-outline',
            'Organizations' => 'mdi mdi-domain',
            'Campaigns' => 'mdi mdi-bullhorn',
            default => 'mdi mdi-card-account-details',
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
                    // Top up to full CRUD rather than skipping - an existing
                    // row (e.g. live's pre-existing view-only grant) should
                    // not block an administrator from actually managing the
                    // module once it is real.
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
