<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `2026_10_05_150000_create_document_library_menu_right.php` seeded Document
 * Library's `can_view` right administrator-only, matching Event Bus/Audit's
 * own precedent at the time. Document Library has since grown a dedicated
 * self-service surface for ordinary employees (My Department Documents,
 * personal "My Drive"/payslips/offer letters) - it was never meant to be an
 * admin-only tool, and the administrator-only seed left every other profile,
 * in every tenant, with no row for this menu at all. Confirmed live: an
 * Employee-profile test account (tbluser.id=63, sub_institute_id=6) sees no
 * Document Library entry anywhere because of exactly this gap.
 *
 * This backfills `can_view = 1` for EVERY profile that doesn't already have a
 * row for this menu - not just a role-key subset - across every tenant, same
 * broad-across-tenants shape the original migration already used, just
 * without its role-key filter. One-time backfill: a profile created after
 * this runs is not covered unless this app's profile-creation flow already
 * copies default rights from elsewhere - not investigated here, out of scope
 * for this fix.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_21_120000_grant_document_library_menu_right_to_all_profiles.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_21_120000_grant_document_library_menu_right_to_all_profiles.php
 */
return new class extends Migration
{
    private const PARENT_ID = 1;
    private const ACCESS_LINK = '/documents';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g') && $this->tableExists('tbluserprofilemaster');

        if ($menuId === null || ! $hasRights) {
            return;
        }

        // Profiles that already hold a row for this menu (the administrator-only
        // seed, plus anything granted since) - skip these, never touch an
        // existing row's can_add/can_edit/can_delete/dashboard_right values.
        $alreadyGranted = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->pluck('profile_id')
            ->all();

        $profiles = DB::table('tbluserprofilemaster')
            ->whereNotIn('id', $alreadyGranted)
            ->get(['id', 'sub_institute_id']);

        foreach ($profiles as $profile) {
            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $profile->sub_institute_id,
                'menu_id' => $menuId,
                'profile_id' => $profile->id,
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'dashboard_right' => 0,
                'is_mobile' => 0,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately a no-op: rolling back cannot distinguish rows this
        // migration inserted from rows the original administrator-only
        // migration (or a manual grant) already created, since both write
        // the identical shape into the same table with no marker of origin.
        // Reverting would risk revoking access this migration was never
        // responsible for granting.
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
