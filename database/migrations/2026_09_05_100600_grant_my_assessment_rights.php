<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let anyone actually open "My Assessment".
 *
 * ── A FINISHED SCREEN NOBODY CAN REACH ──────────────────────────────────────
 *
 * Menu 301 (`/module/lms/learning/my-assessment`) is `status = 1`, sits under
 * an enabled parent, and resolves through content-map-m4 to `CmMyAssessment` —
 * a complete 287-line timed assessment runner. Every part of the chain is
 * correct except one: there is not a single `tblgroupwise_rights_g2g` row for
 * it, on either database, for any profile.
 *
 * `displaySidebarMenu` requires a `can_view` row, so the item has never
 * appeared in anybody's sidebar. The screen was built, wired and shipped, and
 * no employee has ever been able to open it.
 *
 * ── WHO GETS IT ─────────────────────────────────────────────────────────────
 *
 * Everyone who learns. It is the LEARNER's own assessment runner — the
 * counterpart to My Learning, deliberately a different component from the
 * admin assessment workspace (which renders correct answers). So it goes to
 * the same audience as My Learning: employee, admin and HR, plus the extra
 * profiles a tenant happens to define.
 *
 * Granted from each tenant's OWN profiles. `tbluserprofilemaster` is
 * tenant-scoped — live carries Admin/HR for tenant 1 (ids 1, 2) and tenant 6
 * (ids 16, 18) — so an unscoped query hands every tenant rows pointing at
 * another tenant's profiles. That mistake was made once already in
 * 2026_09_05_100500 and is not repeated here.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100600_grant_my_assessment_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100600_grant_my_assessment_rights.php
 */
return new class extends Migration
{
    private const MENU_ID = 301;

    /**
     * A learner-facing screen, so every profile that learns.
     *
     * Matched on role_key rather than name: the display names differ between
     * tenants, the keys do not.
     */
    private const ROLE_KEYS = [
        'employee',
        'administrator',
        'hr_manager',
        'hr_executive',
        'reporting_manager',
        'department_head',
        'executive',
        'auditor',
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        // Only tenants that already have rights configured; a tenant nobody has
        // set up is not silently granted something.
        $tenants = DB::table('tblgroupwise_rights_g2g')
            ->distinct()
            ->whereNotNull('sub_institute_id')
            ->pluck('sub_institute_id');

        foreach ($tenants as $tenant) {
            $profiles = DB::table('tbluserprofilemaster')
                ->whereIn('role_key', self::ROLE_KEYS)
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($profiles as $profileId) {
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('sub_institute_id', $tenant)
                    ->where('menu_id', self::MENU_ID)
                    ->where('profile_id', $profileId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $tenant,
                    'menu_id' => self::MENU_ID,
                    'profile_id' => $profileId,
                    'can_view' => 1,
                    // Sitting an assessment is not an authoring right.
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    // This table has created_at and no updated_at.
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', self::MENU_ID)->delete();
        }
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
