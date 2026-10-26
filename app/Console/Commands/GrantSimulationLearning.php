<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Offers Simulation Learning to the learners of ONE organisation: a view-only grant on the `Simulation Learning` menu row to every profile
 * of that organisation that already holds My Learning. The migration that creates the row grants it to the people who author in the LMS
 * (administrator and HR); which organisations offer simulations to their employees is decided here, per organisation, so no organisation id
 * is written into code.
 *
 *   php artisan simulations:grant --tenant=6 --dry-run     (shows who would get it, changes nothing)
 *   php artisan simulations:grant --tenant=6
 *   php artisan simulations:grant --tenant=6 --revoke      (takes the view-only grants of this command away again)
 *   php artisan simulations:grant --tenant=6 --database=live
 *
 * The menu rows are found by access_link, never by id (the ids differ between the default and live connections). Rows are matched on
 * profile and menu only - the rights table's own organisation column holds ids, NULL and CSV lists and is never used to scope a change.
 */
class GrantSimulationLearning extends Command
{
    private const MENU_LINK = '/module/lms/simulation-learning';
    private const LEARNER_LINK = '/module/lms/learning/my-learning';

    protected $signature = 'simulations:grant
        {--tenant= : The organisation (sub_institute_id)}
        {--dry-run : Show what would change and change nothing}
        {--revoke : Remove the view-only grants this command gives}
        {--database= : The connection to change (default: the application\'s default)}';

    protected $description = 'Gives the learners of one organisation view access to Simulation Learning (or takes that grant away again).';

    public function handle(): int
    {
        if ($name = $this->option('database')) {
            DB::setDefaultConnection($name);
        }
        $tenant = $this->option('tenant');
        if ($tenant === null || ! ctype_digit((string) $tenant)) {
            $this->error('Give --tenant=<organisation id>.');

            return self::INVALID;
        }

        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', self::MENU_LINK)->value('id');
        $learnerMenu = DB::table('tblmenumaster_g2g')->where('access_link', self::LEARNER_LINK)->value('id');
        if ($menuId === null || $learnerMenu === null) {
            $this->error('The Simulation Learning menu row (or My Learning) does not exist on this connection. Run the menu migration first.');

            return self::FAILURE;
        }

        // the profiles of this organisation (tbluserprofilemaster.sub_institute_id is the reliable owner)
        $profiles = DB::table('tbluserprofilemaster')->where('sub_institute_id', (int) $tenant)->whereNull('deleted_at')->pluck('id');
        $dry = (bool) $this->option('dry-run');

        if ($this->option('revoke')) {
            $rows = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->whereIn('profile_id', $profiles)
                ->where('can_add', 0)->where('can_edit', 0)->where('can_delete', 0);
            $n = (clone $rows)->count();
            if (! $dry) {
                $rows->delete();
            }
            $this->info(($dry ? 'Would remove ' : 'Removed ') . "{$n} view-only grant(s) for organisation {$tenant}.");

            return self::SUCCESS;
        }

        $given = 0;
        foreach (DB::table('tblgroupwise_rights_g2g')->where('menu_id', $learnerMenu)->where('can_view', 1)->whereIn('profile_id', $profiles)->get() as $base) {
            if (DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $base->profile_id)->exists()) {
                continue;
            }
            $given++;
            $name = DB::table('tbluserprofilemaster')->where('id', $base->profile_id)->value('name');
            $this->line(($dry ? 'would grant ' : 'granting ') . "profile {$base->profile_id} ({$name})");
            if (! $dry) {
                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $base->sub_institute_id,
                    'menu_id' => $menuId,
                    'profile_id' => $base->profile_id,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => $base->is_mobile,
                    'created_at' => now(),
                ]);
            }
        }
        $this->info(($dry ? 'Would give ' : 'Gave ') . "view access to {$given} profile(s) of organisation {$tenant}.");

        return self::SUCCESS;
    }
}
