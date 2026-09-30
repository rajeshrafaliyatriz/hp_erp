<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant the two self-service rows to every profile that can open Talent.
 *
 * ── WHY role_key WAS THE WRONG KEY ──────────────────────────────────────────
 *
 * 2026_09_30_110100 and _110400 granted "My Certifications" and "My Capability"
 * to profiles matched on `role_key`, with the stated reason that display names
 * differ between tenants and the keys do not. That reasoning is sound and the
 * rule is still incomplete, because on live `role_key` IS OFTEN NULL:
 *
 *   live   30 of 42 profiles have role_key = NULL
 *   app    19 of 112
 *
 * And they are not obscure rows. They are the profiles named "Admin", "HR" and
 * "Employee" on tenants 2, 3, 4, 5 and 7 - every live tenant except 1 and 6,
 * whose profiles were backfilled. Measured after _110100 ran: 29 profiles held
 * a can_view row for Talent Management (menu 3) and no row for menu 401, so the
 * new leaf was invisible to them. An employee on tenant 4 could open Talent
 * Management and not find their own certifications.
 *
 * That is F-209 from the other direction - there the parent was missing and the
 * leaf was granted; here the parent is granted and the leaf is missing - and it
 * is the same lesson: A GRANT THAT LOOKS COMPLETE AND IS NOT LOOKS EXACTLY LIKE
 * A GRANT THAT IS.
 *
 * ── THE RULE THIS USES INSTEAD ──────────────────────────────────────────────
 *
 * If a profile can already open Talent Management, it gets the two rows inside
 * it that show a person their OWN record. No role_key, no name matching, no
 * per-tenant profile list - just "can you open the module this lives in".
 *
 * ── WHY THAT CANNOT OVER-GRANT ──────────────────────────────────────────────
 *
 * Both endpoints behind these rows take NO SUBJECT PARAMETER
 * (MyCertificationsController) or resolve the subject through
 * competencySubject() (the capability gap). So whoever the profile belongs to,
 * the response contains that caller's own records and nobody else's. There is
 * no configuration of this grant that exposes one person's data to another,
 * which is exactly why it is safe to apply broadly - and it is the whole
 * argument for building these as separate no-subject endpoints rather than as
 * tabs on the admin screen.
 *
 * Read-only: can_view 1, everything else 0.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_110500_grant_self_service_to_every_talent_profile.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110500_grant_self_service_to_every_talent_profile.php
 */
return new class extends Migration
{
    /** Talent Management - the container. */
    private const ANCESTOR = 3;

    /** The employee's own screens inside it. */
    private const LEAVES = [401, 404];

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        /*
         * Every profile that can open Talent Management.
         *
         * Read unscoped on tenant, because displaySidebarMenu reads rights
         * unscoped too - `where('profile_id', $id)` with no tenant predicate.
         * A row whose sub_institute_id is NULL or a CSV string still grants the
         * menu, so it still has to count here.
         */
        $profileIds = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::ANCESTOR)
            ->where('can_view', 1)
            ->distinct()
            ->pluck('profile_id');

        if ($profileIds->isEmpty()) {
            return;
        }

        // Soft-deleted profiles are skipped: they cannot sign in, and giving
        // them rights rows only makes the next audit harder to read.
        $profiles = DB::table('tbluserprofilemaster')
            ->whereIn('id', $profileIds->all())
            ->whereNull('deleted_at')
            ->get(['id', 'sub_institute_id']);

        foreach ($profiles as $profile) {
            foreach (self::LEAVES as $menuId) {
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $profile->sub_institute_id,
                    'menu_id'          => $menuId,
                    'profile_id'       => $profile->id,
                    'can_view'         => 1,
                    'can_add'          => 0,
                    'can_edit'         => 0,
                    'can_delete'       => 0,
                    'dashboard_right'  => 0,
                    'is_mobile'        => 0,
                    'created_at'       => now(),
                ]);
            }
        }
    }

    /**
     * Nothing to undo here.
     *
     * The rows this adds are indistinguishable from the ones _110100 and _110400
     * added, and those migrations' own down() methods already delete every row
     * for their menu. Deleting them again from here would remove rights those
     * rollbacks are responsible for.
     */
    public function down(): void
    {
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
