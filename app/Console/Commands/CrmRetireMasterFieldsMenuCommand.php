<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the Master Fields row out of the Sales & Marketing menu, with a way back (26 Nov 2026; DB-RULES item 8). Master Fields was a console of five admin screens (picklist values, tax
 * rates, lead scoring and assignment, web-to-lead, SLA policies); they are now tabs of Field settings (Platform Services > Field settings, ?module=crm), which only administrators open
 * (owner decision). None of their APIs is gated by this row: picklists by the edit right of the module that owns them, tax rates by Quotes, lead scoring and web-to-lead by Leads
 * (menuright:201), SLA policies by Tickets. The front end already folds the entry out of the sidebar and the old address redirects; this removes the menu row and its rights too, so it
 * also leaves Role and Permissions.
 *
 *   php artisan crm:retire-master-fields-menu                         DRY RUN (the default): prints the menu row and the rights rows it would delete; only SELECT statements are sent
 *   php artisan crm:retire-master-fields-menu --apply                 saves the JSON before-image of every row it will delete FIRST, then deletes the rights, then the menu row, in ONE transaction
 *   php artisan crm:retire-master-fields-menu --restore=<file>        puts the rows of a before-image back, with their own ids; refuses (and writes nothing) when one of those ids or addresses is taken
 *   --image-dir=<directory>                                              where the before-image is written (default storage/app/crm-retire-master-fields-menu)
 *   --connection=<name>                                                  without it only the dev host (or the sqlite test database) is used; any other host must be named
 *
 * It looks at ONE address: the menu row whose `access_link` is exactly /module/crm/master-fields (tblmenumaster_g2g) and every right granted on it (tblgroupwise_rights_g2g).
 * A row with any other address is never selected and never deleted (the delete statements repeat the address). A row that still has children is left alone and reported: deleting it would
 * orphan them. Idempotent: when no row has the address there is nothing to do and no before-image is written. No table, column or other row is touched: the picklist, tax rate, lead
 * routing, lead capture and SLA tables stay, and so does the Agent Run History row (the agent API checks a right on it) and every other CRM row.
 *
 * Exit code: 0 done or nothing to do, 1 the delete or the restore could not be completed, 2 the command could not run (bad option, refused host, missing file or table).
 */
class CrmRetireMasterFieldsMenuCommand extends Command
{
    /** @var list<string> */
    public const LINKS = ['/module/crm/master-fields'];

    public const FORMAT = 'crm-retire-master-fields-menu-before-image/1';

    protected $signature = 'crm:retire-master-fields-menu
        {--apply : Delete the Master Fields menu row and its rights (default: show what would be deleted)}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}
        {--image-dir= : Directory for the before-image (default storage/app/crm-retire-master-fields-menu)}
        {--restore= : A before-image file written by an --apply run: put its rows back}';

    protected $description = 'Dry run by default: remove the Master Fields menu row and its rights (it moved into Field settings). --apply saves a before-image first; --restore puts the rows back.';

    public function handle(): int
    {
        $explicit = $this->option('connection');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;
        $refusal = ProductMasterIsolateTenantsCommand::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $restore = $this->option('restore');
        $restore = is_string($restore) && $restore !== '' ? $restore : null;
        if ($apply && $restore !== null) {
            $this->error('--apply and --restore are two different runs: do one, check, then the other.');

            return self::INVALID;
        }
        $db = DB::connection($explicit);
        $schema = Schema::connection($explicit);
        if (! $schema->hasTable('tblmenumaster_g2g') || ! $schema->hasTable('tblgroupwise_rights_g2g')) {
            $this->error('This connection has no tblmenumaster_g2g / tblgroupwise_rights_g2g. Nothing was done.');

            return self::INVALID;
        }

        $this->line('crm:retire-master-fields-menu - ' . ($restore !== null ? 'RESTORE of ' . $restore : ($apply ? 'APPLY' : 'DRY RUN (nothing is written)')) . ' on connection ' . ($explicit ?? config('database.default')));

        return $restore !== null ? $this->undo($db, $restore) : $this->retire($db, $apply);
    }

    // ------------------------------------------------------------------ dry run and apply

    private function retire($db, bool $apply): int
    {
        $menu = $db->table('tblmenumaster_g2g')->whereIn('access_link', self::LINKS)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        if ($ids === []) {
            $this->line('Nothing to do: no menu row has the address ' . implode(' or ', self::LINKS) . '.');

            return self::SUCCESS;
        }
        $children = $db->table('tblmenumaster_g2g')->whereIn('parent_id', $ids)->orderBy('id')->get(['id', 'menu_name', 'access_link', 'parent_id'])->all();
        $rights = $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        foreach ($menu as $r) {
            $this->line(sprintf('  menu row %d "%s" (parent %s, status %s, access_link %s)', $r['id'], $r['menu_name'] ?? '', $r['parent_id'] ?? '-', $r['status'] ?? '-', $r['access_link']));
        }
        foreach ($rights as $r) {
            $this->line(sprintf('  right %d on menu row %d: profile %s, organisation %s, view/add/edit/delete %s/%s/%s/%s', $r['id'], $r['menu_id'], $r['profile_id'] ?? '-', $r['sub_institute_id'] ?? '-',
                $r['can_view'] ?? '-', $r['can_add'] ?? '-', $r['can_edit'] ?? '-', $r['can_delete'] ?? '-'));
        }
        $this->line(sprintf('  %d right(s) granted on them (tblgroupwise_rights_g2g)', count($rights)));
        if ($children !== []) {
            foreach ($children as $c) {
                $this->line(sprintf('  REFUSED: row %d "%s" (%s) is a child of a row to delete; it would be orphaned', $c->id, $c->menu_name ?? '', $c->access_link ?? ''));
            }
            $this->line('Nothing was written: move or delete the children first.');

            return $apply ? self::FAILURE : self::SUCCESS;
        }
        if (! $apply) {
            $this->line(sprintf('%d menu row(s) and %d right(s) would be deleted. Nothing was written. Add --apply to delete them (a before-image is saved first).', count($menu), count($rights)));

            return self::SUCCESS;
        }

        $path = $this->folder() . '/retire-master-fields-menu-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        $image = ['format' => self::FORMAT, 'taken_at' => now()->toIso8601String(), 'links' => self::LINKS, 'menu' => $menu, 'rights' => $rights];
        if (! $this->save($path, $image)) {
            $this->error("The before-image could not be saved to {$path}, so nothing was deleted.");

            return self::FAILURE;
        }
        $this->line(sprintf('Before-image of %d menu row(s) and %d right(s) saved to %s (undo with --restore=%s)', count($menu), count($rights), $path, $path));

        try {
            $db->transaction(function () use ($db, $ids): void {
                $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
                // The addresses are repeated: whatever the ids say, a row with another address is never deleted.
                $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->whereIn('access_link', self::LINKS)->delete();
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was deleted)');

            return self::FAILURE;
        }
        $this->forgetMenuIds();
        $left = $db->table('tblmenumaster_g2g')->whereIn('access_link', self::LINKS)->count() + $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->count();
        $this->line($left === 0 ? 'DONE: the menu rows and their rights are gone. Running it again changes nothing.' : "INCOMPLETE: {$left} row(s) are still there.");

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------ the way back

    private function undo($db, string $path): int
    {
        $image = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($image) || ($image['format'] ?? null) !== self::FORMAT || ! is_array($image['menu'] ?? null) || ! is_array($image['rights'] ?? null)) {
            $this->error("{$path} is not a before-image written by this command.");

            return self::INVALID;
        }
        $menu = $image['menu'];
        $rights = $image['rights'];
        foreach ($menu as $r) {
            if (! is_array($r) || ! in_array($r['access_link'] ?? null, self::LINKS, true)) {
                $this->error('REFUSED: the file holds a menu row with another address; this command restores only ' . implode(' and ', self::LINKS) . '. Nothing was restored.');

                return self::FAILURE;
            }
        }
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        $rightIds = array_map(fn (array $r) => (int) $r['id'], $rights);

        // Already back, exactly as the image says: nothing to do, and nothing is written.
        $present = $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->whereIn('access_link', self::LINKS)->count();
        $presentRights = $rightIds === [] ? 0 : $db->table('tblgroupwise_rights_g2g')->whereIn('id', $rightIds)->count();
        if ($ids !== [] && $present === count($ids) && $presentRights === count($rightIds)) {
            $this->line('Nothing to do: every row of the before-image is already there.');

            return self::SUCCESS;
        }

        $taken = $db->table('tblmenumaster_g2g')->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('access_link', self::LINKS))->count();
        if ($taken > 0 || $presentRights > 0) {
            $this->line(sprintf('REFUSED: %d menu row(s) and %d right(s) already hold these ids or these addresses; nothing was restored.', $taken, $presentRights));

            return self::FAILURE;
        }

        try {
            $db->transaction(function () use ($db, $menu, $rights): void {
                foreach ($menu as $r) {
                    $db->table('tblmenumaster_g2g')->insert($r);
                }
                foreach ($rights as $r) {
                    $db->table('tblgroupwise_rights_g2g')->insert($r);
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was restored)');

            return self::FAILURE;
        }
        $this->forgetMenuIds();
        $this->line(sprintf('RESTORED: %d menu row(s) and %d right(s) put back with their own ids.', count($menu), count($rights)));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ plumbing

    private function folder(): string
    {
        $given = $this->option('image-dir');

        return rtrim(is_string($given) && $given !== '' ? $given : storage_path('app/crm-retire-master-fields-menu'), '/\\');
    }

    /** MenuRight::idForAccessLink() remembers a menu id for five minutes: forget it so a deleted or restored row is seen at once. */
    private function forgetMenuIds(): void
    {
        try {
            foreach (self::LINKS as $link) {
                Cache::forget('menu_id_for_link:' . $link);
            }
        } catch (\Throwable) {
            // a cache that cannot be reached only means the old id is remembered until it expires
        }
    }

    private function save(string $path, array $image): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, json_encode($image, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false
            && json_decode((string) file_get_contents($path), true) === json_decode(json_encode($image), true);
    }
}
