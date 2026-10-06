<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stop offering employees three doors the API refuses to open.
 *
 * ── WHAT IS WRONG ───────────────────────────────────────────────────────────
 *
 * On live, tenant 6 grants the Employee profile `can_view` on:
 *
 *   76  Administration
 *   84  Course Builder
 *   85  Administration & Governance
 *
 * Every controller behind those screens guards on admin/hr
 * (LmsCourseController, LmsGovernanceController, LmsPartnerController,
 * LmsAssessmentController). So an employee who clicks Course Builder gets the
 * wizard, fills it in, presses Save, and is told their profile is not
 * permitted — after doing the work. Governance is the same: the screen opens,
 * the user list and audit log refuse.
 *
 * The menu and the API disagreed, and the API is right: these are
 * administrative surfaces. Dev already reflects that (Employee has none of the
 * three); live did not. This aligns live with dev and with the code.
 *
 * ── WHY REVOKING IS SAFE ────────────────────────────────────────────────────
 *
 * Nothing is being taken away that worked. Every write behind these menus
 * already answers 403 for an employee, and the two reads that did not
 * (governance `kpis` and `roles`) are guarded in the same change. Removing the
 * menu removes a door that was already locked, not access to a working feature.
 *
 * Employee learning surfaces are untouched: Learning Dashboard, Learning
 * Catalog, My Learning, My Assessment, Assignments, Sessions, Certifications.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_05_100700_align_lms_admin_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_05_100700_align_lms_admin_menu_rights.php
 */
return new class extends Migration
{
    /** The three administrative LMS menus. */
    private const ADMIN_MENUS = [76, 84, 85];

    /** Profiles that must not see them. */
    private const LEARNER_ROLE_KEYS = ['employee'];

    public function up(): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $learnerProfiles = DB::table('tbluserprofilemaster')
            ->whereIn('role_key', self::LEARNER_ROLE_KEYS)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($learnerProfiles->isEmpty()) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')
            ->whereIn('menu_id', self::ADMIN_MENUS)
            ->whereIn('profile_id', $learnerProfiles)
            ->delete();
    }

    /**
     * Deliberately NOT reversible.
     *
     * `down()` would have to re-grant an employee access to Course Builder and
     * Governance, which is the state this migration exists to correct. Undoing
     * a security alignment by rolling back is not a capability worth having;
     * an administrator who genuinely wants it can grant it through the
     * permission matrix, which is what that screen is for.
     */
    public function down(): void
    {
        // Intentionally empty. See above.
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
