<?php

namespace App\Console\Commands;

use App\Support\RoleKey;
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
 *   php artisan simulations:grant --tenant=7 --authors     (ALSO lets the organisation's administrator profile add, edit and delete simulations)
 *   php artisan simulations:grant --tenant=6 --database=live
 *
 * `--authors` is for an organisation whose administrators do not author in the LMS yet (the menu migration only grants the profiles that already hold
 * Course Builder with an authoring bit, which on live is one profile): it gives every profile of the organisation that RoleKey resolves to
 * `administrator` full rights on the row, or upgrades a view-only row it gave before. It never touches any other role, and `--revoke` leaves
 * authoring rights alone (change those in Role & Permissions).
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
        {--authors : Also give the administrator profile(s) of the organisation the authoring rights (view, add, edit, delete)}
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
            $author = (bool) $this->option('authors') && RoleKey::forProfileId((int) $base->profile_id) === 'administrator';
            $has = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $base->profile_id)->first();
            if ($has && (! $author || ((int) $has->can_add + (int) $has->can_edit + (int) $has->can_delete) === 3)) {
                continue;
            }
            $given++;
            $name = DB::table('tbluserprofilemaster')->where('id', $base->profile_id)->value('name');
            $this->line(($dry ? 'would grant ' : 'granting ') . ($author ? 'authoring to ' : '') . "profile {$base->profile_id} ({$name})");
            if (! $dry) {
                $flag = $author ? 1 : 0;
                $rights = ['can_view' => 1, 'can_add' => $flag, 'can_edit' => $flag, 'can_delete' => $flag];
                if ($has) {
                    DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $base->profile_id)->update($rights);
                } else {
                    DB::table('tblgroupwise_rights_g2g')->insert([
                        'sub_institute_id' => $base->sub_institute_id,
                        'menu_id' => $menuId,
                        'profile_id' => $base->profile_id,
                        'dashboard_right' => 0,
                        'is_mobile' => $base->is_mobile,
                        'created_at' => now(),
                    ] + $rights);
                }
            }
        }
        $this->info(($dry ? 'Would give ' : 'Gave ') . "access to {$given} profile(s) of organisation {$tenant}.");

        return self::SUCCESS;
    }
}
