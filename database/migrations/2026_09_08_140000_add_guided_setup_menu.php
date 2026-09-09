<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GIVE THE SETUP WIZARD A DOOR.
 *
 *   php artisan migrate --path=database/migrations/2026_09_08_140000_add_guided_setup_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_08_140000_add_guided_setup_menu.php
 *
 * ── THE SAME BUG AS READINESS GATES, ON THE SCREEN THAT MATTERS MOST ────────
 *
 * `/organization/setup` is where a new organisation is walked through its own
 * configuration - profile, roles, modules, departments, people, capability. It
 * is finished, it is measured against the tenant's real tables, and it has
 * NOTHING LINKING TO IT.
 *
 * A grep across the whole frontend finds exactly one reference: a redirect from
 * the retired `/settings/portal-review` URL. It is in no sidebar, no dashboard
 * card, no menu. The only way in is to type the address - which is precisely
 * how Readiness Gates was found unreachable, and how 561 lines of setup wizard
 * sat unopened before that.
 *
 * ── access_link IS AN APP ROUTE, NOT A /module/ PATH ────────────────────────
 *
 * Deliberate, and there is precedent: menu 300 (Main Dashboard) carries
 * `/dashboard`. The sidebar pushes a node's access_link directly, so a row can
 * point anywhere in the application.
 *
 * It matters here because the wizard owns the whole screen - rail on the left,
 * one step on the right, its own header with an exit. Mounting it through the
 * content map would put a full-screen wizard inside the sidebar shell, with two
 * sets of chrome arguing about who owns the page.
 *
 * ── SORTED FIRST, ON PURPOSE ────────────────────────────────────────────────
 *
 * sort_order 0 puts "Guided Setup" above Organization Setup, User Management and
 * the rest. For an organisation that has just been created this is the first
 * thing its administrator should see, and for one that finished long ago it
 * reads as done the moment they open it - every line is counted, so a completed
 * organisation is not nagged.
 *
 * ── WHO GETS IT ─────────────────────────────────────────────────────────────
 *
 * Administrator and HR. The checklist is readable by any member - the endpoint
 * behind it is only `api.token` - but the actions it leads to are theirs.
 *
 * Only tenants that already have rights rows are touched: writing a grant for an
 * organisation that has never had a sidebar would make this the one screen it
 * can see, which is stranger than seeing none.
 *
 * MariaDB 10.1.48 on live: existence checked through information_schema because
 * Schema::hasTable() throws there. The catalogue is GLOBAL - sub_institute_id is
 * NULL on every menu row - so the menu carries no tenant while every rights row
 * does.
 */
return new class extends Migration
{
    private const ACCESS_LINK = '/organization/setup';
    private const MENU_NAME = 'Guided Setup';

    /** Organizational Management. */
    private const PARENT_ID = 1;

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $existing = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if ($existing) {
            $menuId = (int) $existing;
        } else {
            $menuId = (int) DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => self::MENU_NAME,
                'parent_id' => self::PARENT_ID,
                'level' => 2,
                'access_link' => self::ACCESS_LINK,
                'page_type' => 'page',
                'status' => 1,
                // First. See the note above.
                'sort_order' => 0,
                'sub_institute_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tenants = DB::table('tblgroupwise_rights_g2g')
            ->whereNotNull('sub_institute_id')
            ->distinct()
            ->pluck('sub_institute_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        foreach ($tenants as $tenant) {
            $profiles = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenant)
                ->where(function ($q) {
                    $q->whereIn('role_key', ['administrator', 'hr_manager'])
                      ->orWhereIn('name', ['Admin', 'HR']);
                })
                ->pluck('id');

            foreach ($profiles as $profileId) {
                $already = DB::table('tblgroupwise_rights_g2g')
                    ->where('profile_id', $profileId)
                    ->where('menu_id', $menuId)
                    ->exists();

                if ($already) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'menu_id' => $menuId,
                    'profile_id' => $profileId,
                    'sub_institute_id' => $tenant,
                    'can_view' => 1,
                    // Nothing on the wizard is created or deleted from the menu
                    // itself; each step's own screen carries its own rights.
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);
            }
        }

        $this->ensureParentVisible($menuId);
    }

    public function down(): void
    {
        $menuId = DB::table('tblmenumaster_g2g')
            ->where('access_link', self::ACCESS_LINK)
            ->value('id');

        if (!$menuId) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    /**
     * Anyone granted the child must be able to see Organizational Management,
     * or displaySidebarMenu skips the module before reaching this row.
     */
    private function ensureParentVisible(int $menuId): void
    {
        $holders = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $menuId)
            ->get(['profile_id', 'sub_institute_id']);

        foreach ($holders as $holder) {
            $hasParent = DB::table('tblgroupwise_rights_g2g')
                ->where('profile_id', $holder->profile_id)
                ->where('menu_id', self::PARENT_ID)
                ->where('can_view', 1)
                ->exists();

            if ($hasParent) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'menu_id' => self::PARENT_ID,
                'profile_id' => $holder->profile_id,
                'sub_institute_id' => $holder->sub_institute_id,
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

    /** Schema::hasTable() throws on MariaDB 10.1 - information_schema does not. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
