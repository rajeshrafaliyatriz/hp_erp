<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE ORGANISATION LEAVES EVERYBODY ELSE'S NAVIGATION, AND THE AUDITOR GAINS
 * THE ONE SCREEN THEY EXIST FOR.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS REPORTED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * "As an employee why am I seeing the organization setting?"
 *
 * `tblgroupwise_rights_g2g` granted the Employee profile `can_view = 1` on
 * Organizational Management, Organization Setup and Organization Profile - and on
 * tenant 6 also Role & Permissions. Organization Profile is the screen showing the
 * company logo, which is also what the earlier "profile image of my organization's
 * setting" report was describing: no avatar code was ever involved.
 *
 * The route gate in `routes/settings.php` now refuses them, so this is no longer a
 * security hole. It is still a broken product: a menu entry that answers 403 is
 * worse than no menu entry, because the person has been invited to click it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND THE AUDITOR COULD NOT REACH THE AUDIT TRAIL
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Found while scoping the revoke, on BOTH databases: ZERO auditor profiles held
 * `can_view` on Audit & Activity Center. `hr_executive` held it on ten tenants and
 * the auditor on none - so the role whose entire purpose is reading the trail had
 * no way to open the screen, while a role the API refuses could see the menu.
 *
 * This grants it. Not scope creep: revoking without granting would have left the
 * auditor role with no reachable function at all, and the API has admitted
 * `administrator` and `auditor` to the trail since it was built.
 *
 * ── `can_view`, NOT `right_view` ─────────────────────────────────────────────
 *
 * The table carries two parallel permission sets - `can_*` tinyints and `right_*`
 * enums. Everything that reads rights reads `can_*`
 * (`tblmenumasterG2gController::hasView` is `($rights->can_view ?? 0) == 1`), and a
 * repo-wide search finds no reader of the enums at all. Writing the enums instead
 * would have been a migration that changed nothing, which is the failure this
 * comment exists to prevent somebody repeating.
 *
 * ── NO `Schema::hasColumn` ──────────────────────────────────────────────────
 *
 * Live is MariaDB 10.1.48, where `Schema::hasColumn()` throws: Laravel's query
 * asks for `generation_expression`, a column that version does not have. Every
 * guard here reads `information_schema` directly.
 */
return new class extends Migration
{
    /**
     * Menus that describe the ORGANISATION rather than somebody's own work.
     *
     * Named explicitly rather than matched by pattern. A regex over menu names is
     * how "Monthly Attendance Report" gets revoked for containing "report".
     */
    private const ORG_MENUS = [
        'Organizational Management',
        'Organization Setup',
        'Organization Profile',
        'Role & Permissions',
        'Guided Setup',
        'Audit & Activity Center',
    ];

    /** The trail, which the auditor must reach. */
    private const AUDIT_MENU = 'Audit & Activity Center';

    /**
     * Role keys that may keep each menu.
     *
     * Everything is administrator-only except the trail, which the API has always
     * admitted the auditor to.
     */
    private const PERMITTED = [
        self::AUDIT_MENU => ['administrator', 'auditor'],
    ];

    private const DEFAULT_PERMITTED = ['administrator'];

    public function up(): void
    {
        if (!$this->tablesPresent()) {
            return;
        }

        $menuIds = $this->menuIds();

        if ($menuIds->isEmpty()) {
            // Nothing to do on a database that has not been given the G2G menus.
            return;
        }

        $revoked = 0;
        $granted = 0;

        foreach ($this->grantsOn($menuIds) as $grant) {
            /*
             * Resolved through RoleKey, the same way the SERVER resolves it, so a
             * legacy profile named "Admin" with no role_key counts as an
             * administrator here exactly as it does at the route gate. Comparing
             * on the display name instead is how the two would disagree, and ten
             * live tenants have precisely that shape.
             */
            $roleKey = \App\Support\RoleKey::forProfileId((int) $grant->profile_id);
            $permitted = self::PERMITTED[$grant->menu_name] ?? self::DEFAULT_PERMITTED;

            if (in_array($roleKey, $permitted, true)) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')
                ->where('id', $grant->id)
                ->update([
                    'can_view' => 0,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                ]);

            $revoked++;
        }

        $granted = $this->grantAuditorTheTrail($menuIds);

        if (function_exists('fwrite')) {
            fwrite(STDOUT, sprintf(
                "  organisation menus: revoked %d grant(s), granted the trail to %d auditor profile(s)\n",
                $revoked, $granted
            ));
        }
    }

    /**
     * Restoring this is deliberately NOT automatic.
     *
     * `up()` sets five flags to 0 across hundreds of rows. It cannot know what
     * each row held before, so a `down()` that guessed - "grant everything back" -
     * would hand every employee on the platform edit rights they never had, which
     * is a worse state than either the before or the after.
     *
     * The exact previous values are captured in
     * `docs/hrit-audit/_reversals/REVERSAL-2026-09-19-org-menu-grants.sql`, keyed
     * on (sub_institute_id, profile_id, menu_id) rather than on row ids, so it
     * survives the table being rebuilt. `down()` reverses only the half it CAN
     * know: the auditor grant this migration created.
     */
    public function down(): void
    {
        if (!$this->tablesPresent()) {
            return;
        }

        $auditMenuId = DB::table('tblmenumaster_g2g')
            ->where('menu_name', self::AUDIT_MENU)->value('id');

        if (!$auditMenuId) {
            return;
        }

        $auditorProfiles = DB::table('tbluserprofilemaster')
            ->where('role_key', 'auditor')->pluck('id');

        // Only the rows this migration inserted - `created_at` is not reliable
        // enough to distinguish them, so they are matched on being the auditor's
        // grant for exactly this menu.
        DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $auditMenuId)
            ->whereIn('profile_id', $auditorProfiles)
            ->delete();
    }

    /** Both tables, via information_schema - see the class note on MariaDB 10.1. */
    private function tablesPresent(): bool
    {
        foreach (['tblgroupwise_rights_g2g', 'tblmenumaster_g2g', 'tbluserprofilemaster'] as $table) {
            $found = DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );

            if ((int) ($found->n ?? 0) === 0) {
                return false;
            }
        }

        return true;
    }

    private function menuIds(): \Illuminate\Support\Collection
    {
        return DB::table('tblmenumaster_g2g')
            ->whereIn('menu_name', self::ORG_MENUS)
            ->pluck('id', 'menu_name');
    }

    private function grantsOn(\Illuminate\Support\Collection $menuIds): \Illuminate\Support\Collection
    {
        return DB::table('tblgroupwise_rights_g2g as r')
            ->join('tblmenumaster_g2g as m', 'm.id', '=', 'r.menu_id')
            ->whereIn('r.menu_id', $menuIds->values())
            ->where(function ($q) {
                // Any flag set at all - a row with can_view 0 but can_edit 1 is
                // still a grant, and leaving it would be a half-revoke.
                $q->where('r.can_view', 1)->orWhere('r.can_add', 1)
                  ->orWhere('r.can_edit', 1)->orWhere('r.can_delete', 1)
                  ->orWhere('r.dashboard_right', 1);
            })
            ->get(['r.id', 'r.profile_id', 'r.sub_institute_id', 'm.menu_name']);
    }

    /** Every auditor profile gets read access to the trail, once. */
    private function grantAuditorTheTrail(\Illuminate\Support\Collection $menuIds): int
    {
        $auditMenuId = $menuIds[self::AUDIT_MENU] ?? null;

        if (!$auditMenuId) {
            return 0;
        }

        $granted = 0;

        $auditors = DB::table('tbluserprofilemaster')
            ->where('role_key', 'auditor')
            ->get(['id', 'sub_institute_id']);

        foreach ($auditors as $auditor) {
            $existing = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $auditMenuId)
                ->where('profile_id', $auditor->id)
                ->first(['id']);

            if ($existing) {
                // Already has a row - flip the read flag on rather than inserting
                // a duplicate the menu builder would then see twice.
                DB::table('tblgroupwise_rights_g2g')
                    ->where('id', $existing->id)
                    ->update(['can_view' => 1]);
            } else {
                DB::table('tblgroupwise_rights_g2g')->insert([
                    'menu_id' => $auditMenuId,
                    'profile_id' => $auditor->id,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    // TEXT on both databases, so it is written as a string.
                    'sub_institute_id' => (string) $auditor->sub_institute_id,
                    'created_at' => now(),
                ]);
            }

            $granted++;
        }

        return $granted;
    }
};
