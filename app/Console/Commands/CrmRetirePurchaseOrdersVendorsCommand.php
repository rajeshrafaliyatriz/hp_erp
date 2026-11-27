<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the Purchase Orders and Vendors leftovers out of the data, with a way back (26 Nov 2026; Docs/agentic-crm/briefs/REMOVE-PURCHASE-ORDERS-VENDORS.md; DB-RULES item 8).
 * The two CRM modules left the workflow. WRITTEN, NOT RUN by the track that wrote it: the caller decides when.
 *
 *   php artisan crm:retire-purchase-orders-vendors                          DRY RUN (the default): prints every row it would delete and a count per kind; only SELECT statements are sent
 *   php artisan crm:retire-purchase-orders-vendors --apply                  saves the JSON before-image of every row it will delete FIRST, then deletes them in ONE transaction
 *   php artisan crm:retire-purchase-orders-vendors --restore=<file>         puts the rows of a before-image back, with their own ids; refuses (and writes nothing) when one of those ids or addresses is taken
 *   --image-dir=<directory>                                                 where the before-image is written (default storage/app/crm-retire-purchase-orders-vendors)
 *   --connection=<name>                                                     without it only the dev host (or the sqlite test database) is used; any other host must be named
 *
 * It handles four kinds of row, in this order, and prints a count for each:
 *   (a) rights         tblgroupwise_rights_g2g rows granted on the two menu rows below
 *   (b) menu rows      tblmenumaster_g2g rows whose `access_link` is exactly /module/crm/sales/vendors or /module/crm/sales/purchase-orders (a row that still has children is left alone and reported)
 *   (c) picklist       crm_picklist_values rows whose `module` is purchase_orders or vendors (read with `*`: the column set differs between hosts, so the whole row is kept and put back)
 *   (d) reports        reports rows whose `primary_module` is crm.purchase_orders or crm.vendors (live or in the trash), and the report_folders they sat in, but only a folder that is left EMPTY
 *
 * REFUSED, nothing written, exit 1 under --apply: a menu row with children, or a report that has a report_schedules or a report_shares row (it is named). A schedule or a share is somebody's
 * arrangement: the owner decides what becomes of it, not this command. A row with any other address, module or primary module is never selected and never deleted (the delete statements
 * repeat the filters). A table a host does not have (reports, report_folders, crm_picklist_values) is skipped and said so. No table, column or other row is touched: the five tables
 * (crm_purchase_orders, crm_purchase_order_line_items, crm_purchase_order_status_history, crm_vendors, crm_vendor_contacts) go in their own migration (2026_12_27_090300), which the
 * caller runs after the new backend is deployed. Idempotent: when no row matches there is nothing to do and no before-image is written.
 *
 * Exit code: 0 done or nothing to do, 1 the delete or the restore could not be completed (or was refused), 2 the command could not run (bad option, refused host, missing file or table).
 */
class CrmRetirePurchaseOrdersVendorsCommand extends Command
{
    /** @var list<string> */
    public const LINKS = ['/module/crm/sales/vendors', '/module/crm/sales/purchase-orders'];

    /** @var list<string> */
    public const PICKLIST_MODULES = ['purchase_orders', 'vendors'];

    /** @var list<string> */
    public const REPORT_MODULES = ['crm.purchase_orders', 'crm.vendors'];

    public const FORMAT = 'crm-retire-purchase-orders-vendors-before-image/1';

    protected $signature = 'crm:retire-purchase-orders-vendors
        {--apply : Delete the menu rows, their rights, the picklist values and the reports (default: show what would be deleted)}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}
        {--image-dir= : Directory for the before-image (default storage/app/crm-retire-purchase-orders-vendors)}
        {--restore= : A before-image file written by an --apply run: put its rows back}';

    protected $description = 'Dry run by default: remove the CRM Purchase Orders and Vendors menu rows, rights, picklist values and reports. --apply saves a before-image first; --restore puts the rows back.';

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

        $this->line('crm:retire-purchase-orders-vendors - ' . ($restore !== null ? 'RESTORE of ' . $restore : ($apply ? 'APPLY' : 'DRY RUN (nothing is written)')) . ' on connection ' . ($explicit ?? config('database.default')));

        return $restore !== null ? $this->undo($db, $schema, $restore) : $this->retire($db, $schema, $apply);
    }

    // ------------------------------------------------------------------ dry run and apply

    private function retire($db, $schema, bool $apply): int
    {
        $hasPicklist = $schema->hasTable('crm_picklist_values');
        $hasReports = $schema->hasTable('reports');
        $hasFolders = $schema->hasTable('report_folders');

        $menu = $db->table('tblmenumaster_g2g')->whereIn('access_link', self::LINKS)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $ids = array_map(fn (array $r) => (int) $r['id'], $menu);
        $children = $ids === [] ? [] : $db->table('tblmenumaster_g2g')->whereIn('parent_id', $ids)->orderBy('id')->get(['id', 'menu_name', 'access_link', 'parent_id'])->all();
        $rights = $ids === [] ? [] : $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $picklist = $hasPicklist ? $db->table('crm_picklist_values')->whereIn('module', self::PICKLIST_MODULES)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() : [];
        $reports = $hasReports ? $db->table('reports')->whereIn('primary_module', self::REPORT_MODULES)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() : [];
        $reportIds = array_map(fn (array $r) => (int) $r['id'], $reports);
        $folders = $hasFolders ? $this->emptiedFolders($db, $reports, $reportIds) : [];
        $keptFolders = $hasFolders ? $this->keptFolders($db, $reports, $folders) : [];

        foreach (['crm_picklist_values' => $hasPicklist, 'reports' => $hasReports, 'report_folders' => $hasFolders] as $table => $present) {
            if (! $present) {
                $this->line("  note: this connection has no {$table}; that part is skipped");
            }
        }
        if ($ids === [] && $picklist === [] && $reports === []) {
            $this->line('Nothing to do: no menu row has the address ' . implode(' or ', self::LINKS) . ', no picklist value belongs to ' . implode(' or ', self::PICKLIST_MODULES)
                . ' and no report has the primary module ' . implode(' or ', self::REPORT_MODULES) . '.');

            return self::SUCCESS;
        }

        foreach ($rights as $r) {
            $this->line(sprintf('  right %d on menu row %d: profile %s, organisation %s, view/add/edit/delete %s/%s/%s/%s', $r['id'], $r['menu_id'], $r['profile_id'] ?? '-', $r['sub_institute_id'] ?? '-',
                $r['can_view'] ?? '-', $r['can_add'] ?? '-', $r['can_edit'] ?? '-', $r['can_delete'] ?? '-'));
        }
        foreach ($menu as $r) {
            $this->line(sprintf('  menu row %d "%s" (parent %s, status %s, access_link %s)', $r['id'], $r['menu_name'] ?? '', $r['parent_id'] ?? '-', $r['status'] ?? '-', $r['access_link']));
        }
        foreach ($picklist as $r) {
            $this->line(sprintf('  picklist value %d: module %s, field %s, value "%s", organisation %s', $r['id'], $r['module'], $r['field_key'] ?? '-', $r['value'] ?? '', $r['sub_institute_id'] ?? '-'));
        }
        foreach ($reports as $r) {
            $this->line(sprintf('  report %d "%s" (organisation %s, primary module %s, folder %s%s)', $r['id'], $r['title'] ?? '', $r['sub_institute_id'] ?? '-', $r['primary_module'], $r['folder_id'] ?? '-',
                ($r['deleted_at'] ?? null) !== null ? ', in the trash' : ''));
        }
        foreach ($folders as $r) {
            $this->line(sprintf('  report folder %d "%s" (organisation %s): empty once those reports are gone', $r['id'], $r['name'] ?? '', $r['sub_institute_id'] ?? '-'));
        }
        foreach ($keptFolders as $r) {
            $this->line(sprintf('  report folder %d "%s" stays: other reports are in it', $r['id'], $r['name'] ?? ''));
        }
        $this->line(sprintf('  (a) %d right(s) granted on the menu rows (tblgroupwise_rights_g2g)', count($rights)));
        $this->line(sprintf('  (b) %d menu row(s) (tblmenumaster_g2g)', count($menu)));
        $this->line(sprintf('  (c) %d picklist value(s) (crm_picklist_values)', count($picklist)));
        $this->line(sprintf('  (d) %d report(s) (reports) and %d emptied report folder(s) (report_folders)', count($reports), count($folders)));

        $refused = false;
        foreach ($children as $c) {
            $this->line(sprintf('  REFUSED: menu row %d "%s" (%s) is a child of a row to delete; it would be orphaned', $c->id, $c->menu_name ?? '', $c->access_link ?? ''));
            $refused = true;
        }
        foreach ($this->arrangements($db, $schema, $reports, $reportIds) as $line) {
            $this->line('  REFUSED: ' . $line);
            $refused = true;
        }
        if ($refused) {
            $this->line('Nothing was written: deal with the rows named above first (move or delete the children; remove or hand over the schedule or the share).');

            return $apply ? self::FAILURE : self::SUCCESS;
        }
        if (! $apply) {
            $this->line(sprintf('%d right(s), %d menu row(s), %d picklist value(s), %d report(s) and %d report folder(s) would be deleted. Nothing was written. Add --apply to delete them (a before-image is saved first).',
                count($rights), count($menu), count($picklist), count($reports), count($folders)));

            return self::SUCCESS;
        }

        $path = $this->folder() . '/retire-purchase-orders-vendors-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        $image = [
            'format' => self::FORMAT, 'taken_at' => now()->toIso8601String(), 'links' => self::LINKS, 'picklist_modules' => self::PICKLIST_MODULES, 'report_modules' => self::REPORT_MODULES,
            'rights' => $rights, 'menu' => $menu, 'picklist' => $picklist, 'folders' => $folders, 'reports' => $reports,
        ];
        if (! $this->save($path, $image)) {
            $this->error("The before-image could not be saved to {$path}, so nothing was deleted.");

            return self::FAILURE;
        }
        $this->line(sprintf('Before-image of %d right(s), %d menu row(s), %d picklist value(s), %d report(s) and %d report folder(s) saved to %s (undo with --restore=%s)',
            count($rights), count($menu), count($picklist), count($reports), count($folders), $path, $path));

        $folderIds = array_map(fn (array $r) => (int) $r['id'], $folders);
        try {
            $db->transaction(function () use ($db, $ids, $reportIds, $folderIds, $hasPicklist, $hasReports, $hasFolders): void {
                if ($ids !== []) {
                    $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
                    // The addresses are repeated: whatever the ids say, a row with another address is never deleted.
                    $db->table('tblmenumaster_g2g')->whereIn('id', $ids)->whereIn('access_link', self::LINKS)->delete();
                }
                if ($hasPicklist) {
                    $db->table('crm_picklist_values')->whereIn('module', self::PICKLIST_MODULES)->delete();
                }
                if ($hasReports && $reportIds !== []) {
                    $db->table('reports')->whereIn('id', $reportIds)->whereIn('primary_module', self::REPORT_MODULES)->delete();
                }
                if ($hasFolders && $hasReports && $folderIds !== []) {
                    // A folder is deleted only while no report is left in it, whatever the plan said a moment ago.
                    $db->table('report_folders')->whereIn('id', $folderIds)
                        ->whereNotIn('id', $db->table('reports')->whereNotNull('folder_id')->select('folder_id'))
                        ->delete();
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was deleted)');

            return self::FAILURE;
        }
        $this->forgetMenuIds();
        $left = ($ids === [] ? 0 : $db->table('tblmenumaster_g2g')->whereIn('access_link', self::LINKS)->count() + $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->count())
            + ($hasPicklist ? $db->table('crm_picklist_values')->whereIn('module', self::PICKLIST_MODULES)->count() : 0)
            + ($hasReports ? $db->table('reports')->whereIn('primary_module', self::REPORT_MODULES)->count() : 0)
            + ($hasFolders && $folderIds !== [] ? $db->table('report_folders')->whereIn('id', $folderIds)->count() : 0);
        $this->line($left === 0 ? 'DONE: the rights, the menu rows, the picklist values, the reports and their emptied folders are gone. Running it again changes nothing.' : "INCOMPLETE: {$left} row(s) are still there.");

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The folders those reports sat in that no other report is in (a report in the trash counts: it still points at the folder).
     *
     * @param  list<array<string, mixed>>  $reports
     * @param  list<int>  $reportIds
     * @return list<array<string, mixed>>
     */
    private function emptiedFolders($db, array $reports, array $reportIds): array
    {
        $candidates = array_values(array_unique(array_filter(array_map(fn (array $r) => $r['folder_id'] === null ? null : (int) $r['folder_id'], $reports))));
        if ($candidates === []) {
            return [];
        }
        $holding = $db->table('reports')->whereIn('folder_id', $candidates)->whereNotIn('id', $reportIds)->distinct()->pluck('folder_id')->map(fn ($v) => (int) $v)->all();
        $empty = array_values(array_diff($candidates, $holding));

        return $empty === [] ? [] : $db->table('report_folders')->whereIn('id', $empty)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * The folders those reports sat in that stay because something else is in them.
     *
     * @param  list<array<string, mixed>>  $reports
     * @param  list<array<string, mixed>>  $emptied
     * @return list<array<string, mixed>>
     */
    private function keptFolders($db, array $reports, array $emptied): array
    {
        $candidates = array_values(array_unique(array_filter(array_map(fn (array $r) => $r['folder_id'] === null ? null : (int) $r['folder_id'], $reports))));
        $keep = array_values(array_diff($candidates, array_map(fn (array $r) => (int) $r['id'], $emptied)));

        return $keep === [] ? [] : $db->table('report_folders')->whereIn('id', $keep)->orderBy('id')->get(['id', 'name'])->map(fn ($r) => (array) $r)->all();
    }

    /**
     * A schedule or a share on one of the reports is somebody's arrangement: it is named, and nothing is written.
     *
     * @param  list<array<string, mixed>>  $reports
     * @param  list<int>  $reportIds
     * @return list<string>
     */
    private function arrangements($db, $schema, array $reports, array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }
        $titles = [];
        foreach ($reports as $r) {
            $titles[(int) $r['id']] = (string) ($r['title'] ?? '');
        }
        $lines = [];
        if ($schema->hasTable('report_schedules')) {
            foreach ($db->table('report_schedules')->whereIn('report_id', $reportIds)->orderBy('id')->get() as $s) {
                $lines[] = sprintf('report %d "%s" has a schedule (report_schedules row %d, %s, %s)', $s->report_id, $titles[(int) $s->report_id] ?? '', $s->id, $s->frequency ?? '-', ($s->is_active ?? 0) ? 'active' : 'switched off');
            }
        }
        if ($schema->hasTable('report_shares')) {
            foreach ($db->table('report_shares')->whereIn('report_id', $reportIds)->orderBy('id')->get() as $s) {
                $lines[] = sprintf('report %d "%s" is shared (report_shares row %d, with user %s)', $s->report_id, $titles[(int) $s->report_id] ?? '', $s->id, $s->viewer_user_id ?? '-');
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------ the way back

    private function undo($db, $schema, string $path): int
    {
        $image = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $lists = ['rights', 'menu', 'picklist', 'folders', 'reports'];
        if (! is_array($image) || ($image['format'] ?? null) !== self::FORMAT) {
            $this->error("{$path} is not a before-image written by this command.");

            return self::INVALID;
        }
        foreach ($lists as $key) {
            if (! is_array($image[$key] ?? null)) {
                $this->error("{$path} is not a before-image written by this command.");

                return self::INVALID;
            }
        }
        [$rights, $menu, $picklist, $folders, $reports] = array_map(fn (string $key) => $image[$key], $lists);

        // This command restores only what it could have deleted.
        foreach ($menu as $r) {
            if (! is_array($r) || ! in_array($r['access_link'] ?? null, self::LINKS, true)) {
                $this->error('REFUSED: the file holds a menu row with another address; this command restores only ' . implode(' and ', self::LINKS) . '. Nothing was restored.');

                return self::FAILURE;
            }
        }
        $menuIds = array_map(fn (array $r) => (int) $r['id'], $menu);
        foreach ($rights as $r) {
            if (! is_array($r) || ! in_array((int) ($r['menu_id'] ?? 0), $menuIds, true)) {
                $this->error('REFUSED: the file holds a right on a menu row that is not one of its own; this command restores only the rights of the rows it deleted. Nothing was restored.');

                return self::FAILURE;
            }
        }
        foreach ($picklist as $r) {
            if (! is_array($r) || ! in_array($r['module'] ?? null, self::PICKLIST_MODULES, true)) {
                $this->error('REFUSED: the file holds a picklist value of another module; this command restores only ' . implode(' and ', self::PICKLIST_MODULES) . '. Nothing was restored.');

                return self::FAILURE;
            }
        }
        foreach ($reports as $r) {
            if (! is_array($r) || ! in_array($r['primary_module'] ?? null, self::REPORT_MODULES, true)) {
                $this->error('REFUSED: the file holds a report with another primary module; this command restores only ' . implode(' and ', self::REPORT_MODULES) . '. Nothing was restored.');

                return self::FAILURE;
            }
        }
        $reportFolders = array_map(fn (array $r) => $r['folder_id'] ?? null, $reports);
        foreach ($folders as $r) {
            if (! is_array($r) || ! in_array($r['id'] ?? null, $reportFolders, true)) {
                $this->error('REFUSED: the file holds a report folder that none of its reports sat in; this command restores only the folders of the reports it deleted. Nothing was restored.');

                return self::FAILURE;
            }
        }
        foreach (['picklist' => ['crm_picklist_values', $picklist], 'reports' => ['reports', $reports], 'folders' => ['report_folders', $folders]] as [$table, $rows]) {
            if ($rows !== [] && ! $schema->hasTable($table)) {
                $this->error("This connection has no {$table}, which the file has rows for. Nothing was restored.");

                return self::INVALID;
            }
        }

        $ids = fn (array $rows): array => array_map(fn (array $r) => (int) $r['id'], $rows);
        $menuLinks = fn () => $db->table('tblmenumaster_g2g')->where(fn ($q) => $q->whereIn('id', $menuIds)->orWhereIn('access_link', self::LINKS));
        $counts = fn (string $table, array $rows) => $rows === [] ? 0 : $db->table($table)->whereIn('id', $ids($rows))->count();

        // Already back, exactly as the image says: nothing to do, and nothing is written.
        $present = $menuIds === [] ? 0 : $db->table('tblmenumaster_g2g')->whereIn('id', $menuIds)->whereIn('access_link', self::LINKS)->count();
        $total = count($menu) + count($rights) + count($picklist) + count($folders) + count($reports);
        $presentAll = $present + $counts('tblgroupwise_rights_g2g', $rights) + $counts('crm_picklist_values', $picklist) + $counts('report_folders', $folders) + $counts('reports', $reports);
        if ($total > 0 && $presentAll === $total) {
            $this->line('Nothing to do: every row of the before-image is already there.');

            return self::SUCCESS;
        }

        // Taken: an id of the image is in use, a menu row has one of the two addresses again, the module of the picklist values or the primary module of the reports was made again under other ids.
        $taken = ($menuIds === [] ? 0 : $menuLinks()->count()) + $counts('tblgroupwise_rights_g2g', $rights) + $counts('report_folders', $folders)
            + ($picklist === [] ? 0 : $db->table('crm_picklist_values')->where(fn ($q) => $q->whereIn('id', $ids($picklist))->orWhereIn('module', self::PICKLIST_MODULES))->count())
            + ($reports === [] ? 0 : $db->table('reports')->where(fn ($q) => $q->whereIn('id', $ids($reports))->orWhereIn('primary_module', self::REPORT_MODULES))->count());
        if ($taken > 0) {
            $this->line(sprintf('REFUSED: %d row(s) already hold these ids or these addresses; nothing was restored.', $taken));

            return self::FAILURE;
        }

        try {
            $db->transaction(function () use ($db, $menu, $rights, $picklist, $folders, $reports): void {
                foreach ($menu as $r) {
                    $db->table('tblmenumaster_g2g')->insert($r);
                }
                foreach ($rights as $r) {
                    $db->table('tblgroupwise_rights_g2g')->insert($r);
                }
                foreach ($picklist as $r) {
                    $db->table('crm_picklist_values')->insert($r);
                }
                foreach ($folders as $r) {
                    $db->table('report_folders')->insert($r);
                }
                foreach ($reports as $r) {
                    $db->table('reports')->insert($r);
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was restored)');

            return self::FAILURE;
        }
        $this->forgetMenuIds();
        $this->line(sprintf('RESTORED: %d right(s), %d menu row(s), %d picklist value(s), %d report folder(s) and %d report(s) put back with their own ids.',
            count($rights), count($menu), count($picklist), count($folders), count($reports)));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ plumbing

    private function folder(): string
    {
        $given = $this->option('image-dir');

        return rtrim(is_string($given) && $given !== '' ? $given : storage_path('app/crm-retire-purchase-orders-vendors'), '/\\');
    }

    /** MenuRight::idForAccessLink() remembers a menu id for five minutes: forget the two so a deleted or restored row is seen at once. */
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
