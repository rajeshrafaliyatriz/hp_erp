<?php

namespace App\Console\Commands;

use App\Support\RoleKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lets ONE organisation's administrator profile(s) use the Product Master screen: a full-rights grant (view, add, edit, delete) on the
 * `Product Master` menu row, the leaf only. A dry run unless --apply is given. Repeating it changes nothing.
 *
 *   php artisan product-master:grant --tenant=6                 (shows who would get it)
 *   php artisan product-master:grant --tenant=6 --apply
 *   php artisan product-master:grant --tenant=6 --apply --database=live
 *   php artisan product-master:grant --tenant=6 --revoke --apply   (takes the administrator grants away again)
 *
 * Which profiles count as administrators is answered by RoleKey (the stable role_key, then the exact legacy names), never by a name match or by
 * the role_key column alone, which is empty for most profiles on the app host. Nobody else is ever granted. The menu row is found by access_link
 * (ids differ between hosts); rights rows are matched on profile and menu only, because the rights table's own organisation column holds ids,
 * NULL and CSV lists and is never used to scope a change. The CRM root is NOT granted: a profile that cannot already see the CRM root is
 * reported and left alone, so granting this leaf never reveals anything else.
 */
class ProductMasterGrantCommand extends Command
{
    private const MENU_LINK = '/module/crm/product-master';

    private const ROOT_LINK = '/module/crm';

    protected $signature = 'product-master:grant
        {--tenant= : The organisation (sub_institute_id)}
        {--apply : Write the change (default: show what would change)}
        {--revoke : Remove the administrator grants instead of adding them}
        {--database= : The connection to change (default: the application\'s default)}';

    protected $description = 'Gives the administrator profile(s) of one organisation access to the Product Master screen. Dry run unless --apply.';

    public function handle(): int
    {
        if ($name = $this->option('database')) {
            DB::setDefaultConnection($name);
        }
        $tenant = $this->option('tenant');
        if ($tenant === null || ! ctype_digit((string) $tenant) || (int) $tenant <= 0) {
            $this->error('Give --tenant=<organisation id>.');

            return self::INVALID;
        }
        $tenant = (int) $tenant;
        $apply = (bool) $this->option('apply');
        $revoke = (bool) $this->option('revoke');

        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', self::MENU_LINK)->value('id');
        if ($menuId === null) {
            $this->error('The Product Master menu row does not exist on this connection. Run its menu migration first.');

            return self::FAILURE;
        }
        $rootId = DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->where('parent_id', 0)->value('id');

        $administrators = DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenant)->get(['id', 'sub_institute_id', 'role_key', 'name'])
            ->filter(fn ($p) => RoleKey::fromProfile($p) === 'administrator')->values();

        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ($revoke ? ' (revoke)' : '') . ' on connection ' . DB::getDefaultConnection() . ', organisation ' . $tenant . ': '
            . $administrators->count() . ' administrator profile(s) (' . $administrators->pluck('id')->implode(', ') . ')');
        if ($administrators->isEmpty()) {
            $this->warn('No administrator profile found for this organisation. Nothing to do.');

            return self::SUCCESS;
        }

        $changed = 0;
        foreach ($administrators as $profile) {
            $row = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $profile->id)->first();

            if ($revoke) {
                if ($row === null) {
                    $this->line("  profile {$profile->id}: no grant to remove");

                    continue;
                }
                $this->line("  profile {$profile->id}: " . ($apply ? 'grant removed' : 'would remove the grant'));
                if ($apply) {
                    DB::table('tblgroupwise_rights_g2g')->where('id', $row->id)->delete();
                }
                $changed++;

                continue;
            }

            if ($rootId !== null) {
                $seesRoot = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $rootId)->where('profile_id', $profile->id)->where('can_view', 1)->exists();
                if (! $seesRoot) {
                    $this->warn("  profile {$profile->id}: cannot see the CRM root, so the screen would not show in the menu. Left alone; the root is never granted here.");

                    continue;
                }
            }
            if ($row !== null && (int) $row->can_view === 1) {
                $this->line("  profile {$profile->id}: already has the screen");

                continue;
            }

            $this->line("  profile {$profile->id}: " . ($apply ? 'granted (view, add, edit, delete)' : 'would grant (view, add, edit, delete)'));
            if ($apply) {
                if ($row === null) {
                    DB::table('tblgroupwise_rights_g2g')->insert([
                        'sub_institute_id' => $profile->sub_institute_id, 'menu_id' => $menuId, 'profile_id' => $profile->id,
                        'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1, 'dashboard_right' => 0, 'is_mobile' => 0, 'created_at' => now(),
                    ]);
                } else {
                    DB::table('tblgroupwise_rights_g2g')->where('id', $row->id)->update(['can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
                }
            }
            $changed++;
        }

        $this->line($apply ? "Done: {$changed} profile(s) changed." : "Dry run: nothing was written ({$changed} profile(s) would change). Run again with --apply to write.");

        return self::SUCCESS;
    }
}
