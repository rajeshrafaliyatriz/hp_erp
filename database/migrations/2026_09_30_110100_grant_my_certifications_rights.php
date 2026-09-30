<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let people actually open "My Certifications" - including the ancestor row.
 *
 * ── TWO INSERTS, NOT ONE ────────────────────────────────────────────────────
 *
 * `displaySidebarMenu` requires a `can_view` row for a menu item, and it also
 * requires one for every ANCESTOR: a granted leaf under an ungranted parent is
 * invisible. That is F-209, where 209 employees could not find Leave because
 * the leaf was granted and the container was not.
 *
 * THIS IS NOT HYPOTHETICAL HERE. Measured before writing this migration, on the
 * app database, tenant 6:
 *
 *     profile 17 "Employee"   menu 3 (Talent Management) = NO ROW
 *                             menu 158 (Certifications)  = can_view 1
 *
 * The leaf was granted and the parent was not, so employees on that database
 * hold a right to the certification screen that they have never been able to
 * see. Granting only the new leaf would have reproduced F-209 exactly.
 *
 * ── AND WHY 2026_09_30_110200 EXISTS ────────────────────────────────────────
 *
 * Granting the ancestor makes every ALREADY-GRANTED leaf under it visible too -
 * including that stale menu 158 right. So this migration would hand employees
 * the full admin certification screen as a side effect of giving them their own
 * page. The companion migration removes that stale right; the two belong
 * together and are separate files only so the revoke can be reverted on its
 * own.
 *
 * ── WHO GETS IT ─────────────────────────────────────────────────────────────
 *
 * Everybody. Every role holds their own certificates, and the endpoint behind
 * this row (GET /competency/my-certifications) takes no subject parameter, so
 * there is nothing for a profile gate to protect - the same reasoning as
 * my-capability and my-rating.
 *
 * Read-only: can_view 1, can_add/can_edit/can_delete 0. HR remains the issuer
 * of record. An employee seeing their own certificate is not an authoring
 * right.
 *
 * ── PROFILES ARE RESOLVED PER TENANT, AND PER DATABASE ──────────────────────
 *
 * `tbluserprofilemaster` is tenant-scoped AND the ids differ between hosts -
 * measured: "Reporting Manager" is id 69 on app and 46 on live. So profiles are
 * matched on `role_key` within each tenant, never by id. An unscoped or
 * id-based query would hand one tenant rows pointing at another tenant's
 * profiles; that mistake was made once in 2026_09_05_100500 and is not repeated.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_110100_grant_my_certifications_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110100_grant_my_certifications_rights.php
 */
return new class extends Migration
{
    private const MENU_ID   = 401;
    /** Talent Management - the container the new row sits in. */
    private const ANCESTOR  = 3;

    /** Everyone, because everyone holds their own certificates. */
    private const ROLE_KEYS = [
        'employee',
        'administrator',
        'hr_manager',
        'hr_executive',
        'reporting_manager',
        'department_head',
        'executive',
        'auditor',
        'recruiter',
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        /*
         * Only tenants that already have rights configured; a tenant nobody has
         * set up is not silently granted something.
         *
         * FILTERED TO PLAIN INTEGERS, AND THAT IS NOT COSMETIC.
         * `tblgroupwise_rights_g2g.sub_institute_id` is `text` and holds three
         * shapes - an id, NULL, and a CSV string '1,2,3,4,5,6,7,8,9,10,11'.
         * `tbluserprofilemaster.sub_institute_id` is `bigint`, so comparing the
         * CSV string against it makes MySQL coerce '1,2,3,...' to 1 - and
         * tenant 1's profiles then match BOTH the '1' iteration and the CSV
         * one, getting two rights rows each. Measured: that produced 9 junk
         * rows on app and 3 on live before this filter existed.
         */
        $tenants = DB::table('tblgroupwise_rights_g2g')
            ->distinct()
            ->whereNotNull('sub_institute_id')
            ->pluck('sub_institute_id')
            ->filter(static fn ($t) => ctype_digit(trim((string) $t)))
            ->values();

        foreach ($tenants as $tenant) {
            $profiles = DB::table('tbluserprofilemaster')
                ->whereIn('role_key', self::ROLE_KEYS)
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($profiles as $profileId) {
                $this->grant($tenant, self::MENU_ID, $profileId);

                /*
                 * The container. Only added when absent - an existing row may
                 * carry rights an admin set deliberately, and this must not
                 * overwrite them. Without this the new leaf is invisible.
                 */
                $this->grant($tenant, self::ANCESTOR, $profileId);
            }
        }
    }

    public function down(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        /*
         * Only the leaf. The ancestor rows are deliberately left behind: by the
         * time this is rolled back other leaves may depend on them, and
         * removing a container right would hide screens this migration never
         * granted.
         */
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', self::MENU_ID)->delete();
    }

    private function grant($tenant, int $menuId, $profileId): void
    {
        /*
         * UNSCOPED ON TENANT, DELIBERATELY - this must match how the right is
         * READ. displaySidebarMenu queries `where('profile_id', $id)` with no
         * tenant predicate and then keyBy('menu_id'), so any row naming this
         * profile and menu is already in force whatever its sub_institute_id
         * says. Checking with a tenant predicate the reader does not use is how
         * a second row gets added for a right that is already granted, and
         * keyBy over an unordered query then picks between them arbitrarily.
         *
         * A profile belongs to exactly one tenant, so profile_id + menu_id is a
         * complete key here. Same reasoning as the unscoped delete in
         * storeGroupwiseRightsG2g.
         */
        $exists = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->where('profile_id', $profileId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->insert([
            'sub_institute_id' => $tenant,
            'menu_id'          => $menuId,
            'profile_id'       => $profileId,
            'can_view'         => 1,
            // Read-only. Seeing your own certificate is not an authoring right.
            'can_add'          => 0,
            'can_edit'         => 0,
            'can_delete'       => 0,
            'dashboard_right'  => 0,
            'is_mobile'        => 0,
            // This table has created_at and no updated_at.
            'created_at'       => now(),
        ]);
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
