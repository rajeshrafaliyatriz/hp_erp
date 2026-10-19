<?php

namespace App\Domain\AI\Discovery;

use App\Domain\AI\Modules\ModuleRegistrar;
use App\Services\Ai\AiRequestScope;
use App\Support\MenuRight;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns discovered tables into read-only data sources for `ModuleDataSourceCatalog`.
 *
 * The sources are shaped exactly like the hand-written ones (name, module, label, arguments,
 * query closure), so Knowledge Base, Templates, Automations, the chat's tool selection, grounding
 * and the report builder treat them identically. What differs is how much is promised: a
 * discovered source is a faithful, tenant-scoped LIST of one table with its parent records named
 * - nothing derived, no business rule.
 *
 * ACCESS: the organisation filter is part of every query and cannot be argued with. On top of
 * that, a person who is not an administrator must hold the `view` right on the module's own menu
 * row; otherwise the source refuses to run rather than returning rows or an empty list.
 */
final class DiscoveredSources
{
    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $memo = null;

    /** @var array<string, bool> */
    private array $rights = [];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $defs = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $moduleList = null;

    public function __construct(
        private readonly ModuleSchemaDiscovery $discovery,
        private readonly ModuleRegistrar $registrar,
    ) {
    }

    /**
     * Modules whose sources are discovered: registered top-level modules that are not curated.
     *
     * @return array<int, array{key:string, label:string, menu_id:int, prefixes:array<int,string>}>
     */
    public function modules(): array
    {
        // Discovery is an addition to the hand-written catalogue and must never be able to take it down:
        // a missing table, an unreadable schema or a bad menu row means "no discovered sources", not an error.
        try {
            return $this->moduleList ??= $this->loadModules();
        } catch (Throwable $exception) {
            report($exception);

            return $this->moduleList = [];
        }
    }

    /** @return array<int, array{key:string, label:string, menu_id:int, prefixes:array<int,string>}> */
    private function loadModules(): array
    {
        $curated = (array) config('ai_modules.curated', []);
        $out = [];

        foreach (DB::table('ai_modules')->whereNull('sub_institute_id')->whereNotNull('menu_id')->where('status', 1)->get(['module_key', 'label', 'menu_id']) as $row) {
            if (in_array($row->module_key, $curated, true) || ! $this->isTopLevel((int) $row->menu_id)) {
                continue;
            }

            $out[] = [
                'key' => (string) $row->module_key,
                'label' => (string) $row->label,
                'menu_id' => (int) $row->menu_id,
                'prefixes' => $this->registrar->prefixesFor((string) $row->module_key, (int) $row->menu_id),
            ];
        }

        return $out;
    }

    /**
     * Catalogue definitions for every discovered module.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->defs ??= $this->loadDefinitions();
    }

    /** @return array<int, array<string, mixed>> */
    private function loadDefinitions(): array
    {
        $defs = [];

        foreach ($this->modules() as $module) {
            foreach ($this->specsFor($module['key'], $module['prefixes']) as $spec) {
                $defs[] = $this->definition($module['key'], $module['menu_id'], $spec);
            }
        }

        return $defs;
    }

    /**
     * What discovery found for one module: sources, skipped tables with reasons, relationships.
     *
     * @return array<string, mixed>
     */
    public function report(string $moduleKey): array
    {
        foreach ($this->modules() as $module) {
            if ($module['key'] !== $moduleKey) {
                continue;
            }

            $found = $this->discovery->discover($moduleKey, $module['prefixes']);

            return [
                'discovered' => true,
                'prefixes' => $module['prefixes'],
                'fingerprint' => $found['fingerprint'],
                'tables' => array_map(fn (array $s) => [
                    'source' => $this->nameFor($moduleKey, $s['table'], $module['prefixes'], $found['tables']),
                    'table' => $s['table'],
                    'columns' => array_column($s['columns'], 'name'),
                    'withheld_columns' => $s['dropped_columns'],
                    'relationships' => array_map(fn ($j) => ['column' => $j['column'], 'references' => $j['parent'], 'shown_as' => $j['alias']], $s['joins']),
                ], $found['tables']),
                'skipped' => $found['skipped'],
            ];
        }

        return ['discovered' => false, 'prefixes' => [], 'fingerprint' => null, 'tables' => [], 'skipped' => []];
    }

    /**
     * The data behind a menu page of a discovered module, when exactly one discovered source is named like it.
     *
     * `/module/crm/sales/quotes` and `/gtm/deals` lead to the sources `crm.quotes` and `crm.deals`; "Sales Orders"
     * to `crm.sales_orders`. A page matching none, or more than one, is left unmapped: a screen whose data cannot
     * be named from its own name (a settings page, a dashboard) is reported as such rather than given a guess.
     *
     * @return array{sources: array<int,string>, purpose: string}|null
     */
    public function pageEntry(string $moduleKey, string $route, string $title): ?array
    {
        $slugs = array_values(array_unique(array_filter([
            str_replace('-', '_', strtolower(basename(trim((string) parse_url($route, PHP_URL_PATH), '/')))),
            Str::snake($title),
        ])));
        $hits = [];

        foreach ($this->definitions() as $definition) {
            if ($definition['module'] !== $moduleKey) {
                continue;
            }

            $short = substr((string) $definition['name'], strlen($moduleKey) + 1);

            foreach ($slugs as $slug) {
                if ($short === $slug || Str::plural($slug) === $short || Str::singular($short) === $slug || str_ends_with($short, '_' . $slug)) {
                    $hits[$definition['name']] = $definition['label'];
                }
            }
        }

        if (count($hits) !== 1) {
            return null;
        }

        return [
            'sources' => array_keys($hits),
            'purpose' => sprintf('%s: the %s records of your organisation. Mapped to this page by its name; the source was discovered from the database, not written for this page.', $title, mb_strtolower((string) array_values($hits)[0])),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function specsFor(string $moduleKey, array $prefixes): array
    {
        $this->memo ??= [];

        return $this->memo[$moduleKey] ??= (function () use ($moduleKey, $prefixes) {
            try {
                return $this->discovery->discover($moduleKey, $prefixes)['tables'];
            } catch (Throwable) {
                return [];
            }
        })();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function definition(string $moduleKey, int $menuId, array $spec): array
    {
        $prefixes = $this->registrar->prefixesFor($moduleKey, $menuId);
        $name = $this->nameFor($moduleKey, $spec['table'], $prefixes, $this->specsFor($moduleKey, $prefixes));
        $thing = Str::headline($this->stripPrefix($spec['table'], $prefixes));
        $hasStatus = in_array('status', array_column($spec['columns'], 'name'), true);

        $arguments = [['key' => 'limit', 'type' => 'integer', 'description' => 'Maximum rows to return (1–500, default 200).', 'required' => false]];

        if ($hasStatus) {
            $arguments[] = ['key' => 'status', 'type' => 'string', 'description' => 'Only rows with this status, exactly as stored.', 'required' => false];
        }

        $relationships = array_map(fn ($j) => $j['alias'] . ' (from ' . $j['parent'] . ')', $spec['joins']);

        return [
            'name' => $name,
            'module' => $moduleKey,
            'label' => $thing,
            'description' => sprintf(
                'Every %s record of your organisation, read from table %s (discovered automatically). Columns: %s%s. A plain list: no derived figures or business rules.',
                mb_strtolower($thing),
                $spec['table'],
                implode(', ', array_column($spec['columns'], 'name')),
                $relationships === [] ? '' : '; names the record behind ' . implode(', ', $relationships)
            ),
            'arguments' => $arguments,
            'discovered' => true,
            'query' => fn (AiRequestScope $scope, array $a): Builder => $this->query($spec, $moduleKey, $menuId, $scope, $a),
        ];
    }

    /** @param array<string, mixed> $spec @param array<string, mixed> $a */
    private function query(array $spec, string $moduleKey, int $menuId, AiRequestScope $scope, array $a): Builder
    {
        if (! $this->mayRead($scope, $menuId)) {
            throw new RuntimeException('Your role does not have view rights on this module, so its records are not read for you.');
        }

        $table = $spec['table'];
        $q = DB::table($table . ' as c');

        if ($spec['tenant_via'] === null) {
            $q->where('c.sub_institute_id', $scope->selectedInstituteId);
        } else {
            // No organisation column of its own: the rows count only if the parent they belong to is the caller's.
            $via = $spec['tenant_via'];
            $q->join($via['parent'] . ' as pv', 'pv.' . $via['parent_key'], '=', 'c.' . $via['column'])
                ->where('pv.sub_institute_id', $scope->selectedInstituteId);

            if ($this->parentSoftDeletes($moduleKey, $menuId, $via['parent'])) {
                $q->whereNull('pv.deleted_at');
            }
        }

        if ($spec['soft_deletes']) {
            $q->whereNull('c.deleted_at');
        }

        $textChars = (int) config('ai_modules.text_chars', 200);
        $selected = [];

        foreach ($spec['columns'] as $column) {
            $ident = $this->ident($column['name']);
            $isText = in_array($column['type_name'], ['text', 'tinytext', 'mediumtext', 'longtext'], true);

            if ($isText) {
                $q->selectRaw("SUBSTRING(c.{$ident}, 1, {$textChars}) as {$ident}");
            } else {
                $q->addSelect('c.' . $column['name']);
            }
            $selected[$column['name']] = true;
        }

        foreach ($spec['joins'] as $i => $join) {
            $alias = 'p' . $i;
            $q->leftJoin($join['parent'] . ' as ' . $alias, function ($j) use ($alias, $join, $scope) {
                $j->on($alias . '.' . $join['parent_key'], '=', 'c.' . $join['column'])
                    ->where($alias . '.sub_institute_id', '=', $scope->selectedInstituteId);
            });
            $q->selectRaw($alias . '.' . $this->ident($join['label']) . ' as ' . $this->ident($join['alias']));
        }

        if (isset($a['status']) && $a['status'] !== '' && isset($selected['status'])) {
            $q->where('c.status', (string) $a['status']);
        }

        if ($spec['order_by'] !== null && isset($selected[$spec['order_by']])) {
            $q->orderByDesc('c.' . $spec['order_by']);
        }

        return $q;
    }

    private function parentSoftDeletes(string $moduleKey, int $menuId, string $parent): bool
    {
        foreach ($this->specsFor($moduleKey, $this->registrar->prefixesFor($moduleKey, $menuId)) as $spec) {
            if ($spec['table'] === $parent) {
                return (bool) $spec['soft_deletes'];
            }
        }

        return false;
    }

    private function mayRead(AiRequestScope $scope, int $menuId): bool
    {
        if ($scope->isAdmin) {
            return true;
        }

        $key = $scope->userProfileId . '|' . $menuId . '|' . $scope->selectedInstituteId;

        return $this->rights[$key] ??= ($scope->userProfileId !== null
            && MenuRight::can((int) $scope->userProfileId, $menuId, (int) $scope->selectedInstituteId, 'view'));
    }

    /** @param array<int, string> $prefixes @param array<int, array<string, mixed>> $specs */
    private function nameFor(string $moduleKey, string $table, array $prefixes, array $specs): string
    {
        $short = $this->stripPrefix($table, $prefixes);
        $clashes = 0;

        foreach ($specs as $other) {
            if ($this->stripPrefix($other['table'], $prefixes) === $short) {
                $clashes++;
            }
        }

        return $moduleKey . '.' . ($clashes > 1 ? $table : $short);
    }

    /** @param array<int, string> $prefixes */
    private function stripPrefix(string $table, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return substr($table, strlen($prefix));
            }
        }

        return $table;
    }

    /** A column/alias name that is safe to place inside backticks. */
    private function ident(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }

    private function isTopLevel(int $menuId): bool
    {
        $row = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first(['parent_id', 'level']);

        return $row !== null && ((int) $row->parent_id === 0 || (int) $row->level === 1);
    }
}
