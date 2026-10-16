<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a regression introduced by the previous migration in this batch
 * (2026_11_29_080200_add_signals_menu_branch.php), caught by directly
 * simulating tblmenumasterG2gController's real tree-building logic for a
 * Reporting Manager profile after that migration ran: Signals previously
 * had ZERO tblmenumaster_g2g rows, so no profile needed can_view on
 * anything to "reach" it. Once Signals became a real branch under menu 199
 * (Sales & Marketing), every profile granted rights on the Signals rows
 * also needs can_view=1 on 199 itself - buildMenuTree requires can_view at
 * EVERY ancestor level, not just the leaf. hr_manager, hr_executive, and
 * reporting_manager got the Signals grants but none of the three had any
 * prior reason to hold can_view on 199 (they never had CRM access before
 * either) - confirmed by direct query: all three had NULL rows on menu_id
 * 199 after the Signals migration ran, while they correctly had can_view=1
 * on the new Signals rows themselves.
 *
 * Same union-grant pattern as the GTM re-parenting migration's own
 * remediation: add can_view=1 on 199 for any profile that holds can_view=1
 * on ANY of the 5 Signals rows this batch created, never subtracting.
 * Administrator already has full rights on 199 from pre-existing migrations
 * so is unaffected either way.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_29_080300_grant_sales_marketing_root_view_for_signals_profiles.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_29_080300_grant_sales_marketing_root_view_for_signals_profiles.php
 */
return new class extends Migration
{
    private const SALES_MARKETING_ID = 199;
    private const SIGNALS_LINKS = ['/module/crm/signals', '/signals', '/ingestion', '/portfolio', '/partners'];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g') || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $signalsMenuIds = DB::table('tblmenumaster_g2g')->whereIn('access_link', self::SIGNALS_LINKS)->pluck('id');

        if ($signalsMenuIds->isEmpty()) {
            return;
        }

        $viewers = DB::table('tblgroupwise_rights_g2g')
            ->whereIn('menu_id', $signalsMenuIds)
            ->where('can_view', 1)
            ->get(['profile_id', 'sub_institute_id'])
            ->unique('profile_id');

        foreach ($viewers as $viewer) {
            $existing = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', self::SALES_MARKETING_ID)
                ->where('profile_id', $viewer->profile_id)
                ->first(['id', 'can_view']);

            if ($existing === null) {
                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $viewer->sub_institute_id,
                    'menu_id' => self::SALES_MARKETING_ID,
                    'profile_id' => $viewer->profile_id,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);

                continue;
            }

            if ((int) $existing->can_view !== 1) {
                DB::table('tblgroupwise_rights_g2g')->where('id', $existing->id)->update(['can_view' => 1]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately a no-op, same precedent as the GTM remediation this
        // migration mirrors: a can_view grant this migration's up() added
        // should not be silently stripped by a rollback, since a human may
        // be relying on it by then. If this batch is ever fully reverted,
        // remove these rows by hand after confirming nothing else now
        // depends on them.
    }

    /** MariaDB 10.1 (live) cannot run Schema::hasTable(); sqlite (PHPUnit) has no information_schema. */
    private function tableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
