<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groups the Sales and Marketing Intelligence sidebar into three folders (25 Nov 2026; DB-RULES item 8):
 *
 *   Sales and Marketing Intelligence
 *     Command Center
 *     GTM        Market Signals, Playbooks
 *     Marketing  Leads, Campaigns, Outreach, Automation Rules, Agent Run History, SMS Notifier
 *     Sales      Opportunities and Deals, Quotes, Organizations, Sales Orders, Invoices, Customer Success, Reports, Master Fields   (Organizations moved from first to third on 27 Nov 2026, the owner's order: a customer becomes an Organization when the deal is won)
 *
 *   php artisan crm:group-sidebar                       DRY RUN (the default): prints the tree it would make and the rights it would add; only SELECT statements are sent
 *   php artisan crm:group-sidebar --apply               saves a before-image (storage/app/crm-sidebar/), then changes the rows in ONE transaction
 *   php artisan crm:group-sidebar --rollback=<file>     puts the menu rows back as they were and removes the rights rows this run added
 *
 * Rows are found by `access_link` (their ids are the same on dev and live today, but nothing relies on that). The three folders are the old disabled rows /gtm (renamed GTM),
 * /module/crm/marketing and /module/crm/sales. A leaf moves under its folder (parent_id, level 3, sort_order); nothing about its address or its screen changes. A row that is not
 * listed here (the disabled GTM rows, Support) is never touched.
 *
 * THE SIDEBAR API shows a folder only to a profile that holds can_view on the folder itself AND on at least one child (tblmenumasterG2gController::buildMenuTree). So for every profile
 * that holds can_view on the root and on a leaf of a folder, a can_view right on that folder is added (copied from the profile's root row, with add/edit/delete off). A profile that has
 * no right on the root sees nothing of this module before and after, so it gets nothing. Rights rows are never deleted by --apply.
 *
 * Without --connection the command runs only on the dev host (or the sqlite test database); any other host must be named, as product-master:isolate-tenants does.
 * Exit code: 0 done or nothing to do, 1 the change or the restore could not be completed, 2 the command could not run.
 */
class CrmGroupSidebarCommand extends Command
{
    public const ROOT = '/module/crm';

    public const FORMAT = 'crm-sidebar-group-before-image/1';

    /** Command Center stays directly under the root, first. */
    public const COMMAND_CENTER = '/gtm/command-center';

    /** folder access_link => [label, sort_order under the root, leaf access_links in order] */
    public const GROUPS = [
        '/gtm' => ['GTM', 20, ['/signals', '/gtm/playbooks']],
        '/module/crm/marketing' => ['Marketing', 30, [
            '/module/crm/marketing/leads', '/module/crm/marketing/campaigns', '/gtm/outreach', '/module/crm/marketing/automation-rules', '/module/crm/marketing/agent-runs', '/module/crm/sales/sms-notifier',
        ]],
        '/module/crm/sales' => ['Sales', 40, [
            '/module/crm/sales/opportunities', '/module/crm/sales/quotes', '/module/crm/marketing/organizations', '/module/crm/sales/sales-orders', '/module/crm/sales/invoices',
            '/gtm/customers', '/module/crm/reports', '/module/crm/master-fields',
        ]],
    ];

    /**
     * Screens that were merged or removed (Signal Review, Contacts, Products, Services, Price Books). Their rows STAY (the APIs of those screens find their rights through them, so
     * they are never deleted or switched off here) and the front end folds them out of the drawn sidebar, but they are NOT part of any group: a row of this list is put back directly under
     * the root, at the level and order it had before the grouping.
     */
    public const FOLDED = [
        '/signal-review' => 12,
        '/module/crm/marketing/contacts' => 26,
        '/module/crm/sales/products' => 40,
        '/module/crm/sales/services' => 41,
        '/module/crm/sales/price-books' => 42,
    ];

    protected $signature = 'crm:group-sidebar
        {--apply : Change the menu rows and add the folder rights (default: show what would change)}
        {--rollback= : A before-image file written by an --apply run: put the menu rows back and remove the rights it added}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}';

    protected $description = 'Dry run by default: group the CRM sidebar into GTM, Marketing and Sales folders. --apply saves a before-image first; --rollback puts it back.';

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

        $this->line('crm:group-sidebar - ' . ($rollback !== null ? 'ROLLBACK of ' . $rollback : ($apply ? 'APPLY' : 'DRY RUN (nothing is written)')) . ' on connection ' . ($explicit ?? config('database.default')));

        return $rollback !== null ? $this->undo($db, $rollback) : $this->group($db, $schema, $apply);
    }

    // ------------------------------------------------------------------ the plan

    /** @return array{root: ?array, menu: list<array{id: int, set: array<string, mixed>}>, rights: list<array<string, mixed>>, problems: list<string>} */
    private function plan($db, $schema): array
    {
        $hasLevel = $schema->hasColumn('tblmenumaster_g2g', 'level');
        $root = $db->table('tblmenumaster_g2g')->where('access_link', self::ROOT)->orderBy('id')->first();
        $out = ['root' => $root ? (array) $root : null, 'menu' => [], 'rights' => [], 'problems' => []];
        if ($root === null) {
            $out['problems'][] = 'No menu row has the address ' . self::ROOT . '.';

            return $out;
        }
        $rootId = (int) $root->id;
        $rootLevel = $hasLevel ? (int) ($root->level ?? 1) : 1;
        $hasDeleted = $schema->hasColumn('tblmenumaster_g2g', 'deleted_at');
        $under = function (string $link) use ($db, $hasDeleted) {
            $q = $db->table('tblmenumaster_g2g')->where('access_link', $link);
            if ($hasDeleted) {
                $q->whereNull('deleted_at');
            }

            return $q->orderBy('id');
        };
        $change = function (array $row, array $want) use (&$out): void {
            $set = [];
            foreach ($want as $column => $value) {
                if (array_key_exists($column, $row) && (string) $row[$column] !== (string) $value) {
                    $set[$column] = $value;
                }
            }
            if ($set !== []) {
                $out['menu'][] = ['id' => (int) $row['id'], 'old' => array_intersect_key($row, $set), 'set' => $set];
            }
        };

        $cc = (clone $under(self::COMMAND_CENTER))->where('parent_id', $rootId)->first();
        if ($cc !== null) {
            $change((array) $cc, ['sort_order' => 5]);
        }

        // The merged or removed screens are never grouped: a row that an earlier run moved under a folder goes back under the root.
        $folderIds = [];
        foreach (array_keys(self::GROUPS) as $folderLink) {
            $folderRow = (clone $under($folderLink))->where('parent_id', $rootId)->first();
            if ($folderRow !== null) {
                $folderIds[] = (int) $folderRow->id;
            }
        }
        foreach (self::FOLDED as $link => $sort) {
            $folded = $under($link)->whereIn('parent_id', array_merge([$rootId], $folderIds))->first();
            if ($folded !== null) {
                $wantFolded = ['parent_id' => $rootId, 'sort_order' => $sort];
                if ($hasLevel) {
                    $wantFolded['level'] = $rootLevel + 1;
                }
                $change((array) $folded, $wantFolded);
            }
        }

        foreach (self::GROUPS as $folderLink => [$label, $sort, $leaves]) {
            $folder = (clone $under($folderLink))->where('parent_id', $rootId)->first();
            if ($folder === null) {
                $out['problems'][] = "The folder row {$folderLink} was not found under the root: that group is skipped.";

                continue;
            }
            $folderId = (int) $folder->id;
            $want = ['menu_name' => $label, 'status' => 1, 'sort_order' => $sort, 'parent_id' => $rootId];
            if ($hasLevel) {
                $want['level'] = $rootLevel + 1;
            }
            $change((array) $folder, $want);

            $childIds = [];
            foreach ($leaves as $i => $link) {
                // A leaf is taken from the root OR from this folder (a second run), never from another place (Support has its own rows with other addresses).
                $leaf = $under($link)->whereIn('parent_id', [$rootId, $folderId])->first();
                if ($leaf === null) {
                    continue;
                }
                $childIds[] = (int) $leaf->id;
                $wantLeaf = ['parent_id' => $folderId, 'sort_order' => $i + 1];
                if ($hasLevel) {
                    $wantLeaf['level'] = $rootLevel + 2;
                }
                $change((array) $leaf, $wantLeaf);
            }
            if ($childIds === []) {
                continue;
            }

            // The rights: a profile that can view the root and at least one leaf of this folder must also be able to view the folder.
            $profiles = $db->table('tblgroupwise_rights_g2g')->whereIn('menu_id', $childIds)->where('can_view', 1)->distinct()->pluck('profile_id')->map(fn ($p) => (int) $p)->all();
            foreach ($profiles as $profileId) {
                $rootRight = $db->table('tblgroupwise_rights_g2g')->where('menu_id', $rootId)->where('profile_id', $profileId)->where('can_view', 1)->orderBy('id')->first();
                if ($rootRight === null) {
                    continue;
                }
                $existing = $db->table('tblgroupwise_rights_g2g')->where('menu_id', $folderId)->where('profile_id', $profileId)->orderByDesc('can_view')->orderBy('id')->first();
                if ($existing !== null && (int) $existing->can_view === 1) {
                    continue;
                }
                if ($existing !== null) {
                    $out['rights'][] = ['action' => 'update', 'id' => (int) $existing->id, 'old' => (array) $existing, 'folder' => $folderId, 'profile' => $profileId];

                    continue;
                }
                $row = (array) $rootRight;
                unset($row['id']);
                $row['menu_id'] = $folderId;
                foreach (['can_add', 'can_edit', 'can_delete', 'dashboard_right'] as $column) {
                    if (array_key_exists($column, $row)) {
                        $row[$column] = 0;
                    }
                }
                $row['can_view'] = 1;
                if (array_key_exists('created_at', $row)) {
                    $row['created_at'] = now()->toDateTimeString();
                }
                $out['rights'][] = ['action' => 'insert', 'row' => $row, 'folder' => $folderId, 'profile' => $profileId];
            }
        }

        return $out;
    }

    private function group($db, $schema, bool $apply): int
    {
        $plan = $this->plan($db, $schema);
        foreach ($plan['problems'] as $p) {
            $this->line('  NOTE: ' . $p);
        }
        if ($plan['root'] === null) {
            return $apply ? self::FAILURE : self::SUCCESS;
        }
        foreach ($plan['menu'] as $m) {
            $this->line(sprintf('  menu row %d: %s', $m['id'], json_encode($m['set'], JSON_UNESCAPED_UNICODE)));
        }
        $insert = array_values(array_filter($plan['rights'], fn (array $r) => $r['action'] === 'insert'));
        $update = array_values(array_filter($plan['rights'], fn (array $r) => $r['action'] === 'update'));
        $byFolder = [];
        foreach ($plan['rights'] as $r) {
            $byFolder[$r['folder']] = ($byFolder[$r['folder']] ?? 0) + 1;
        }
        foreach ($byFolder as $folder => $n) {
            $this->line(sprintf('  folder %d: %d profile(s) get can_view on the folder', $folder, $n));
        }
        if ($plan['menu'] === [] && $plan['rights'] === []) {
            $this->line('Nothing to do: the sidebar is already grouped.');

            return self::SUCCESS;
        }
        $this->line(sprintf('%d menu row(s) would change, %d right(s) would be added, %d right(s) would be switched on.', count($plan['menu']), count($insert), count($update)));
        if (! $apply) {
            $this->line('Nothing was written. Add --apply to do it (a before-image is saved first).');

            return self::SUCCESS;
        }

        $path = $this->folder() . '/sidebar-group-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        $image = ['format' => self::FORMAT, 'taken_at' => now()->toIso8601String(), 'menu' => $plan['menu'], 'rights_updated' => array_map(fn (array $r) => ['id' => $r['id'], 'old' => $r['old']], $update), 'rights_added' => []];
        if (! $this->save($path, $image)) {
            $this->error("The before-image could not be saved to {$path}, so nothing was changed.");

            return self::FAILURE;
        }
        $this->line("Before-image saved to {$path} (undo with --rollback={$path}; it is completed with the added rights' ids when the run ends)");

        try {
            $added = [];
            $db->transaction(function () use ($db, $plan, $insert, $update, &$added): void {
                foreach ($plan['menu'] as $m) {
                    $db->table('tblmenumaster_g2g')->where('id', $m['id'])->update($m['set']);
                }
                foreach ($insert as $r) {
                    $added[] = (int) $db->table('tblgroupwise_rights_g2g')->insertGetId($r['row']);
                }
                foreach ($update as $r) {
                    $db->table('tblgroupwise_rights_g2g')->where('id', $r['id'])->update(['can_view' => 1]);
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was changed)');

            return self::FAILURE;
        }
        $image['rights_added'] = $added;
        if (! $this->save($path, $image)) {
            $this->line("WARNING: the changes are made but the added rights' ids could not be written to {$path}: ids " . implode(',', $added));
        }
        $again = $this->plan($db, $schema);
        $left = count($again['menu']) + count($again['rights']);
        $this->line($left === 0 ? sprintf('DONE: %d menu row(s) changed, %d right(s) added, %d switched on. Running it again changes nothing.', count($plan['menu']), count($added), count($update)) : "INCOMPLETE: {$left} change(s) are still pending.");

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------ the way back

    private function undo($db, string $path): int
    {
        $image = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($image) || ($image['format'] ?? null) !== self::FORMAT || ! is_array($image['menu'] ?? null)) {
            $this->error("{$path} is not a before-image written by this command.");

            return self::INVALID;
        }
        $added = array_map('intval', (array) ($image['rights_added'] ?? []));
        try {
            $db->transaction(function () use ($db, $image, $added): void {
                foreach ($image['menu'] as $m) {
                    $db->table('tblmenumaster_g2g')->where('id', (int) $m['id'])->update((array) $m['old']);
                }
                foreach ((array) ($image['rights_updated'] ?? []) as $r) {
                    $db->table('tblgroupwise_rights_g2g')->where('id', (int) $r['id'])->update(['can_view' => (int) ($r['old']['can_view'] ?? 0)]);
                }
                if ($added !== []) {
                    $db->table('tblgroupwise_rights_g2g')->whereIn('id', $added)->delete();
                }
            });
        } catch (\Throwable $e) {
            $this->line('FAILED: ' . $e->getMessage() . ' (the transaction was taken back; nothing was restored)');

            return self::FAILURE;
        }
        $this->line(sprintf('UNDONE: %d menu row(s) put back, %d added right(s) removed, %d right(s) put back.', count($image['menu']), count($added), count((array) ($image['rights_updated'] ?? []))));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ plumbing

    private function folder(): string
    {
        return rtrim(storage_path('app/crm-sidebar'), '/\\');
    }

    private function save(string $path, array $image): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        return @file_put_contents($path, json_encode($image, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }
}
