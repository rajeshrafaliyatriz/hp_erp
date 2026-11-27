<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two menu changes of the Market Signals module, with a way back (26 Nov 2026; Docs/agentic-crm/briefs/MARKET-SIGNALS-PARTNER-REMOVAL.md; DB-RULES item 8):
 *
 *   (a) the Partner Network row goes: the menu row whose `access_link` is exactly /partners, named exactly "Partner Network", under the Market Signals row (id 476), and every right
 *       granted on it. The screen, its routes and the registry table are gone from the code, so the row also leaves Role and Permissions.
 *   (b) the tab "Company Opportunities" is renamed "Market Signals": the row whose `access_link` is exactly /signals, under the same parent (476), named exactly "Company Opportunities".
 *       Only its name changes; its id, address, parent, order and rights stay.
 *
 *   php artisan signals:market-signals-menu                         DRY RUN (the default): prints the row it would delete, its rights and the row it would rename; only SELECT statements are sent
 *   php artisan signals:market-signals-menu --apply                 saves the JSON before-image FIRST (the deleted row, its rights, the renamed row and its old name), then does both changes in ONE transaction
 *   php artisan signals:market-signals-menu --restore=<file>        puts the deleted row and its rights back with their own ids and gives the renamed row its old name; refuses (and writes nothing) when
 *                                                                   an id or the address is taken, or when the renamed row no longer has the new name
 *   --image-dir=<directory>                                         where the before-image is written (default storage/app/signals-market-signals-menu)
 *   --connection=<name>                                             without it only the dev host (or the sqlite test database) is used; any other host must be named
 *
 * Which rows: a row is touched only when its address, its name AND its parent are exactly the ones above (compared in PHP too, so a database whose collation ignores case or trailing
 * spaces cannot widen the match). Any other row, a look-alike address, another name or another parent, is never selected, never changed and never deleted; the statements repeat the
 * conditions. The parent id (476) is the one the dev host and db3 share; on a host where it differs the command finds nothing and says so, which is the safe way to be wrong.
 * A Partner Network row that still has children is left alone and reported, and nothing else is written either (a run is both changes or neither).
 *
 * Idempotent: once the row is gone and the tab is renamed there is nothing to do and no before-image is written. Run it BEFORE or AFTER the backend deploy: it only changes menu rows,
 * and the tab is renamed in the front end by the same release. The table g2g_partners is NOT touched here (migration 2026_12_28_110000, after the backend is deployed).
 *
 * Exit code: 0 done or nothing to do, 1 the change or the restore could not be completed, 2 the command could not run (bad option, refused host, missing file or table).
 */
class SignalsMarketSignalsMenuCommand extends Command
{
    /** The Market Signals row both menu rows hang under (the same id on dev and db3, checked on 26 Nov 2026). */
    public const PARENT_ID = 476;

    public const REMOVE_LINK = '/partners';

    public const REMOVE_NAME = 'Partner Network';

    public const RENAME_LINK = '/signals';

    public const OLD_NAME = 'Company Opportunities';

    public const NEW_NAME = 'Market Signals';

    public const FORMAT = 'signals-market-signals-menu-before-image/1';

    protected $signature = 'signals:market-signals-menu
        {--apply : Delete the Partner Network menu row and its rights and rename the Company Opportunities tab (default: show what would change)}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}
        {--image-dir= : Directory for the before-image (default storage/app/signals-market-signals-menu)}
        {--restore= : A before-image file written by an --apply run: put its rows and the old name back}';

    protected $description = 'Dry run by default: remove the Partner Network menu row and its rights, rename the Company Opportunities tab to Market Signals. --apply saves a before-image first; --restore puts everything back.';

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

        $this->line('signals:market-signals-menu - ' . ($restore !== null ? 'RESTORE of ' . $restore : ($apply ? 'APPLY' : 'DRY RUN (nothing is written)')) . ' on connection ' . ($explicit ?? config('database.default')));

        return $restore !== null ? $this->undo($db, $restore) : $this->change($db, $apply);
    }

    // ------------------------------------------------------------------ dry run and apply

    private function change($db, bool $apply): int
    {
        $remove = $this->exactRows($db, self::REMOVE_LINK, self::REMOVE_NAME);
        $rename = $this->exactRows($db, self::RENAME_LINK, self::OLD_NAME);
        $removeIds = array_map(fn (array $r) => (int) $r['id'], $remove);
        $renameIds = array_map(fn (array $r) => (int) $r['id'], $rename);

        $this->reportLookAlikes($db, self::REMOVE_LINK, self::REMOVE_NAME, $removeIds);
        $this->reportLookAlikes($db, self::RENAME_LINK, self::OLD_NAME, $renameIds);

        if ($remove === [] && $rename === []) {
            $this->line(sprintf('Nothing to do: no menu row "%s" at %s and no tab "%s" at %s under row %d.', self::REMOVE_NAME, self::REMOVE_LINK, self::OLD_NAME, self::RENAME_LINK, self::PARENT_ID));

            return self::SUCCESS;
        }

        $children = $removeIds === [] ? [] : $db->table('tblmenumaster_g2g')->whereIn('parent_id', $removeIds)->orderBy('id')->get(['id', 'menu_name', 'access_link', 'parent_id'])->all();
        $rights = $removeIds === [] ? [] : $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $removeIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        foreach ($remove as $r) {
            $this->line(sprintf('  delete menu row %d "%s" (parent %s, status %s, access_link %s)', $r['id'], $r['menu_name'] ?? '', $r['parent_id'] ?? '-', $r['status'] ?? '-', $r['access_link']));
        }
        foreach ($rights as $r) {
            $this->line(sprintf('  right %d on menu row %d: profile %s, organisation %s, view/add/edit/delete %s/%s/%s/%s', $r['id'], $r['menu_id'], $r['profile_id'] ?? '-', $r['sub_institute_id'] ?? '-',
                $r['can_view'] ?? '-', $r['can_add'] ?? '-', $r['can_edit'] ?? '-', $r['can_delete'] ?? '-'));
        }
        $this->line(sprintf('  %d right(s) granted on the row to delete (tblgroupwise_rights_g2g)', count($rights)));
        foreach ($rename as $r) {
            $this->line(sprintf('  rename menu row %d "%s" -> "%s" (access_link %s, parent %s; only the name changes)', $r['id'], $r['menu_name'], self::NEW_NAME, $r['access_link'], $r['parent_id']));
        }
        if ($children !== []) {
            foreach ($children as $c) {
                $this->line(sprintf('  REFUSED: row %d "%s" (%s) is a child of the row to delete; it would be orphaned', $c->id, $c->menu_name ?? '', $c->access_link ?? ''));
            }
            $this->line('Nothing was written: move or delete the children first.');

            return $apply ? self::FAILURE : self::SUCCESS;
        }
        if (! $apply) {
            $this->line(sprintf('%d menu row(s) and %d right(s) would be deleted, %d row(s) renamed. Nothing was written. Add --apply to do it (a before-image is saved first).', count($remove), count($rights), count($rename)));

            return self::SUCCESS;
        }

        $path = $this->folder() . '/market-signals-menu-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        $image = [
            'format' => self::FORMAT, 'taken_at' => now()->toIso8601String(), 'parent_id' => self::PARENT_ID,
            'menu' => $remove, 'rights' => $rights,
            'renamed' => array_map(fn (array $r) => ['id' => (int) $r['id'], 'access_link' => $r['access_link'], 'parent_id' => (int) $r['parent_id'], 'old_name' => self::OLD_NAME, 'new_name' => self::NEW_NAME], $rename),
        ];
        if (! $this->save($path, $image)) {
            $this->error("The before-image could not be saved to {$path}, so nothing was changed.");

            return self::FAILURE;
        }
        $this->line(sprintf('Before-image of %d menu row(s), %d right(s) and %d renamed row(s) saved to %s (undo with --restore=%s)', count($remove), count($rights), count($rename), $path, $path));

        try {
            $db->transaction(function () use ($db, $removeIds, $renameIds): void {
                if ($removeIds !== []) {
                    $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $removeIds)->delete();
                    // The address, the name and the parent are repeated: whatever the ids say, a row that does not match all three is never deleted.
                    $db->table('tblmenumaster_g2g')->whereIn('id', $removeIds)->where('access_link', self::REMOVE_LINK)->where('menu_name', self::REMOVE_NAME)->where('parent_id', self::PARENT_ID)->delete();
                }
                if ($renameIds !== []) {
                    $db->table('tblmenumaster_g2g')->whereIn('id', $renameIds)->where('access_link', self::RENAME_LINK)->where('menu_name', self::OLD_NAME)->where('parent_id', self::PARENT_ID)
                        ->update(['menu_name' => self::NEW_NAME]);
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was changed)');

            return self::FAILURE;
        }
        $this->forgetMenuIds();
        $left = count($this->exactRows($db, self::REMOVE_LINK, self::REMOVE_NAME)) + count($this->exactRows($db, self::RENAME_LINK, self::OLD_NAME))
            + ($removeIds === [] ? 0 : $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $removeIds)->count());
        $this->line($left === 0 ? 'DONE: the Partner Network row and its rights are gone and the tab is named ' . self::NEW_NAME . '. Running it again changes nothing.' : "INCOMPLETE: {$left} row(s) are still as they were.");

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The rows with this address, this name and the Market Signals parent, compared exactly (the database may ignore case or trailing spaces; PHP does not).
     *
     * @return list<array<string, mixed>>
     */
    private function exactRows($db, string $link, string $name): array
    {
        $rows = $db->table('tblmenumaster_g2g')->where('access_link', $link)->where('menu_name', $name)->where('parent_id', self::PARENT_ID)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return array_values(array_filter($rows, fn (array $r) => $r['access_link'] === $link && $r['menu_name'] === $name && (int) $r['parent_id'] === self::PARENT_ID));
    }

    /** Rows with the same address that are NOT the row this command means: said out loud, never touched. */
    private function reportLookAlikes($db, string $link, string $name, array $touchedIds): void
    {
        foreach ($db->table('tblmenumaster_g2g')->where('access_link', $link)->orderBy('id')->get(['id', 'menu_name', 'parent_id']) as $r) {
            if (! in_array((int) $r->id, $touchedIds, true)) {
                $this->line(sprintf('  left alone: row %d "%s" (parent %s) has the address %s but is not the "%s" row under %d', $r->id, $r->menu_name ?? '', $r->parent_id ?? '-', $link, $name, self::PARENT_ID));
            }
        }
    }

    // ------------------------------------------------------------------ the way back

    private function undo($db, string $path): int
    {
        $image = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($image) || ($image['format'] ?? null) !== self::FORMAT || ! is_array($image['menu'] ?? null) || ! is_array($image['rights'] ?? null) || ! is_array($image['renamed'] ?? null)) {
            $this->error("{$path} is not a before-image written by this command.");

            return self::INVALID;
        }
        $menu = $image['menu'];
        $rights = $image['rights'];
        $renamed = $image['renamed'];

        // A forged or edited file must not make this command restore, or rename, a row it does not mean.
        foreach ($menu as $r) {
            if (! is_array($r) || ($r['access_link'] ?? null) !== self::REMOVE_LINK || ($r['menu_name'] ?? null) !== self::REMOVE_NAME || (int) ($r['parent_id'] ?? 0) !== self::PARENT_ID) {
                $this->error(sprintf('REFUSED: the file holds a menu row with another address, name or parent; this command restores only "%s" (%s) under %d. Nothing was restored.', self::REMOVE_NAME, self::REMOVE_LINK, self::PARENT_ID));

                return self::FAILURE;
            }
        }
        foreach ($renamed as $r) {
            if (! is_array($r) || (int) ($r['id'] ?? 0) < 1 || ($r['access_link'] ?? null) !== self::RENAME_LINK || ($r['old_name'] ?? null) !== self::OLD_NAME || ($r['new_name'] ?? null) !== self::NEW_NAME) {
                $this->error(sprintf('REFUSED: the file holds a renamed row with another address or name; this command renames only "%s" <-> "%s" at %s. Nothing was restored.', self::OLD_NAME, self::NEW_NAME, self::RENAME_LINK));

                return self::FAILURE;
            }
        }
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        $rightIds = array_map(fn (array $r) => (int) $r['id'], $rights);
        foreach ($rights as $r) {
            if (! is_array($r) || ! in_array((int) ($r['menu_id'] ?? 0), $ids, true)) {
                $this->error('REFUSED: the file holds a right on a menu row it does not restore. Nothing was restored.');

                return self::FAILURE;
            }
        }

        // The deleted row: already back exactly as the image says, still to put back, or blocked.
        $deleteDone = true;
        if ($ids !== []) {
            $present = $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->where('access_link', self::REMOVE_LINK)->count();
            $presentRights = $rightIds === [] ? 0 : $db->table('tblgroupwise_rights_g2g')->whereIn('id', $rightIds)->count();
            $deleteDone = $present === count($ids) && $presentRights === count($rightIds);
            if (! $deleteDone) {
                $taken = $db->table('tblmenumaster_g2g')->where(fn ($q) => $q->whereIn('id', $ids)->orWhere('access_link', self::REMOVE_LINK))->count();
                if ($taken > 0 || $presentRights > 0) {
                    $this->line(sprintf('REFUSED: %d menu row(s) and %d right(s) already hold these ids or this address; nothing was restored.', $taken, $presentRights));

                    return self::FAILURE;
                }
            }
        }

        // The renamed rows: each is back to its old name, still has the new name, or is something else now (blocked).
        $toRename = [];
        foreach ($renamed as $r) {
            $row = $db->table('tblmenumaster_g2g')->where('id', (int) $r['id'])->first(['id', 'menu_name', 'access_link']);
            if ($row === null || $row->access_link !== self::RENAME_LINK) {
                $this->line(sprintf('REFUSED: menu row %d is not at %s any more; nothing was restored.', $r['id'], self::RENAME_LINK));

                return self::FAILURE;
            }
            if ($row->menu_name === self::NEW_NAME) {
                $toRename[] = (int) $r['id'];
            } elseif ($row->menu_name !== self::OLD_NAME) {
                $this->line(sprintf('REFUSED: menu row %d is now named "%s", not "%s": it was changed after this image was taken; nothing was restored.', $r['id'], $row->menu_name ?? '', self::NEW_NAME));

                return self::FAILURE;
            }
        }

        if ($deleteDone && $toRename === []) {
            $this->line('Nothing to do: every row of the before-image is already as it says.');

            return self::SUCCESS;
        }

        try {
            $db->transaction(function () use ($db, $deleteDone, $menu, $rights, $toRename): void {
                if (! $deleteDone) {
                    foreach ($menu as $r) {
                        $db->table('tblmenumaster_g2g')->insert($r);
                    }
                    foreach ($rights as $r) {
                        $db->table('tblgroupwise_rights_g2g')->insert($r);
                    }
                }
                if ($toRename !== []) {
                    $db->table('tblmenumaster_g2g')->whereIn('id', $toRename)->where('access_link', self::RENAME_LINK)->where('menu_name', self::NEW_NAME)->update(['menu_name' => self::OLD_NAME]);
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was restored)');

            return self::FAILURE;
        }
        $this->forgetMenuIds();
        $this->line(sprintf('RESTORED: %d menu row(s) and %d right(s) put back with their own ids, %d row(s) named "%s" again.', $deleteDone ? 0 : count($menu), $deleteDone ? 0 : count($rights), count($toRename), self::OLD_NAME));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ plumbing

    private function folder(): string
    {
        $given = $this->option('image-dir');

        return rtrim(is_string($given) && $given !== '' ? $given : storage_path('app/signals-market-signals-menu'), '/\\');
    }

    /** MenuRight::idForAccessLink() remembers a menu id for five minutes: forget it so a deleted or restored row is seen at once. */
    private function forgetMenuIds(): void
    {
        try {
            foreach ([self::REMOVE_LINK, self::RENAME_LINK] as $link) {
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
