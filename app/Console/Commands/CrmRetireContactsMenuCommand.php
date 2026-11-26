<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the two "Contacts" rows out of the menu, with a way back (S18, 25 Nov 2026; contract Docs/agentic-crm/slices/S18-CONTACTS-INTO-ORGANIZATIONS-CONTRACT.md section 2, DB-RULES item 8).
 * The people of a company now live inside Organizations, so the sidebar entry of their own goes. WRITTEN, NOT RUN by the track that wrote it: the owner decides when.
 *
 *   php artisan crm:retire-contacts-menu                        DRY RUN (the default): lists the menu rows and the rights rows it would delete; only SELECT statements are sent
 *   php artisan crm:retire-contacts-menu --apply                saves the before-image of every row it will delete (storage/app/crm-contacts-menu/), then deletes them in ONE transaction
 *   php artisan crm:retire-contacts-menu --rollback=<file>      puts the rows of a before-image back, with their own ids; refuses (and writes nothing) when one of those ids is taken
 *
 * It looks at TWO addresses: the menu rows whose `access_link` is exactly /module/crm/marketing/contacts or /module/crm/support/contacts (tblmenumaster_g2g) and every right granted on them
 * (tblgroupwise_rights_g2g). A row with any other address is never selected and never deleted (the delete statements repeat the addresses). A row that still has children is left alone and
 * reported: deleting it would orphan them. The screens, the table crm_contacts and every pointer to it are NOT touched: DB-RULES item 1, additive only.
 *
 * READ THIS BEFORE --apply: the contact API routes (GET/POST/PUT/DELETE /api/crm/contacts..., the contact merge, the Recycle Bin's contacts tab) look the right up by exactly these two addresses
 * (`platformright:/module/crm/marketing/contacts|/module/crm/support/contacts`). Once the rows are gone nobody holds a right on them, so those routes answer 403 to everyone until they are
 * pointed at the Organizations rows. The dry run says so.
 *
 * Without --connection the command runs only on the dev host (or the sqlite test database); any other host must be named, as product-master:isolate-tenants does.
 *
 * Exit code: 0 done or nothing to do, 1 the delete or the restore could not be completed, 2 the command could not run (bad option, refused host, missing file or table).
 */
class CrmRetireContactsMenuCommand extends Command
{
    /** @var list<string> */
    public const LINKS = ['/module/crm/marketing/contacts', '/module/crm/support/contacts'];

    public const FORMAT = 'crm-contacts-menu-before-image/1';

    protected $signature = 'crm:retire-contacts-menu
        {--apply : Delete the two menu rows and their rights (default: show what would be deleted)}
        {--rollback= : A before-image file written by an --apply run: put its rows back}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}';

    protected $description = 'Dry run by default: remove the two Contacts menu rows and their rights. --apply saves a before-image first; --rollback puts the rows back.';

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
        $rollback = $this->option('rollback');
        $rollback = is_string($rollback) && $rollback !== '' ? $rollback : null;
        if ($apply && $rollback !== null) {
            $this->error('--apply and --rollback are two different runs: do one, check, then the other.');

            return self::INVALID;
        }
        $db = DB::connection($explicit);
        $schema = Schema::connection($explicit);
        if (! $schema->hasTable('tblmenumaster_g2g') || ! $schema->hasTable('tblgroupwise_rights_g2g')) {
            $this->error('This connection has no tblmenumaster_g2g / tblgroupwise_rights_g2g. Nothing was done.');

            return self::INVALID;
        }

        $this->line('crm:retire-contacts-menu - ' . ($rollback !== null ? 'ROLLBACK of ' . $rollback : ($apply ? 'APPLY' : 'DRY RUN (nothing is written)')) . ' on connection ' . ($explicit ?? config('database.default')));

        return $rollback !== null ? $this->undo($db, $rollback) : $this->retire($db, $apply);
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
        $this->line(sprintf('  %d right(s) granted on them (tblgroupwise_rights_g2g)', count($rights)));
        if ($children !== []) {
            foreach ($children as $c) {
                $this->line(sprintf('  REFUSED: row %d "%s" (%s) is a child of a row to delete; it would be orphaned', $c->id, $c->menu_name ?? '', $c->access_link ?? ''));
            }
            $this->line('Nothing was written: move or delete the children first.');

            return $apply ? self::FAILURE : self::SUCCESS;
        }
        $this->line('  WARNING: the contact API routes find their right by these two addresses; with the rows gone they answer 403 to everyone until they are pointed at the Organizations rows.');
        if (! $apply) {
            $this->line(sprintf('%d menu row(s) and %d right(s) would be deleted. Nothing was written. Add --apply to delete them (a before-image is saved first).', count($menu), count($rights)));

            return self::SUCCESS;
        }

        $path = $this->folder() . '/contacts-menu-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        $image = ['format' => self::FORMAT, 'taken_at' => now()->toIso8601String(), 'links' => self::LINKS, 'menu' => $menu, 'rights' => $rights];
        if (! $this->save($path, $image)) {
            $this->error("The before-image could not be saved to {$path}, so nothing was deleted.");

            return self::FAILURE;
        }
        $this->line(sprintf('Before-image of %d menu row(s) and %d right(s) saved to %s (undo with --rollback=%s)', count($menu), count($rights), $path, $path));

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
                $this->error('REFUSED: the file holds a menu row with another address; this command restores only ' . implode(' and ', self::LINKS) . '. Nothing was undone.');

                return self::FAILURE;
            }
        }
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        $taken = $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->orWhereIn('access_link', self::LINKS)->count();
        $takenRights = $rights === [] ? 0 : $db->table('tblgroupwise_rights_g2g')->whereIn('id', array_map(fn (array $r) => (int) $r['id'], $rights))->count();
        if ($taken > 0 || $takenRights > 0) {
            $this->line(sprintf('REFUSED: %d menu row(s) and %d right(s) already hold these ids or these addresses; nothing was undone.', $taken, $takenRights));

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
        $this->line(sprintf('UNDONE: %d menu row(s) and %d right(s) put back with their own ids.', count($menu), count($rights)));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ plumbing

    private function folder(): string
    {
        return rtrim(storage_path('app/crm-contacts-menu'), '/\\');
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
