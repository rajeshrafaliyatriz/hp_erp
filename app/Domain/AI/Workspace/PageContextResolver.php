<?php

namespace App\Domain\AI\Workspace;

use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Support\SchemaCache;
use App\Domain\AI\Examples\Sources\SourceRegistry;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Where the user is, and what is worth asking from there.
 *
 * The page is a row of `tblmenumaster_g2g`. Its module is the nearest `ai_modules` row at or
 * above it in the menu tree - the same `menu_id` + `parent_id` derivation `ModuleRollUp` and
 * `ModuleDataSourceCatalog` use, so a page needs no registration of its own: "Organization
 * Profile" has no `ai_modules` row and resolves to Organizational Management; "Course
 * Builder" has one and resolves to itself.
 *
 * SUGGESTIONS ARE BUILT FROM REAL SOURCES, IN THIS ORDER
 *
 *   1. Curated: `ai_suggestions` rows (capability `conversational`) for the page's module or its
 *      top-level module, tenant rows alongside platform rows, filtered by the caller's role.
 *   2. Derived: the starter questions of the data sources the module reads
 *      (`SourceQuestions`) - the sources of the page's own module first, then the rest of the
 *      top-level module's.
 *
 * Nothing is borrowed from another module: a page outside any module with an `ai_modules` row
 * resolves to no module and gets no suggestions, rather than a generic list.
 *
 * The page's title and breadcrumb come from the database row, never from the client, because
 * they end up in the model's instructions.
 */
final class PageContextResolver
{
    /** At most this many suggestions are returned. */
    private const MAX_SUGGESTIONS = 6;

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly SourceQuestions $questions,
        private readonly SourceFacets $facets,
        private readonly PageQuestions $pageQuestions,
        private readonly SchemaCache $schema,
        private readonly AiStackTabContext $stackTabs,
    ) {
    }

    /**
     * @return array{
     *   module: array{key:string,label:string,root_key:string,root_label:string}|null,
     *   page: array{id:int,title:string,breadcrumb:array<int,string>,route:?string}|null,
     *   suggestions: array<int, array{id:string,label:string,prompt:string,origin:string,data_source:?string}>
     * }
     */
    public function resolve(
        AiRequestScope $scope,
        ?int $menuId,
        ?string $route = null,
        ?array $pageData = null,
        ?string $aiStackModule = null,
        ?string $aiStackTab = null
    ): array {
        // On a module's AI Stack the page IS the tab: its module and tab come from the screen itself
        // (the AI Stack has no menu row), and the questions are about that tab's real content.
        if ($aiStackModule !== null) {
            $stack = $this->aiStackContext($scope, $aiStackModule, $aiStackTab, $route);

            if ($stack !== null) {
                return $stack;
            }
        }

        $empty = ['module' => null, 'page' => null, 'suggestions' => $this->pageSuggestions($pageData), 'scope' => 'none'];
        $empty['scope'] = $empty['suggestions'] === [] ? 'none' : 'page';

        if (! $this->schema->hasTable('tblmenumaster_g2g') || ! $this->schema->hasTable('ai_modules')) {
            return $empty;
        }

        $page = $this->pageRow($menuId, $route);

        if ($page === null) {
            return $empty;
        }

        $chain = $this->chain((int) $page->id);
        $institute = $scope->selectedInstituteId;

        // Nearest module at or above the page, and the top-level module it sits under.
        $nearest = null;
        foreach ($chain as $row) {
            $nearest = $this->moduleForMenu((int) $row->id, $institute);
            if ($nearest !== null) {
                break;
            }
        }

        $root = $this->moduleForMenu((int) end($chain)->id, $institute);
        $pageInfo = [
            'id' => (int) $page->id,
            'title' => (string) $page->menu_name,
            'breadcrumb' => array_values(array_map(fn ($row) => (string) $row->menu_name, array_reverse($chain))),
            'route' => $page->access_link === null ? null : (string) $page->access_link,
        ];

if ($nearest === null || $root === null) {
            // A page outside every AI module still has a screen: its questions come from what is on
            // it. Nothing module-level is offered, because there is no module.
            $onScreen = $this->pageSuggestions($pageData);

            return ['module' => null, 'page' => $pageInfo, 'suggestions' => $onScreen, 'scope' => $onScreen === [] ? 'none' : 'page'];
        }

        // WHAT IS ON THE SCREEN COMES FIRST. When the page itself yields questions, those are the
        // whole answer: the module's general questions are not mixed in beside them.
        $onPage = $this->pageSuggestions($pageData);

        return [
            'module' => [
                'key' => (string) $nearest->module_key,
                'label' => (string) $nearest->label,
                'root_key' => (string) $root->module_key,
                'root_label' => (string) $root->label,
            ],
            'page' => $pageInfo,
            'suggestions' => $onPage !== [] ? $onPage : $this->suggestions($scope, (string) $nearest->module_key, (string) $root->module_key, $pageInfo['breadcrumb'], $pageInfo['route']),
            'scope' => $onPage !== [] ? 'page' : 'module',
        ];
    }

    /**
     * The AI Stack tab the user has open, as a page context.
     *
     * @return array<string, mixed>|null Null when the module is not a registered AI module.
     */
    private function aiStackContext(AiRequestScope $scope, string $moduleKey, ?string $tab, ?string $route): ?array
    {
        $module = DB::table('ai_modules')
            ->where('module_key', $moduleKey)
            ->where(fn ($q) => $q->where('sub_institute_id', $scope->selectedInstituteId)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first(['module_key', 'label']);

        if ($module === null) {
            return null;
        }

        $label = (string) $module->label;
        $tabId = $this->stackTabs->valid($tab);
        $moduleInfo = ['key' => (string) $module->module_key, 'label' => $label, 'root_key' => (string) $module->module_key, 'root_label' => $label];

        if ($tabId === null) {
            return null;
        }

        $questions = $this->stackTabs->questions($scope, (string) $module->module_key, $label, $tabId);

        return [
            'module' => $moduleInfo,
            'page' => [
                'id' => 0,
                'title' => $label . ' AI Stack - ' . $this->stackTabs->label($tabId),
                'breadcrumb' => [$label, 'AI Stack', $this->stackTabs->label($tabId)],
                'route' => $route,
            ],
            'suggestions' => array_map(fn (string $q, int $i) => ['id' => 'tab-' . $i, 'label' => $q, 'prompt' => $q, 'origin' => 'tab', 'data_source' => null], $questions, array_keys($questions)),
            'scope' => 'tab',
        ];
    }

    /**
     * Questions derived from the snapshot of the page the browser read.
     *
     * @return array<int, array{id:string,label:string,prompt:string,origin:string,data_source:?string}>
     */
    private function pageSuggestions(?array $pageData): array
    {
        $snapshot = PageSnapshot::clean($pageData);

        if ($snapshot === null) {
            return [];
        }

        $suggestions = [];

        foreach ($this->pageQuestions->derive($snapshot) as $index => $question) {
            $suggestions[] = ['id' => 'page-' . $index, 'label' => $question, 'prompt' => $question, 'origin' => 'page', 'data_source' => null];
        }

        return $suggestions;
    }

    /** The menu row for an id, or - failing that - the row whose access link is this route. */
    private function pageRow(?int $menuId, ?string $route): ?object
    {
        $query = DB::table('tblmenumaster_g2g')->whereNull('deleted_at');

        if ($menuId !== null && $menuId > 0) {
            return (clone $query)->where('id', $menuId)->first();
        }

        $route = $route === null ? '' : '/' . trim(strtok($route, '?#') ?: '', '/');

        return $route === '/' ? null : (clone $query)->where('access_link', $route)->orderBy('level')->first();
    }

    /**
     * The page row, then each parent up to the level-1 module (at most six hops).
     *
     * @return array<int, object>
     */
    private function chain(int $menuId): array
    {
        $rows = [];

        for ($hops = 0; $hops < 6; $hops++) {
            $row = DB::table('tblmenumaster_g2g')->where('id', $menuId)->first(['id', 'menu_name', 'parent_id', 'level', 'access_link']);

            if ($row === null) {
                break;
            }

            $rows[] = $row;

            if ((int) $row->parent_id === 0 || (int) $row->level === 1) {
                break;
            }

            $menuId = (int) $row->parent_id;
        }

        return $rows;
    }

    /** The `ai_modules` row bound to a menu row: the institute's own, else the platform's. */
    private function moduleForMenu(int $menuId, int|string|null $institute): ?object
    {
        return DB::table('ai_modules')
            ->where('menu_id', $menuId)
            ->where('status', 1)
            ->where(fn ($q) => $q->where('sub_institute_id', $institute)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')
            ->first();
    }

    /**
     * @param  array<int, string>  $breadcrumb
     * @return array<int, array{id:string,label:string,prompt:string,origin:string,data_source:?string}>
     */
    private function suggestions(AiRequestScope $scope, string $moduleKey, string $rootKey, array $breadcrumb, ?string $route = null): array
    {
        $out = [];
        $seen = [];

        $add = function (string $id, string $prompt, string $origin, ?string $source) use (&$out, &$seen): void {
            $key = mb_strtolower(trim($prompt));

            if ($key === '' || isset($seen[$key]) || count($out) >= self::MAX_SUGGESTIONS) {
                return;
            }

            $seen[$key] = true;
            $out[] = ['id' => $id, 'label' => $prompt, 'prompt' => $prompt, 'origin' => $origin, 'data_source' => $source];
        };

        foreach ($this->curated($scope, array_values(array_unique([$moduleKey, $rootKey]))) as $row) {
            $prompt = trim((string) ($row->prompt ?: $row->label));
            $add('curated-' . $row->id, $prompt, 'curated', null);
        }

        // ONLY the page's own module's sources. A screen with its own module (Course Builder) is
        // not offered its sibling screens' questions; a page directly under a top-level module
        // (Organization Profile) gets that module's sources, which include its screens'.
        // A page the module has declared (its `pages()` map) uses ITS OWN sources - exactly the data behind that
        // page. Only an undeclared page falls back to guessing from the words of its breadcrumb.
        $declared = $route === null ? [] : (array) (SourceRegistry::pages()[$route]['sources'] ?? []);
        $mapped = array_values(array_filter(array_map(fn (string $name) => $this->sources->describe($name), $declared)));
        $sources = $mapped !== [] ? $mapped : $this->narrow($this->sources->forModule($moduleKey), $breadcrumb);

        foreach ($sources as $source) {
            foreach ($this->questions->forSource($source) as $index => $question) {
                $add('source-' . $source['name'] . '-' . $index, $question, 'data', $source['name']);
            }

            // Questions drawn from what the rows actually contain - this organisation's real
            // departments, statuses and categories - so they differ from tenant to tenant and
            // need no per-page code.
            foreach ($this->dataQuestions($scope, $source) as $index => $question) {
                $add('data-' . $source['name'] . '-' . $index, $question, 'data', $source['name']);
            }
        }

        return $out;
    }

    /**
     * Questions built from the source's own rows: a breakdown by its headline category, and a
     * count for the category that is most common.
     *
     * @param  array<string, mixed>  $source
     * @return array<int, string>
     */
    private function dataQuestions(AiRequestScope $scope, array $source): array
    {
        $summary = $this->summary($scope, $source);

        if ($summary === null || $summary['facets'] === []) {
            return [];
        }

        // A coded value (status 1, level 2) means nothing to the person reading the question,
        // so the first facet whose values are words is the one asked about.
        $facet = null;

        foreach ($summary['facets'] as $candidate) {
            if (! is_numeric($candidate['values'][0]['value'])) {
                $facet = $candidate;
                break;
            }
        }

        if ($facet === null) {
            return [];
        }

        $noun = $this->facets->noun((string) $source['label']);

        // Worded so it reads correctly for any noun, singular or plural.
        return [
            sprintf('What is the breakdown of %s by %s?', $noun, $facet['label']),
            sprintf('For %s, how many have %s "%s"?', $noun, $facet['label'], $facet['values'][0]['value']),
        ];
    }

    /**
     * The shape of a source's rows for this caller - counts and facets only, never the rows.
     *
     * Cached briefly because a page change would otherwise re-run the query. The key carries
     * the tenant, the user and whether they are an administrator, since a source can narrow
     * itself to the caller. Only aggregates are cached: some sources are about people.
     *
     * @param  array<string, mixed>  $source
     * @return array{total:int, truncated:bool, facets:array<int, array<string, mixed>>}|null
     */
    private function summary(AiRequestScope $scope, array $source): ?array
    {
        $key = sprintf(
            'ai.page.summary.%d.%d.%d.%s',
            $scope->selectedInstituteId,
            $scope->userId,
            $scope->isAdmin ? 1 : 0,
            $source['name']
        );

        try {
            return Cache::remember($key, 300, function () use ($scope, $source) {
                $result = $this->sources->run((string) $source['name'], $scope, ['limit' => 500]);

                return [
                    'total' => (int) $result['total'],
                    'truncated' => (bool) $result['truncated'],
                    'facets' => $this->facets->compute($result['rows']),
                ];
            });
        } catch (Throwable) {
            // An unreadable source costs its own questions, not the page's.
            return null;
        }
    }

    /**
     * Within one module, keep the sources the page is about.
     *
     * A source matches when a word of its name or label (Attendance, Leave, Departments) is a
     * word of the page's breadcrumb - "HRIT Management > Attendance Management > Attendance
     * Report" matches `hrms.attendance`. When nothing matches the page says nothing more
     * specific than its module, so every source of the module is kept: that is the honest
     * reading, not a guess at the nearest one.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @param  array<int, string>  $breadcrumb
     * @return array<int, array<string, mixed>>
     */
    private function narrow(array $sources, array $breadcrumb): array
    {
        $pageWords = $this->words(implode(' ', array_slice($breadcrumb, 1)));

        if ($pageWords === []) {
            return $sources;
        }

        $matched = array_values(array_filter($sources, function (array $source) use ($pageWords) {
            $sourceWords = $this->words(str_replace(['.', '_'], ' ', (string) $source['name']) . ' ' . $source['label']);

            return array_intersect($sourceWords, $pageWords) !== [];
        }));

        return $matched === [] ? $sources : $matched;
    }

    /**
     * Lower-case words of four letters or more, with a plural `s` folded away. Words that name
     * the product area rather than a record (management, report, setup) never decide a match.
     *
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        $ignore = ['management', 'report', 'reports', 'setup', 'administration', 'module', 'data', 'status', 'build', 'list'];
        $words = [];

        foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [] as $word) {
            $word = preg_replace('/s$/', '', $word) ?? $word;

            if (mb_strlen($word) >= 4 && ! in_array($word, $ignore, true)) {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * Curated conversational suggestions the caller may see.
     *
     * Rows that need a selected record (`requires_entity`) are left out: a chat opened on a page
     * with no record selected has nothing to point them at.
     *
     * @param  array<int, string>  $moduleKeys
     * @return array<int, object>
     */
    private function curated(AiRequestScope $scope, array $moduleKeys): array
    {
        if (! $this->schema->hasTable('ai_suggestions')) {
            return [];
        }

        $institute = $scope->selectedInstituteId;

        $rows = DB::table('ai_suggestions')
            ->whereIn('module_key', $moduleKeys)
            ->where('capability', 'conversational')
            ->where('status', 1)
            ->where('requires_entity', 0)
            ->where(fn ($q) => $q->whereNull('sub_institute_id')->orWhere('sub_institute_id', $institute))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'label', 'prompt', 'allowed_roles'])
            ->all();

        return array_values(array_filter($rows, function ($row) use ($scope) {
            $roles = json_decode((string) $row->allowed_roles, true);

            return ! is_array($roles) || $roles === [] || in_array($scope->role, $roles, true);
        }));
    }
}
