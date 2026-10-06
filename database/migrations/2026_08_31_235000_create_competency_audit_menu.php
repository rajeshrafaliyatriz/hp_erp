<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu 208 — Audit & Activity Center. A built screen nobody could open.
 *
 * ── THE GAP ─────────────────────────────────────────────────────────────────
 *
 * `hooks/content-map-m2.ts:67` maps `CmAudit` on `submenuId: '208'` and nothing
 * else reaches it. Menu 208 exists on NEITHER database in any state, not even
 * soft-deleted, so the route could never resolve.
 *
 * Behind that one missing row sat a complete subsystem: `cm-audit.tsx` is the
 * only mount of `<ApprovalQueue />`, which is the only consumer of
 * `useApprovalQueue` in the repository, backed by seven `/competency/audit/*`
 * endpoints over 2,038 (dev) / 1,987 (live) rows of `s_competency_activity_log`.
 *
 * And it had a real cost. Two REACHABLE screens can submit for approval — the
 * Competency Library and Framework & Role Mapping — so work went into the queue
 * with no screen able to take it out. Live has carried a pending submission from
 * kalpesh sheth since 2026-08-04 for exactly that reason.
 *
 * ── WHY 38 AND 39 ARE NOT CREATED ALONGSIDE IT ──────────────────────────────
 *
 * The same audit named ids 38, 39 and 208 as missing. Only 208 is a real gap:
 *
 *   39  maps `CmLibrariesTaxonomy` — the SAME component menu 223 already opens
 *       with a working access_link. Creating it would put two names in the
 *       sidebar for one screen.
 *   38  is not a route at all. It survives only in a stale comment.
 *
 * This module's history records removing exactly that kind of duplicate twice
 * (Skill Taxonomy 41, Competency Definitions). The dead `submenuId: '39'` route
 * and the 38 comment are deleted from the content map in the same change, so
 * every M2 screen ends with exactly one door.
 *
 * ── THE ID IS PINNED, AND THE RIGHTS ARE DERIVED ────────────────────────────
 *
 * 208 is written explicitly rather than left to AUTO_INCREMENT: the content map
 * already names it, `tblgroupwise_rights_g2g` joins on `menu_id`, and the two
 * databases have diverged on ids before. Verified free on both.
 *
 * Rights are NOT copied from sibling 223. That sibling grants 87 profiles on dev
 * and 25 on live, and this screen is now gated `profile:admin,hr` at the route —
 * so copying it would put a menu item in ~80 sidebars that answers 403. Instead
 * the grant is DERIVED FROM THE SAME RULE THE API USES: the profiles whose
 * role_key is in RequireProfile's admin/hr aliases (administrator, hr_manager,
 * hr_executive). 30 profiles on dev, 4 on live. The menu appears exactly where it
 * works.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_235000_create_competency_audit_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_235000_create_competency_audit_menu.php
 */
return new class extends Migration
{
    private const MENU_ID = 208;

    private const ACCESS_LINK = '/module/capability-intelligence/audit';

    /** RequireProfile::ALIASES for 'admin' and 'hr', which gate these routes. */
    private const ROLE_KEYS = ['administrator', 'hr_manager', 'hr_executive'];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $exists = DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->exists();

        if (! $exists) {
            // sub_institute_id NULL, matching every other M2 child (34/37/43/154/223):
            // the menu is platform-wide and visibility is decided by the rights rows.
            DB::table('tblmenumaster_g2g')->insert([
                'id'               => self::MENU_ID,
                'menu_name'        => 'Audit & Activity Center',
                'parent_id'        => 2,
                'level'            => 2,
                'page_type'        => 'page',
                'access_link'      => self::ACCESS_LINK,
                'icon'             => null,
                'status'           => 1,
                // Next free slot: the five existing children hold 1-5.
                'sort_order'       => 6,
                'sub_institute_id' => null,
                'menu_type'        => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $profiles = DB::table('tbluserprofilemaster')
            ->whereIn('role_key', self::ROLE_KEYS)
            ->get(['id', 'sub_institute_id']);

        foreach ($profiles as $profile) {
            $already = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', self::MENU_ID)
                ->where('profile_id', $profile->id)
                ->exists();

            if ($already) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'menu_id'          => self::MENU_ID,
                'profile_id'       => $profile->id,
                'can_view'         => 1,
                /*
                 * VIEW ONLY, AND THAT IS NOT A SHORTCUT. Every one of the seven
                 * /competency/audit/* endpoints is read-only — the only write is
                 * the export logging an event about itself. Granting add/edit/
                 * delete would advertise capabilities the API does not have.
                 */
                'can_add'          => 0,
                'can_edit'         => 0,
                'can_delete'       => 0,
                'dashboard_right'  => 0,
                'is_mobile'        => 0,
                'sub_institute_id' => $profile->sub_institute_id,
                'created_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', self::MENU_ID)->delete();
        }

        if ($this->tableExists('tblmenumaster_g2g')) {
            DB::table('tblmenumaster_g2g')
                ->where('id', self::MENU_ID)
                ->where('access_link', self::ACCESS_LINK)
                ->delete();
        }
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue directly. */
    private function tableExists(string $table): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }
};
