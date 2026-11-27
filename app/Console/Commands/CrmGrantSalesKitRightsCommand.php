<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\Kit\KitRights;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives an organisation's profiles their first rights on the "Sales Kit" menu row (27 Nov 2026; DB-RULES item 8; Docs/agentic-crm/briefs/SALES-KIT-CONTRACT.md). The menu row itself is added by
 * the migration 2026_12_30_090100_add_sales_kit_menu_row, which grants nothing; what each profile may do from there on is decided in Role and Permissions. This writes the PROPOSED starting point:
 *
 *   Admin, HR, HR Executive        view, add, edit and delete (they manage the kit)
 *   Reporting Manager, Employee    view (they use it)
 *   every other profile            nothing
 *
 *   php artisan crm:grant-sales-kit-rights --tenant=6           DRY RUN (the default): prints each profile, its ACTIVE user count and what would be written; only SELECT statements are sent
 *   php artisan crm:grant-sales-kit-rights --tenant=6 --apply   saves a before-image, writes the missing rows in ONE transaction, then saves a summary (the ids it wrote)
 *   --connection=<name>                                           without it only the dev host (or the sqlite test database) is used; any other host must be named
 *   --image-dir=<directory>                                       where the before-image and the summary are written (default Docs/agentic-crm/_evidence/sales-kit-2026-11-27)
 *
 * The rules, each one a trap that bit before:
 *   - Profiles are found BY NAME inside the organisation (`tbluserprofilemaster.sub_institute_id`, a number). `tblgroupwise_rights_g2g.sub_institute_id` is a text column that holds an id, NULL or a
 *     comma list, so it is never used to find, scope or count anything here (memory g2g-rights-table-traps). A rights row is written with the organisation's id as plain text, like its neighbours.
 *   - The LEAF only. The row's parents (Market Signals, Product Portfolio) are never granted: a profile that cannot reach the Product Portfolio does not get a Sales Kit it could not open, and
 *     granting an ancestor can reveal stale grants on its other children (memory g2g-leaf-only-grant).
 *   - Never overwrites. A profile that already has ANY row on the Sales Kit is left exactly as it is (it was set in Role and Permissions), and a re-run changes nothing.
 *   - The audience is printed first: each profile with the number of ACTIVE users on it (`tbluser.status = 1`, not deleted; status is 1/0, never the word "active"), so nobody is surprised.
 */
class CrmGrantSalesKitRightsCommand extends Command
{
    public const FORMAT = 'crm-grant-sales-kit-rights/1';

    /** profile name (as the organisation names it) => the rights it is given */
    public const MATRIX = [
        'Admin' => ['view', 'add', 'edit', 'delete'],
        'HR' => ['view', 'add', 'edit', 'delete'],
        'HR Executive' => ['view', 'add', 'edit', 'delete'],
        'Reporting Manager' => ['view'],
        'Employee' => ['view'],
    ];

    protected $signature = 'crm:grant-sales-kit-rights
        {--tenant=6 : The organisation (sub_institute_id) whose profiles are granted}
        {--apply : Write the missing rights (default: show what would be written)}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}
        {--image-dir= : Directory for the before-image and the summary (default Docs/agentic-crm/_evidence/sales-kit-2026-11-27)}';

    protected $description = 'Dry run by default: give one organisation\'s Admin, HR, HR Executive, Reporting Manager and Employee profiles their starting rights on the Sales Kit menu row. --apply writes them (leaf only, never overwrites).';

    public function handle(): int
    {
        $explicit = $this->option('connection');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;
        $refusal = ProductMasterIsolateTenantsCommand::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }
        $tenant = (string) $this->option('tenant');
        if (! ctype_digit($tenant) || (int) $tenant <= 0) {
            $this->error('--tenant must be a whole number above 0 (an organisation id).');

            return self::INVALID;
        }
        $tenant = (int) $tenant;
        $apply = (bool) $this->option('apply');
        $db = DB::connection($explicit);
        $schema = Schema::connection($explicit);
        foreach (['tblmenumaster_g2g', 'tblgroupwise_rights_g2g', 'tbluserprofilemaster', 'tbluser'] as $table) {
            if (! $schema->hasTable($table)) {
                $this->error("This connection has no {$table}. Nothing was done.");

                return self::INVALID;
            }
        }

        $menu = $db->table('tblmenumaster_g2g')->where('access_link', KitRights::LINK)->orderBy('id')->get(['id', 'menu_name', 'parent_id', 'status', 'access_link']);
        if ($menu->count() !== 1) {
            $this->error($menu->isEmpty()
                ? 'There is no menu row with the address ' . KitRights::LINK . ' on this connection. Run the migration 2026_12_30_090100_add_sales_kit_menu_row first. Nothing was done.'
                : 'More than one menu row has the address ' . KitRights::LINK . ' (ids ' . $menu->pluck('id')->implode(', ') . '); fix that first. Nothing was done.');

            return self::INVALID;
        }
        $menuRow = $menu->first();
        $menuId = (int) $menuRow->id;

        $this->line('crm:grant-sales-kit-rights - ' . ($apply ? 'APPLY' : 'DRY RUN (nothing is written)') . ' on connection ' . ($explicit ?? config('database.default')) . ', organization ' . $tenant);
        $this->line(sprintf('  menu row %d "%s" (%s, parent %s, status %s)', $menuId, $menuRow->menu_name, $menuRow->access_link, $menuRow->parent_id ?? '-', $menuRow->status ?? '-'));
        if ((int) ($menuRow->status ?? 1) !== 1) {
            $this->line('  WARNING: the row is not status 1; a Role and Permissions save would delete rights written on it. Set it to status 1 first.');
        }

        // The profiles, by name, inside the organisation.
        $plan = [];
        $missing = [];
        $audience = 0;
        foreach (self::MATRIX as $name => $actions) {
            $profiles = $db->table('tbluserprofilemaster')->where('sub_institute_id', $tenant)->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->orderBy('id')->get(['id', 'name']);
            if ($profiles->isEmpty()) {
                $missing[] = $name;

                continue;
            }
            foreach ($profiles as $profile) {
                $active = (int) $db->table('tbluser')->where('user_profile_id', $profile->id)->where('sub_institute_id', $tenant)->where('status', 1)->whereNull('deleted_at')->count();
                $existing = $db->table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $profile->id)->orderBy('id')->get();
                $plan[] = ['profile_id' => (int) $profile->id, 'name' => (string) $profile->name, 'actions' => $actions, 'active_users' => $active, 'existing' => $existing->map(fn ($r) => (array) $r)->all()];
            }
        }

        $toWrite = 0;
        foreach ($plan as $p) {
            if ($p['existing'] !== []) {
                $row = $p['existing'][0];
                $this->line(sprintf('  profile %d "%s": %d active user(s): LEFT ALONE, it already has a row (id %d: view/add/edit/delete %s/%s/%s/%s)', $p['profile_id'], $p['name'], $p['active_users'], $row['id'],
                    $row['can_view'] ?? '-', $row['can_add'] ?? '-', $row['can_edit'] ?? '-', $row['can_delete'] ?? '-'));

                continue;
            }
            $toWrite++;
            $audience += $p['active_users'];
            $this->line(sprintf('  profile %d "%s": %d active user(s): would be given %s', $p['profile_id'], $p['name'], $p['active_users'], implode(', ', $p['actions'])));
        }
        foreach ($missing as $name) {
            $this->line("  no profile named \"{$name}\" in organization {$tenant}: skipped");
        }
        $this->line(sprintf('  %d right(s) to write for %d active user(s) in total; every other profile gets nothing', $toWrite, $audience));

        if ($toWrite === 0) {
            $this->line('Nothing to do: every matched profile already has a row on the Sales Kit (or no profile matched). Nothing was written.');

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->line('Nothing was written. Add --apply to write them (a before-image is saved first).');

            return self::SUCCESS;
        }

        $stamp = now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $beforePath = $this->folder() . "/grant-sales-kit-rights-T{$tenant}-{$stamp}-before.json";
        $before = [
            'format' => self::FORMAT, 'kind' => 'before-image', 'taken_at' => now()->toIso8601String(), 'connection' => $explicit ?? config('database.default'), 'tenant' => $tenant,
            'menu_row' => (array) $menuRow,
            'existing_rights_on_menu_row' => $db->table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'plan' => array_map(fn ($p) => ['profile_id' => $p['profile_id'], 'name' => $p['name'], 'actions' => $p['actions'], 'active_users' => $p['active_users'], 'has_row' => $p['existing'] !== []], $plan),
        ];
        if (! $this->save($beforePath, $before)) {
            $this->error("The before-image could not be saved to {$beforePath}, so nothing was written.");

            return self::FAILURE;
        }
        $this->line("Before-image saved to {$beforePath}");

        $written = [];
        try {
            $db->transaction(function () use ($db, $plan, $menuId, $tenant, &$written): void {
                foreach ($plan as $p) {
                    // Re-checked inside the transaction: a row written since the dry run is never overwritten.
                    if ($db->table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->where('profile_id', $p['profile_id'])->exists()) {
                        continue;
                    }
                    $row = [
                        'menu_id' => $menuId, 'profile_id' => $p['profile_id'],
                        'can_view' => in_array('view', $p['actions'], true) ? 1 : 0, 'can_add' => in_array('add', $p['actions'], true) ? 1 : 0,
                        'can_edit' => in_array('edit', $p['actions'], true) ? 1 : 0, 'can_delete' => in_array('delete', $p['actions'], true) ? 1 : 0,
                        'dashboard_right' => 0, 'is_mobile' => 0,
                        // The organisation as plain text, the shape its neighbours have; it is never read back to scope anything.
                        'sub_institute_id' => (string) $tenant,
                        'created_at' => now(),
                    ];
                    $written[] = ['id' => (int) $db->table('tblgroupwise_rights_g2g')->insertGetId($row), 'profile_id' => $p['profile_id'], 'name' => $p['name'], 'actions' => $p['actions'], 'active_users' => $p['active_users']];
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was written)');

            return self::FAILURE;
        }

        $summaryPath = $this->folder() . "/grant-sales-kit-rights-T{$tenant}-{$stamp}-summary.json";
        $ids = array_column($written, 'id');
        $summary = [
            'format' => self::FORMAT, 'kind' => 'summary', 'written_at' => now()->toIso8601String(), 'connection' => $explicit ?? config('database.default'), 'tenant' => $tenant, 'menu_id' => $menuId,
            'rows_written' => count($written), 'active_users_reached' => array_sum(array_column($written, 'active_users')), 'rights' => $written,
            'undo' => 'DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = ' . $menuId . ' AND id IN (' . implode(', ', $ids) . ');',
        ];
        if (! $this->save($summaryPath, $summary)) {
            $this->line("WARNING: the rights were written but the summary could not be saved to {$summaryPath}. The ids are: " . implode(', ', $ids));
        } else {
            $this->line("Summary saved to {$summaryPath}");
        }

        $left = $db->table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->whereIn('id', $ids)->count();
        $this->line(sprintf('DONE: %d right(s) written (%s). Running it again changes nothing.', $left, implode(', ', $ids)));

        return $left === count($written) ? self::SUCCESS : self::FAILURE;
    }

    private function folder(): string
    {
        $given = $this->option('image-dir');

        return rtrim(is_string($given) && $given !== '' ? $given : base_path('Docs/agentic-crm/_evidence/sales-kit-2026-11-27'), '/\\');
    }

    private function save(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false
            && json_decode((string) file_get_contents($path), true) === json_decode(json_encode($data), true);
    }
}
