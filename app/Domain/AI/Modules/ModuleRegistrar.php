<?php

namespace App\Domain\AI\Modules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers a G2G module with the AI architecture from the menu tree, and says what its tables are.
 *
 * A module IS a level-1 row of `tblmenumaster_g2g`. Adding one to the menu is the whole act of
 * adding a module, so this reads the menu rather than asking for a second declaration: the
 * `ai_modules` row (which the chat, the policies, the model bindings and the ledger all key on)
 * is created from it, idempotently, the first time anything asks and on a schedule.
 *
 * Nothing here deletes or rewrites a module that already exists, with one exception that is the
 * point of the work: a row registered as dormant (every capability off) that now has readable
 * data is switched on, because an off flag with data behind it is a module the stack silently
 * ignores.
 */
final class ModuleRegistrar
{
    /** What a freshly registered module may do. Generative and workflow stay off: nothing wires them. */
    private const DEFAULT_CAPABILITIES = [
        'conversational' => true,
        'generative' => false,
        'agent' => true,
        'workflow' => false,
        'ontology' => false,
    ];

    /** @return array<int, object> Active level-1 menu rows - the modules G2G has. */
    public function menuModules(): array
    {
        return DB::table('tblmenumaster_g2g')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->where(fn ($q) => $q->where('parent_id', 0)->orWhere('level', 1))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The module row for a level-1 menu, created if it does not exist yet.
     *
     * @return array{row: ?object, created: bool, reason: ?string}
     */
    public function ensureForMenu(int $menuId): array
    {
        $existing = DB::table('ai_modules')->where('menu_id', $menuId)->whereNull('sub_institute_id')->first();

        if ($existing !== null) {
            return ['row' => $existing, 'created' => false, 'reason' => null];
        }

        $menu = DB::table('tblmenumaster_g2g')->where('id', $menuId)->whereNull('deleted_at')->first();

        if ($menu === null) {
            return ['row' => null, 'created' => false, 'reason' => 'There is no such menu row.'];
        }

        if ((int) $menu->parent_id !== 0 && (int) $menu->level !== 1) {
            return ['row' => null, 'created' => false, 'reason' => 'Only a top-level menu is a module; this is a screen inside one.'];
        }

        if ((int) $menu->status !== 1) {
            return ['row' => null, 'created' => false, 'reason' => 'The module is switched off in the menu.'];
        }

        $key = $this->uniqueKey($this->keyFromLink((string) $menu->access_link, (string) $menu->menu_name), $menuId);

        $id = DB::table('ai_modules')->insertGetId([
            'module_key' => $key,
            'label' => (string) $menu->menu_name,
            'domain' => 'g2g',
            'description' => 'Registered automatically from the menu tree.',
            'capabilities' => json_encode(self::DEFAULT_CAPABILITIES),
            'registry_keys' => json_encode([]),
            'menu_id' => $menuId,
            'icon' => $menu->icon ?: null,
            'sort_order' => (int) ($menu->sort_order ?? 0),
            'status' => 1,
            'sub_institute_id' => null,
            'client_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['row' => DB::table('ai_modules')->where('id', $id)->first(), 'created' => true, 'reason' => null];
    }

    /**
     * Register every module the menu has. Returns what happened per module.
     *
     * @return array<int, array{menu_id:int, key:?string, action:string}>
     */
    public function syncAll(bool $dryRun = false): array
    {
        $report = [];

        foreach ($this->menuModules() as $menu) {
            $existing = DB::table('ai_modules')->where('menu_id', $menu->id)->whereNull('sub_institute_id')->first();

            if ($existing !== null) {
                $report[] = ['menu_id' => (int) $menu->id, 'key' => (string) $existing->module_key, 'action' => 'present'];
                continue;
            }

            if ($dryRun) {
                $report[] = ['menu_id' => (int) $menu->id, 'key' => $this->keyFromLink((string) $menu->access_link, (string) $menu->menu_name), 'action' => 'would register'];
                continue;
            }

            $result = $this->ensureForMenu((int) $menu->id);
            $report[] = ['menu_id' => (int) $menu->id, 'key' => $result['row']->module_key ?? null, 'action' => $result['created'] ? 'registered' : 'skipped: ' . $result['reason']];
        }

        return $report;
    }

    /** Whether a row is registered but has nothing switched on. */
    public function isDormant(object $row): bool
    {
        $flags = json_decode((string) ($row->capabilities ?? ''), true);

        return ! is_array($flags) || ! in_array(true, array_map('boolval', $flags), true);
    }

    /** Switch a dormant module's chat and agents on. Returns true when it changed anything. */
    public function enable(object $row): bool
    {
        if (! $this->isDormant($row)) {
            return false;
        }

        DB::table('ai_modules')->where('id', $row->id)->update([
            'capabilities' => json_encode(self::DEFAULT_CAPABILITIES),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Table prefixes a module's tables are expected to carry: one per distinct first path segment
     * of the access links in its menu subtree (`/module/crm/sales` -> `crm_`, `/gtm/deals` -> `gtm_`),
     * plus the module key's own words, plus anything declared in config.
     *
     * @return array<int, string>
     */
    public function prefixesFor(string $moduleKey, ?int $menuId): array
    {
        $prefixes = [];

        if ($menuId !== null) {
            foreach ($this->subtreeLinks($menuId) as $link) {
                $slug = $this->firstSlug($link);

                if ($slug !== null) {
                    $prefixes[] = str_replace('-', '_', $slug) . '_';
                }
            }
        }

        foreach ((array) config('ai_modules.table_prefixes.' . $moduleKey, []) as $declared) {
            $prefixes[] = (string) $declared;
        }

        // Never a bare, generic word: `module_` or `platform_services_` would claim other modules' tables.
        $prefixes = array_filter($prefixes, fn ($p) => ! in_array($p, ['module_', 'platform_services_', 'api_', 'ai_', 'tbl_'], true) && strlen($p) > 3);

        return array_values(array_unique($prefixes));
    }

    /** @return array<int, string> access_links of the menu and everything beneath it. */
    private function subtreeLinks(int $menuId): array
    {
        $links = [];
        $frontier = [$menuId];

        for ($depth = 0; $depth < 5 && $frontier !== []; $depth++) {
            $rows = DB::table('tblmenumaster_g2g')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereIn('id', $depth === 0 ? $frontier : [])->orWhereIn('parent_id', $frontier))
                ->get(['id', 'access_link']);

            $next = [];
            foreach ($rows as $row) {
                if ($row->access_link) {
                    $links[] = (string) $row->access_link;
                }
                if ((int) $row->id !== $menuId) {
                    $next[] = (int) $row->id;
                }
            }
            $frontier = $next;
        }

        return $links;
    }

    /** `/module/crm/sales` -> `crm`; `/gtm/deals` -> `gtm`; `/platform-services/x` -> null. */
    private function firstSlug(string $link): ?string
    {
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');
        $parts = $path === '' ? [] : explode('/', $path);

        if ($parts === []) {
            return null;
        }

        if ($parts[0] === 'module') {
            return $parts[1] ?? null;
        }

        return in_array($parts[0], ['platform-services', 'ai', 'api'], true) ? null : $parts[0];
    }

    private function keyFromLink(string $link, string $fallbackName): string
    {
        $slug = $this->firstSlug($link) ?? Str::slug($fallbackName);

        return Str::snake(str_replace('-', '_', $slug)) ?: 'module';
    }

    private function uniqueKey(string $key, int $menuId): string
    {
        return DB::table('ai_modules')->where('module_key', $key)->exists() ? $key . '_' . $menuId : $key;
    }
}
