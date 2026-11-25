<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the "Price Books" row out of the menu, with a way back (S16, 25 Nov 2026; contract Docs/agentic-crm/slices/S16-PRICING-CONTRACT.md section 4, DB-RULES item 8).
 *
 *   php artisan crm:retire-price-books-menu                        DRY RUN (the default): lists the menu row(s) and the rights rows it would delete; only SELECT statements are sent
 *   php artisan crm:retire-price-books-menu --apply                saves the before-image of every row it will delete (storage/app/crm-price-books/), then deletes them in ONE transaction
 *   php artisan crm:retire-price-books-menu --rollback=<file>      puts the rows of a before-image back, with their own ids; refuses (and writes nothing) when one of those ids is taken
 *
 * It looks at ONE address: the menu rows whose `access_link` is exactly /module/crm/sales/price-books (tblmenumaster_g2g) and every right granted on them (tblgroupwise_rights_g2g). A row
 * with any other address is never selected and never deleted (the delete statements repeat the address). A row that still has children is left alone and reported: deleting it would
 * orphan them. The screens, the tables (crm_price_books, crm_price_book_items) and the column crm_quotes.price_book_id are NOT touched: DB-RULES item 1, additive only.
 *
 * Without --connection the command runs only on the dev host (or the sqlite test database); any other host must be named, as product-master:isolate-tenants does.
 *
 * Exit code: 0 done or nothing to do, 1 the delete or the restore could not be completed, 2 the command could not run (bad option, refused host, missing file or table).
 */
class CrmRetirePriceBooksMenuCommand extends Command
{
    public const LINK = '/module/crm/sales/price-books';

    public const FORMAT = 'crm-price-books-menu-before-image/1';

    protected $signature = 'crm:retire-price-books-menu
        {--apply : Delete the menu row and its rights (default: show what would be deleted)}
        {--rollback= : A before-image file written by an --apply run: put its rows back}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}';

    protected $description = 'Dry run by default: remove the Price Books menu row and its rights. --apply saves a before-image first; --rollback puts the rows back.';

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

        $this->line('crm:retire-price-books-menu - ' . ($rollback !== null ? 'ROLLBACK of ' . $rollback : ($apply ? 'APPLY' : 'DRY RUN (nothing is written)')) . ' on connection ' . ($explicit ?? config('database.default')));

        return $rollback !== null ? $this->undo($db, $rollback) : $this->retire($db, $apply);
    }

    // ------------------------------------------------------------------ dry run and apply

    private function retire($db, bool $apply): int
    {
        $menu = $db->table('tblmenumaster_g2g')->where('access_link', self::LINK)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        if ($ids === []) {
            $this->line('Nothing to do: no menu row has the address ' . self::LINK . '.');

            return self::SUCCESS;
        }
        $children = $db->table('tblmenumaster_g2g')->whereIn('parent_id', $ids)->orderBy('id')->get(['id', 'menu_name', 'access_link', 'parent_id'])->all();
        $rights = $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        foreach ($menu as $r) {
            $this->line(sprintf('  menu row %d "%s" (parent %s, status %s, access_link %s)', $r['id'], $r['menu_name'] ?? '', $r['parent_id'] ?? '-', $r['status'] ?? '-', $r['access_link']));
        }
        $this->line(sprintf('  %d right(s) granted on it (tblgroupwise_rights_g2g)', count($rights)));
        if ($children !== []) {
            foreach ($children as $c) {
                $this->line(sprintf('  REFUSED: row %d "%s" (%s) is a child of the row to delete; it would be orphaned', $c->id, $c->menu_name ?? '', $c->access_link ?? ''));
            }
            $this->line('Nothing was written: move or delete the children first.');

            return $apply ? self::FAILURE : self::SUCCESS;
        }
        if (! $apply) {
            $this->line(sprintf('%d menu row(s) and %d right(s) would be deleted. Nothing was written. Add --apply to delete them (a before-image is saved first).', count($menu), count($rights)));

            return self::SUCCESS;
        }

        $path = $this->folder() . '/price-books-menu-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        $image = ['format' => self::FORMAT, 'taken_at' => now()->toIso8601String(), 'link' => self::LINK, 'menu' => $menu, 'rights' => $rights];
        if (! $this->save($path, $image)) {
            $this->error("The before-image could not be saved to {$path}, so nothing was deleted.");

            return self::FAILURE;
        }
        $this->line(sprintf('Before-image of %d menu row(s) and %d right(s) saved to %s (undo with --rollback=%s)', count($menu), count($rights), $path, $path));

        try {
            $db->transaction(function () use ($db, $ids): void {
                $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
                // The address is repeated: whatever the ids say, a row with another address is never deleted.
                $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->where('access_link', self::LINK)->delete();
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was deleted)');

            return self::FAILURE;
        }
        $left = $db->table('tblmenumaster_g2g')->where('access_link', self::LINK)->count() + $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->count();
        $this->line($left === 0 ? 'DONE: the menu row and its rights are gone. Running it again changes nothing.' : "INCOMPLETE: {$left} row(s) are still there.");

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
            if (! is_array($r) || ($r['access_link'] ?? null) !== self::LINK) {
                $this->error('REFUSED: the file holds a menu row with another address; this command restores only ' . self::LINK . '. Nothing was undone.');

                return self::FAILURE;
            }
        }
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        $taken = $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->orWhere('access_link', self::LINK)->count();
        $takenRights = $rights === [] ? 0 : $db->table('tblgroupwise_rights_g2g')->whereIn('id', array_map(fn (array $r) => (int) $r['id'], $rights))->count();
        if ($taken > 0 || $takenRights > 0) {
            $this->line(sprintf('REFUSED: %d menu row(s) and %d right(s) already hold these ids or this address; nothing was undone.', $taken, $takenRights));

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
        return rtrim(storage_path('app/crm-price-books'), '/\\');
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
