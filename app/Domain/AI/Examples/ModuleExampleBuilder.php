<?php

namespace App\Domain\AI\Examples;

use App\Domain\AI\Chat\ChatReportService;
use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Modules\ModuleRollUp;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Domain\AI\Templates\TemplateCatalog;
use App\Domain\AI\Workspace\SourceFacets;
use App\Http\Controllers\AI\AiModuleController;
use App\Http\Controllers\AI\AiModuleModelController;
use App\Services\Ai\AiPolicyResolver;
use App\Services\Ai\AiRequestScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One worked, runnable example for each tab of a module's AI Stack, built live.
 *
 * EVERYTHING IS READ AT REQUEST TIME
 *
 * No figure, name or sentence about data is stored. Counts come from the module's own
 * read-only data sources (`ModuleDataSourceCatalog`), the policy and model facts from the
 * same resolvers the AI calls use, usage / guardrail / activity facts from the same
 * controller methods the tabs call, and approvals from `ai_action_requests`. Change the
 * underlying rows and the next response changes with them. When a module has nothing to
 * show, the example says so and explains what would appear; it never fills the gap.
 *
 * Every example carries `run`: the one thing the tab's primary button does. The browser
 * performs it with the calls the rest of the app already makes (chat, report build, agent
 * run, guardrail check), so a run is as real as anything else the user does there.
 */
final class ModuleExampleBuilder
{
    /** Rows read per source for counting; the catalogue's own ceiling. */
    private const READ_LIMIT = 500;

    /** Characters of row data put in a rendered prompt. */
    private const RECORD_CHARS = 2400;

    /** @var array<string, array<string, mixed>> */
    private array $reads = [];

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ModuleRollUp $rollUp,
        private readonly ModuleGrounding $grounding,
        private readonly SourceFacets $facets,
        private readonly ChatReportService $reports,
        private readonly TemplateCatalog $templates,
        private readonly AiPolicyResolver $policies,
        private readonly GuardrailCheck $guardrails,
    ) {
    }

    /**
     * @return array<string, mixed>|null Null when the key is not a registered AI module.
     */
    public function build(Request $request, AiRequestScope $scope, string $module, bool $rollup, ?string $only = null): ?array
    {
        $row = $this->grounding->module($module, $scope->selectedInstituteId);

        if ($row === null) {
            return null;
        }

        $this->reads = [];

        $ctx = [
            'request' => $request,
            'scope' => $scope,
            'inst' => $scope->selectedInstituteId,
            'module' => $module,
            'label' => (string) $row->label,
            'rollup' => $rollup,
            'keys' => $rollup ? $this->rollUp->keysFor($module) : [$module],
            'sources' => $this->sources->forModule($module),
            'definition' => $this->definitionFor($module),
        ];

        $tabs = [];

        foreach ([
            'policies' => 'policies',
            'models' => 'models',
            'prompts' => 'prompts',
            'templates' => 'templates',
            'knowledge-base' => 'knowledgeBase',
            'automations' => 'automations',
            'usage-cost' => 'usageCost',
            'guardrails' => 'guardrails',
            'activity' => 'activity',
            'approvals' => 'approvals',
        ] as $tab => $method) {
            // One tab only, when the caller needs just that one (the chat's tab context).
            if ($only !== null && $tab !== $only) {
                continue;
            }

            try {
                $tabs[$tab] = ['tab' => $tab] + $this->{$method}($ctx);
            } catch (Throwable $e) {
                report($e);
                $tabs[$tab] = [
                    'tab' => $tab,
                    'status' => 'attention',
                    'title' => 'This example could not be built',
                    'explanation' => 'Reading the records for this tab failed, so nothing is shown rather than a guess.',
                    'facts' => [],
                    'items' => [],
                    'run' => ['kind' => 'none', 'label' => 'Unavailable', 'reason' => 'The example could not be built.'],
                    'note' => null,
                ];
            }
        }

        return [
            'module' => ['key' => $module, 'label' => (string) $row->label],
            'rollup' => $rollup,
            'generated_at' => now()->toIso8601String(),
            'tabs' => $tabs,
        ];
    }

    // ------------------------------------------------------------------ Policies

    /** @param array<string, mixed> $ctx */
    private function policies(array $ctx): array
    {
        $inst = $ctx['inst'];
        $label = $ctx['label'];

        $moduleIds = DB::table('ai_modules')
            ->whereIn('module_key', $ctx['keys'])
            ->where(fn ($q) => $q->where('sub_institute_id', $inst)->orWhereNull('sub_institute_id'))
            ->pluck('id')->all();

        $assigned = $moduleIds === [] ? collect() : DB::table('ai_policies as p')
            ->join('ai_policy_assignments as a', 'a.policy_id', '=', 'p.id')
            ->where('a.scope_type', 'module')
            ->where('a.status', 1)
            ->whereIn('a.scope_id', $moduleIds)
            ->where(fn ($q) => $q->where('p.sub_institute_id', $inst)->orWhereNull('p.sub_institute_id'))
            ->orderByDesc('p.status')
            ->orderByRaw('p.sub_institute_id IS NULL ASC')
            ->orderBy('p.name')
            ->get(['p.id', 'p.name', 'p.policy_type', 'p.status', 'p.sub_institute_id', 'p.require_disclosure', 'a.scope_id'])
            ->unique('id')->values();

        $ownId = DB::table('ai_modules')->where('module_key', $ctx['module'])
            ->where(fn ($q) => $q->where('sub_institute_id', $inst)->orWhereNull('sub_institute_id'))
            ->orderByRaw('sub_institute_id IS NULL ASC')->value('id');

        // The same resolver the module chat calls before any model call (operation `ai_request`).
        $resolved = $this->policies->resolve($inst, ['module_id' => $ownId, 'operation' => 'ai_request']);
        $catalogue = $this->policies->ruleCatalogue();
        $labelOf = array_column($catalogue, 'label', 'key');

        $facts = [
            ['label' => 'Policies assigned to this module and its screens', 'value' => $assigned->count()],
            ['label' => 'Active', 'value' => $assigned->where('status', 1)->count()],
            [
                'label' => 'Governing the chat of ' . $label . ' right now',
                'value' => $resolved['policy'] === null ? 'No policy' : (string) $resolved['policy']->name,
                'detail' => $resolved['allowed']
                    ? 'The resolver permits an AI request here.'
                    : (string) $resolved['message'],
            ],
        ];

        $run = ['kind' => 'chat', 'label' => 'Ask the assistant', 'message' => 'Which AI policies apply to ' . $label . ', and what do they allow or forbid?'];

        if ($assigned->isEmpty()) {
            return [
                'status' => 'empty',
                'title' => 'No policy governs ' . $label . ' yet',
                'explanation' => 'With no policy assigned, the policy resolver permits every AI request in this module (confirmed live above). '
                    . 'A policy would decide which of these rules apply; the list is the real rule catalogue the resolver enforces.',
                'facts' => $facts + [3 => ['label' => 'Rules a policy can set', 'value' => count($catalogue)]],
                'items' => array_map(fn (array $rule) => [
                    'title' => $rule['label'],
                    'detail' => 'Catalogue default: ' . ($rule['default'] ? 'permitted' : 'not permitted') . '. Applies once a policy sets it.',
                    'meta' => $rule['key'],
                    'status' => $rule['default'] ? 'ok' : 'off',
                ], $catalogue),
                'run' => $run,
                'note' => 'Use New policy on this tab to assign one. Nothing here was created for you.',
            ];
        }

        $chosen = $assigned->first();
        $rules = Schema::hasTable('ai_policy_rules')
            ? DB::table('ai_policy_rules')->where('policy_id', $chosen->id)->get(['rule_key', 'rule_value']) : collect();
        $permitted = $rules->where('rule_value', 1)->count();
        $forbidden = $rules->count() - $permitted;

        $facts[] = ['label' => 'Policy shown', 'value' => (string) $chosen->name, 'detail' => str_replace('_', '-', (string) $chosen->policy_type) . ($chosen->status ? '' : ' (inactive)')];
        $facts[] = ['label' => 'Rules it sets', 'value' => $rules->count(), 'detail' => $permitted . ' permitted, ' . $forbidden . ' not permitted'];
        $facts[] = ['label' => 'Disclosure of AI use required', 'value' => $chosen->require_disclosure ? 'Yes' : 'No'];

        return [
            'status' => 'ready',
            'title' => 'How "' . $chosen->name . '" governs ' . $label,
            'explanation' => 'This is a policy assigned to the module. The resolver reads its type and each rule on every AI request; '
                . ((string) $chosen->policy_type === 'ai_free' ? 'an AI-Free policy refuses the request outright.' : 'a rule set to not permitted refuses just that kind of use.'),
            'facts' => $facts,
            'items' => $rules->map(fn ($rule) => [
                'title' => $labelOf[$rule->rule_key] ?? (string) $rule->rule_key,
                'detail' => $rule->rule_value ? 'Permitted by this policy' : 'Not permitted by this policy',
                'meta' => (string) $rule->rule_key,
                'status' => $rule->rule_value ? 'ok' : 'off',
            ])->values()->all(),
            'run' => $run,
            'note' => $assigned->count() > 1 ? 'Other assigned policies are listed on this tab.' : null,
        ];
    }

    // ------------------------------------------------------------------ Models

    /** @param array<string, mixed> $ctx */
    private function models(array $ctx): array
    {
        $label = $ctx['label'];
        $data = $this->internal(app(AiModuleModelController::class), 'index', $ctx, false);
        $run = ['kind' => 'chat', 'label' => 'Ask the assistant', 'message' => 'In two sentences, what data can you see in ' . $label . ' right now?'];

        if ($data === null) {
            return [
                'status' => 'attention', 'title' => 'The model setup could not be read',
                'explanation' => 'The model configuration for this module could not be resolved, so nothing is claimed about it.',
                'facts' => [], 'items' => [], 'run' => $run, 'note' => null,
            ];
        }

        $rows = $data['rows'] ?? [];
        $bound = count(array_filter($rows, fn ($r) => $r['binding'] !== null));
        $withKey = count(array_filter($rows, fn ($r) => ($r['effective']['has_credential'] ?? false) === true));
        $wired = count(array_filter($rows, fn ($r) => ($r['wired'] ?? false) === true));

        $facts = [
            ['label' => 'Capabilities this module uses', 'value' => count($rows)],
            ['label' => 'With a module-specific model chosen', 'value' => $bound, 'detail' => 'The rest inherit the estate default.'],
            ['label' => 'With a usable credential', 'value' => $withKey],
            ['label' => 'Read by the platform today', 'value' => $wired, 'detail' => 'Capabilities not marked wired store a choice that is not read yet.'],
        ];

        return [
            'status' => $withKey > 0 ? 'ready' : 'attention',
            'title' => 'Which model answers in ' . $label,
            'explanation' => 'Each row is what the next AI call in this module would actually use, resolved by the same code the call runs: '
                . 'the module binding if there is one, otherwise the estate default.'
                . ($withKey === 0 ? ' No capability currently resolves to a usable credential, so a call would be refused until one is added on this tab.' : ''),
            'facts' => $facts,
            'items' => array_map(fn (array $r) => [
                'title' => (string) $r['label'],
                'detail' => trim(((string) ($r['effective']['provider_label'] ?? $r['effective']['provider'] ?? '')) . ' / ' . ((string) ($r['effective']['model'] ?? '')), ' /')
                    ?: 'No provider resolved',
                'meta' => 'source: ' . ($r['effective']['source'] ?? 'none') . ($r['wired'] ? '' : ' - not read yet'),
                'status' => ($r['effective']['has_credential'] ?? false) ? 'ok' : 'off',
            ], $rows),
            'run' => $run,
            'note' => null,
        ];
    }

    // ------------------------------------------------------------------ Prompts

    /** @param array<string, mixed> $ctx */
    private function prompts(array $ctx): array
    {
        $inst = $ctx['inst'];
        $label = $ctx['label'];

        $rows = DB::table('ai_templates')
            ->whereIn('module_key', $ctx['keys'])
            ->where('kind', 'prompt')
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('sub_institute_id', $inst)->orWhereNull('sub_institute_id'))
            ->get(['id', 'template_key', 'name', 'module_key', 'version', 'system_prompt', 'user_prompt', 'sub_institute_id'])
            ->all();

        if ($rows === []) {
            return [
                'status' => 'empty',
                'title' => 'No published prompt for ' . $label . ' yet',
                'explanation' => 'A prompt is text sent to the model with this module\'s live data filled in. None is published for this module, '
                    . 'so there is nothing to render. Create one on this tab (it must use {{records}} or {{metrics}} to be published).',
                'facts' => [['label' => 'Published prompts', 'value' => 0]],
                'items' => [],
                'run' => ['kind' => 'none', 'label' => 'Nothing to run', 'reason' => 'No published prompt exists for this module.'],
                'note' => null,
            ];
        }

        $grounding = ['records', 'metrics'];
        usort($rows, function ($a, $b) use ($ctx, $grounding) {
            $score = fn ($r) => ($r->module_key === $ctx['module'] ? 4 : 0)
                + (preg_match('/\{\{\s*(' . implode('|', $grounding) . ')\s*\}\}/', (string) $r->user_prompt) ? 2 : 0)
                + ($r->sub_institute_id !== null ? 1 : 0);

            return $score($b) <=> $score($a);
        });
        $chosen = $rows[0];

        $values = $this->promptValues($ctx);
        $preview = $this->templates->preview((string) ($chosen->system_prompt ?? ''), (string) $chosen->user_prompt, $values['values']);

        return [
            'status' => 'ready',
            'title' => '"' . $chosen->name . '" filled with ' . $label . ' data',
            'explanation' => 'This is the real prompt as the model would receive it: its placeholders replaced with figures and records read from '
                . 'the module\'s data sources just now. People-level rows are summarised, not listed.',
            'facts' => [
                ['label' => 'Published prompts for this module', 'value' => count($rows)],
                ['label' => 'Total records behind the prompt', 'value' => $values['total'], 'detail' => $values['partial'] ? 'At least this many; the prompt carries a sample.' : null],
                ['label' => 'Records listed in the prompt', 'value' => $values['listed']],
                ['label' => 'Placeholders left unfilled', 'value' => count($preview['unresolved']), 'detail' => $preview['unresolved'] === [] ? null : implode(', ', $preview['unresolved'])],
            ],
            'items' => [[
                'title' => 'Rendered prompt',
                'detail' => mb_strimwidth((string) $preview['user'], 0, 3000, "\n..."),
                'meta' => 'template ' . $chosen->template_key . ' v' . $chosen->version,
                'status' => 'ok',
                'mono' => true,
            ]],
            'run' => [
                'kind' => 'chat',
                'label' => 'Send this prompt to the chat',
                'message' => mb_strimwidth((string) $preview['user'], 0, 7500, '...'),
            ],
            'note' => 'The chat answers from the module\'s own data too, so the answer should agree with the figures shown.',
        ];
    }

    // ------------------------------------------------------------------ Templates

    /** @param array<string, mixed> $ctx */
    private function templates(array $ctx): array
    {
        $label = $ctx['label'];
        $suggestions = array_values(array_filter(
            $this->reports->suggest($ctx['scope'], $ctx['module']),
            fn (array $s) => $s['template_id'] !== null
        ));

        if ($suggestions === []) {
            return [
                'status' => 'empty',
                'title' => 'No report template for ' . $label . ' yet',
                'explanation' => 'A report template binds a layout to one of the module\'s read-only data sources. None is published here, '
                    . 'so a report cannot be built. Create one on this tab from any source listed under Knowledge Base.',
                'facts' => [['label' => 'Published report templates', 'value' => 0], ['label' => 'Data sources available to bind', 'value' => count($ctx['sources'])]],
                'items' => [],
                'run' => ['kind' => 'none', 'label' => 'Nothing to run', 'reason' => 'No published report template exists for this module.'],
                'note' => null,
            ];
        }

        // What each report would hold: its source run with the arguments the template itself
        // declares, capped at the report builder's own default of 200 rows.
        $holds = [];
        foreach ($suggestions as $s) {
            $holds[$s['template_id']] = $this->reportRows($ctx, (int) $s['template_id'], (string) $s['data_source']);
        }
        $count = fn (array $s) => $holds[$s['template_id']] ?? 0;
        $chosen = collect($suggestions)->first(fn ($s) => $count($s) > 0) ?? $suggestions[0];
        $rows = $count($chosen);
        $truncated = false;

        return [
            'status' => $rows > 0 ? 'ready' : 'attention',
            'title' => 'Build "' . $chosen['template_name'] . '" from live records',
            'explanation' => 'The report is not written by a model: the template\'s layout is filled with the rows its data source returns for your organisation now'
                . ($rows > 0 ? '. Press the button to build and save it, then open it.' : ', and this source currently has no rows, so the build would decline instead of producing an empty report.'),
            'facts' => [
                ['label' => 'Published report templates', 'value' => count($suggestions)],
                ['label' => 'Bound data source', 'value' => $chosen['data_source']],
                ['label' => 'Rows it would report', 'value' => $rows, 'detail' => $truncated ? 'At least; the source is read up to ' . self::READ_LIMIT . ' rows.' : null],
            ],
            'items' => array_map(fn (array $s) => [
                'title' => (string) $s['template_name'],
                'detail' => (string) $s['label'],
                'meta' => $s['data_source'] . ' - ' . $count($s) . ' row' . ($count($s) === 1 ? '' : 's'),
                'status' => $count($s) > 0 ? 'ok' : 'off',
            ], $suggestions),
            // The saved-report page and its API are for organisation administrators (the same rule
            // ChatReportService applies), so the button is offered only to one.
            'run' => ($ctx['scope']->isAdmin || $ctx['scope']->isPlatformOwner) ? [
                'kind' => 'report',
                'label' => 'Generate this report',
                'module_key' => $ctx['module'],
                'message' => (string) $chosen['prompt'],
                'template_id' => $chosen['template_id'],
                'data_source' => $chosen['data_source'],
                'expected_rows' => $rows,
            ] : [
                'kind' => 'none',
                'label' => 'Administrators only',
                'reason' => 'Saved reports can be built only by an organisation administrator, and your account is not flagged as one.',
            ],
            'note' => $rows > 0 ? null : 'Add records in the module (or choose another template) and run it again.',
        ];
    }

    // ------------------------------------------------------------------ Knowledge Base

    /** @param array<string, mixed> $ctx */
    private function knowledgeBase(array $ctx): array
    {
        $label = $ctx['label'];
        $reads = $this->readSources($ctx);

        if ($reads === []) {
            return [
                'status' => 'empty',
                'title' => $label . ' has no data source for the AI to read',
                'explanation' => 'No read-only data source is registered for this module, so the assistant has no module records to ground an answer on.',
                'facts' => [['label' => 'Data sources', 'value' => 0]],
                'items' => [],
                'run' => ['kind' => 'none', 'label' => 'Nothing to ask', 'reason' => 'No data source is registered for this module.'],
                'note' => null,
            ];
        }

        $total = array_sum(array_column($reads, 'total'));
        $withRows = array_filter($reads, fn ($r) => $r['total'] > 0);
        usort($withRows, fn ($a, $b) => $b['total'] <=> $a['total']);
        $top = $withRows[0] ?? null;

        $question = null;

        if ($top !== null) {
            $noun = $this->facets->noun((string) $top['label']);
            $facet = $this->facets->compute($top['rows'])[0] ?? null;
            $question = $facet !== null
                ? "How many {$noun} are there, and what is the breakdown by {$facet['label']}?"
                : "How many {$noun} are there?";
        }

        return [
            'status' => $top === null ? 'empty' : 'ready',
            'title' => 'What the ' . $label . ' assistant can read',
            'explanation' => 'These are the read-only sources the assistant is grounded on, counted from the database just now for your organisation. '
                . ($top === null ? 'All of them are currently empty, so an answer would have nothing to rest on.' : 'Ask the question below and compare its answer with the count.'),
            'facts' => [
                ['label' => 'Data sources', 'value' => count($reads)],
                ['label' => 'Rows readable in total', 'value' => $total, 'detail' => array_filter(array_column($reads, 'truncated')) === [] ? null : 'Counted up to ' . self::READ_LIMIT . ' rows per source.'],
                ['label' => 'Sources with rows', 'value' => count($withRows)],
            ],
            'items' => array_map(fn (array $r) => [
                'title' => (string) $r['label'],
                'detail' => $r['error'] ?? ($r['total'] . ($r['truncated'] ? '+' : '') . ' row' . ($r['total'] === 1 ? '' : 's')),
                'meta' => (string) $r['name'] . ($r['total'] > 0 && $this->grounding->isPersonal(array_keys($r['rows'][0])) ? ' - one row per person: only counts are shared' : ''),
                'status' => $r['error'] !== null ? 'off' : ($r['total'] > 0 ? 'ok' : 'off'),
            ], array_values($reads)),
            'run' => $question === null
                ? ['kind' => 'none', 'label' => 'Nothing to ask', 'reason' => 'Every source is empty for this organisation.']
                : ['kind' => 'chat', 'label' => 'Ask about this', 'message' => $question],
            'note' => null,
        ];
    }

    // ------------------------------------------------------------------ Automations

    /** @param array<string, mixed> $ctx */
    private function automations(array $ctx): array
    {
        $inst = $ctx['inst'];
        $label = $ctx['label'];

        $agents = DB::table('agentic_agents')
            ->where('sub_institute_id', $inst)
            ->whereNull('deleted_at')
            ->whereIn('sub_module', $ctx['keys'])
            ->orderByRaw("status = 'deployed' DESC")
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'tools', 'sub_module']);

        $pending = $this->pendingApprovals($ctx);
        $approvalNote = 'Writes never run from an automation: anything the assistant proposes that changes data goes into the approval ledger first ('
            . $pending . ' waiting for this module now).';

        if ($agents->isNotEmpty()) {
            $chosen = $agents->first();
            $tools = json_decode((string) $chosen->tools, true);
            $tools = is_array($tools) ? $tools : [];
            $runs = DB::table('agentic_agent_runs')->where('agent_id', $chosen->id)->where('sub_institute_id', $inst);
            $runCount = (clone $runs)->count();
            $last = (clone $runs)->orderByDesc('id')->first(['status', 'started_at']);
            $active = (string) $chosen->status === 'deployed' && $tools !== [];

            return [
                'status' => $active ? 'ready' : 'attention',
                'title' => 'Run "' . $chosen->name . '" on live data',
                'explanation' => 'This tool agent is a real saved automation. It reads one allow-listed source of the module and logs the run; it needs no model credential and cannot write.',
                'facts' => [
                    ['label' => 'Agents for this module', 'value' => $agents->count()],
                    ['label' => 'Active', 'value' => $agents->where('status', 'deployed')->count()],
                    ['label' => 'Tools allowed', 'value' => $tools === [] ? 'none' : implode(', ', $tools)],
                    ['label' => 'Runs recorded', 'value' => $runCount, 'detail' => $last ? 'Last: ' . $last->status . ' at ' . $last->started_at : null],
                ],
                'items' => $agents->take(5)->map(fn ($a) => [
                    'title' => (string) $a->name,
                    'detail' => (string) $a->status === 'deployed' ? 'Active' : ucfirst((string) $a->status),
                    'meta' => (string) $a->sub_module,
                    'status' => (string) $a->status === 'deployed' ? 'ok' : 'off',
                ])->values()->all(),
                'run' => $active
                    ? ['kind' => 'agent', 'label' => 'Run this agent', 'agent_id' => (string) $chosen->id, 'tool' => $tools[0]]
                    : ['kind' => 'none', 'label' => 'Not runnable', 'reason' => 'The agent is not active or has no tool; activate it on this tab.'],
                'note' => $approvalNote,
            ];
        }

        // No agent yet: offer one derived from the module's best real read source. Creating it is
        // an explicit user action (the button), never done here.
        $reads = $this->readSources($ctx);
        $pick = collect($reads)->sortByDesc('total')->first(fn ($r) => $r['error'] === null);

        if ($pick === null) {
            return [
                'status' => 'empty',
                'title' => $label . ' has no read tool to automate',
                'explanation' => 'An automation is a saved agent over a module\'s read-only data sources. This module has none registered.',
                'facts' => [['label' => 'Agents for this module', 'value' => 0]],
                'items' => [],
                'run' => ['kind' => 'none', 'label' => 'Nothing to create', 'reason' => 'No read-only source exists for this module.'],
                'note' => $approvalNote,
            ];
        }

        return [
            'status' => 'empty',
            'title' => 'Create and run a ' . $label . ' automation',
            'explanation' => 'No agent exists yet for this module. The button creates one over "' . $pick['label'] . '" (a real read-only source with '
                . $pick['total'] . ($pick['truncated'] ? '+' : '') . ' row' . ($pick['total'] === 1 ? '' : 's')
                . ' now) in your organisation, then runs it once so you can read the logged result.',
            'facts' => [
                ['label' => 'Agents for this module', 'value' => 0],
                ['label' => 'Tool it would be allowed', 'value' => $pick['name']],
                ['label' => 'Rows it would read now', 'value' => $pick['total']],
            ],
            'items' => [],
            'run' => [
                'kind' => 'agent_create',
                'label' => 'Create and run this agent',
                'tool' => $pick['name'],
                'create' => [
                    'name' => $label . ' - ' . $pick['label'] . ' snapshot',
                    'description' => 'Reads ' . $pick['label'] . ' (' . $pick['name'] . ') and logs what it found. Read-only.',
                    'module' => $ctx['module'],
                    'tools_allowed' => [$pick['name']],
                    'instructions' => 'Read the ' . $pick['label'] . ' source and report the row count. Do not change anything.',
                    'status' => 'active',
                ],
            ],
            'note' => $approvalNote,
        ];
    }

    // ------------------------------------------------------------------ Usage & Cost

    /** @param array<string, mixed> $ctx */
    private function usageCost(array $ctx): array
    {
        $label = $ctx['label'];
        $data = $this->internal(app(AiModuleController::class), 'usage', $ctx, true);
        $run = ['kind' => 'chat', 'label' => 'Make a request now', 'message' => 'Give me a one-paragraph overview of ' . $label . ': what data you can see and how much of it.'];

        if ($data === null) {
            return [
                'status' => 'attention', 'title' => 'Usage could not be read',
                'explanation' => 'The usage figures for this module could not be computed, so none are claimed.',
                'facts' => [], 'items' => [], 'run' => $run, 'note' => null,
            ];
        }

        $conv = $data['conversations'] ?? ['available' => false];
        $gen = $data['generation'] ?? ['available' => false];
        $facts = [];

        if (($conv['available'] ?? false) === true) {
            $facts[] = ['label' => 'Chat conversations', 'value' => $conv['total']];
            $facts[] = ['label' => 'Turns recorded', 'value' => $conv['turns_recorded_on_conversation']];
            $facts[] = ['label' => 'People who used it', 'value' => $conv['distinct_users']];
            $facts[] = ['label' => 'Last activity', 'value' => $conv['last_activity'] ?? 'none yet'];
            $avg = $conv['turn_detail']['avg_duration_ms'] ?? null;
            $facts[] = ['label' => 'Average answer time (ms)', 'value' => $avg ?? 'not measured'];
        }

        if (($gen['available'] ?? false) === true) {
            $tokens = $gen['tokens'] ?? [];
            $facts[] = ['label' => 'Metered model calls', 'value' => $gen['total']];
            $facts[] = ['label' => 'Tokens in / out', 'value' => ($tokens['prompt_tokens'] ?? null) === null ? 'not recorded' : ($tokens['prompt_tokens'] . ' / ' . ($tokens['completion_tokens'] ?? 0))];
            $facts[] = [
                'label' => 'Cost (USD)',
                'value' => ($tokens['cost'] ?? null) === null ? 'not available' : $tokens['cost'],
                'detail' => ($tokens['cost'] ?? null) === null ? ($tokens['cost_reason'] ?? null) : ('source: ' . ($tokens['cost_source'] ?? '')),
            ];
        }

        $userRows = Schema::hasTable('ai_conversations') ? DB::table('ai_conversations as c')
            ->leftJoin('tbluser as u', 'u.id', '=', 'c.user_id')
            ->where('c.sub_institute_id', $ctx['inst'])
            ->whereIn('c.module_key', $ctx['keys'])
            ->groupBy('c.user_id', 'u.first_name', 'u.last_name')
            ->orderByRaw('count(*) desc')
            ->limit(5)
            ->selectRaw("c.user_id, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as who, count(*) n, coalesce(sum(c.turn_count),0) turns")
            ->get() : collect();

        $used = ($conv['available'] ?? false) && (($conv['total'] ?? 0) > 0 || ($gen['total'] ?? 0) > 0);

        return [
            'status' => $used ? 'ready' : 'empty',
            'title' => $used ? 'What ' . $label . '\'s AI has used' : 'No AI use recorded for ' . $label . ' yet',
            'explanation' => $used
                ? 'Totals are aggregated from the conversation ledger and the metered-call log for this module (and its screens). A cost is shown only when the log or a configured price supports it.'
                : 'Nothing has been asked in this module yet, so every total is genuinely zero. Make a request with the button and these figures change.',
            'facts' => $facts,
            'items' => $userRows->map(fn ($r) => [
                'title' => $r->who !== '' ? $r->who : ($r->user_id === null ? 'Unknown user' : 'User #' . $r->user_id),
                'detail' => $r->n . ' conversation' . ($r->n == 1 ? '' : 's') . ', ' . $r->turns . ' turn' . ($r->turns == 1 ? '' : 's'),
                'meta' => 'who used it',
                'status' => 'ok',
            ])->values()->all(),
            'run' => $run,
            'note' => null,
        ];
    }

    // ------------------------------------------------------------------ Guardrails

    /** @param array<string, mixed> $ctx */
    private function guardrails(array $ctx): array
    {
        $label = $ctx['label'];
        $definition = $ctx['definition'];
        $action = $definition['action'] ?? null;
        $data = $this->internal(app(AiModuleController::class), 'guardrails', $ctx, true);

        $facts = [];

        if ($data !== null) {
            $review = $data['review'] ?? [];
            if (($review['available'] ?? false) === true) {
                $facts[] = ['label' => 'Templates requiring human review', 'value' => $review['requires_review'], 'detail' => 'of ' . $review['templates'] . ' templates'];
            }
            $refused = array_sum(array_column($data['refusal_counts'] ?? [], 'count'));
            $facts[] = ['label' => 'Metered calls refused or failed', 'value' => $refused];
        }

        $resolved = $this->policies->resolve($ctx['inst'], [
            'module_id' => DB::table('ai_modules')->where('module_key', $ctx['module'])
                ->where(fn ($q) => $q->where('sub_institute_id', $ctx['inst'])->orWhereNull('sub_institute_id'))
                ->orderByRaw('sub_institute_id IS NULL ASC')->value('id'),
            'operation' => 'ai_request',
        ]);
        $facts[] = ['label' => 'Policy gate on AI requests', 'value' => $resolved['allowed'] ? 'Permitted' : 'Refused', 'detail' => $resolved['policy'] ? (string) $resolved['policy']->name : 'no policy assigned'];

        if ($action === null) {
            return [
                'status' => 'empty',
                'title' => $label . ' has no chat write action to guard',
                'explanation' => 'No write action the assistant can propose is registered for this module, so there is no enforcement chain to demonstrate.',
                'facts' => $facts, 'items' => [],
                'run' => ['kind' => 'none', 'label' => 'Nothing to check', 'reason' => 'No example action for this module.'],
                'note' => null,
            ];
        }

        $chain = $this->guardrails->describe($action);
        $facts[] = ['label' => 'Example action', 'value' => $action['label'], 'detail' => $chain['route']];

        $items = [];
        foreach ($chain['gates'] as $gate) {
            $items[] = ['title' => $gate['gate'], 'detail' => $gate['meaning'], 'meta' => 'route gate (middleware on ' . $chain['route'] . ')', 'status' => 'ok'];
        }
        if ($chain['found'] && $chain['gates'] === []) {
            $items[] = [
                'title' => 'No route-level right gate',
                'detail' => $action['self_service'] ?? 'The route declares no right of its own; the controller scopes the action to the signed-in user.',
                'meta' => $chain['route'],
                'status' => 'ok',
            ];
        }
        $items[] = ['title' => 'Approval ledger', 'detail' => 'The assistant never executes the action itself: it files an approval request, an administrator decides, and the signed-in user\'s own browser then performs it with their own token.', 'meta' => 'ai_action_requests', 'status' => 'ok'];

        return [
            'status' => $chain['found'] ? 'ready' : 'attention',
            'title' => 'Can you ' . strtolower((string) $action['label']) . '? Check it live',
            'explanation' => 'The check runs the real gates of the route behind this action - the same ones the server applies - for your own account, '
                . 'then records the result in the Activity ledger as allowed or blocked.',
            'facts' => $facts,
            'items' => $items,
            'run' => $chain['found']
                ? ['kind' => 'guardrail', 'label' => 'Check my access']
                : ['kind' => 'none', 'label' => 'Unavailable', 'reason' => 'The route for this action is not registered on this server.'],
            'note' => null,
        ];
    }

    // ------------------------------------------------------------------ Activity

    /** @param array<string, mixed> $ctx */
    private function activity(array $ctx): array
    {
        $label = $ctx['label'];
        $data = $this->internal(app(AiModuleController::class), 'activity', $ctx, true, ['limit' => 5]);
        $run = ['kind' => 'guardrail', 'label' => 'Run a check and watch it land here'];

        if ($data === null || ($data['available'] ?? false) !== true) {
            return [
                'status' => 'attention', 'title' => 'The ledger could not be read',
                'explanation' => $data['reason'] ?? 'The activity ledger could not be read, so nothing is claimed about it.',
                'facts' => [], 'items' => [], 'run' => $run, 'note' => null,
            ];
        }

        $entries = $data['entries'] ?? [];
        $explain = function (array $e): string {
            $op = (string) ($e['operation'] ?? '');
            $how = ($e['outcome'] ?? '') === 'success' ? 'it was allowed or completed' : 'it was refused or failed';

            return match (true) {
                $op === 'guardrail_check' => 'A rights check run from the Guardrails example: ' . $how . '.',
                str_starts_with($op, 'chat') => 'An action taken through the assistant chat: ' . $how . '.',
                default => 'An AI operation recorded by this module: ' . $how . '.',
            };
        };

        return [
            'status' => $entries === [] ? 'empty' : 'ready',
            'title' => $entries === [] ? 'No AI activity recorded for ' . $label . ' yet' : 'The latest AI activity in ' . $label,
            'explanation' => $entries === []
                ? 'The ledger is empty for your organisation. Every guarded or assisted operation writes a row here; run the check to create the first real one.'
                : 'These are the newest rows of the ledger, newest first, with the person, the outcome and what each row shows.',
            'facts' => array_filter([
                ['label' => 'Ledger rows for this module', 'value' => (int) ($data['total'] ?? 0)],
                ...array_map(fn ($b) => ['label' => $b['operation'] . ' (' . $b['outcome'] . ')', 'value' => $b['count']], array_slice($data['by_operation'] ?? [], 0, 3)),
            ]),
            'items' => array_map(fn (array $e) => [
                'title' => (string) ($e['operation_label'] ?? $e['operation']),
                'detail' => $explain($e),
                'meta' => trim(((string) ($e['actor_label'] ?? 'system')) . ' - ' . $e['created_at']),
                'status' => ($e['outcome'] ?? '') === 'success' ? 'ok' : 'off',
            ], $entries),
            'run' => $run,
            'note' => null,
        ];
    }

    // ------------------------------------------------------------------ Approvals

    /** @param array<string, mixed> $ctx */
    private function approvals(array $ctx): array
    {
        $label = $ctx['label'];
        $base = DB::table('ai_action_requests')->where('ai_action_requests.sub_institute_id', $ctx['inst'])->whereIn('ai_action_requests.module_key', $ctx['keys']);
        $byStatus = (clone $base)->selectRaw('ai_action_requests.status as status, count(*) c')->groupBy('ai_action_requests.status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all();
        $total = array_sum($byStatus);

        $latest = (clone $base)
            ->leftJoin('tbluser as u', 'u.id', '=', 'ai_action_requests.requested_by')
            ->orderByDesc('ai_action_requests.id')->limit(5)
            ->get(['ai_action_requests.id', 'ai_action_requests.action_key', 'ai_action_requests.status', 'ai_action_requests.created_at', 'ai_action_requests.preview',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as who")]);

        $facts = [['label' => 'Requests in the ledger', 'value' => $total]];
        foreach ($byStatus as $status => $n) {
            $facts[] = ['label' => ucfirst((string) $status), 'value' => $n];
        }

        return [
            'status' => $total === 0 ? 'empty' : 'ready',
            'title' => $total === 0 ? 'No action has been proposed in ' . $label . ' yet' : 'The latest actions proposed in ' . $label,
            'explanation' => $total === 0
                ? 'When the assistant proposes a change (for example from the chat on a ' . $label . ' page) it is recorded here, an administrator approves or rejects it, '
                    . 'and only then is it carried out. Nothing is waiting, and no request has been invented to fill this list.'
                : 'Each row is a change the assistant proposed, who asked for it, and where it stands: pending, approved, rejected, completed or failed.',
            'facts' => $facts,
            'items' => $latest->map(fn ($r) => [
                'title' => str_replace('_', ' ', (string) $r->action_key),
                'detail' => (string) $r->status,
                'meta' => trim(($r->who !== '' ? $r->who : 'unknown requester') . ' - ' . $r->created_at),
                'status' => in_array($r->status, ['completed', 'approved'], true) ? 'ok' : ($r->status === 'pending' ? 'pending' : 'off'),
            ])->values()->all(),
            'run' => ['kind' => 'chat', 'label' => 'Ask what needs approval', 'message' => 'Which actions can you take in ' . $label . ', and which of them need an administrator\'s approval first?'],
            'note' => null,
        ];
    }

    // ------------------------------------------------------------------ shared

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, array<string, mixed>> Source name => meta + rows/total/truncated/error.
     */
    private function readSources(array $ctx): array
    {
        $cacheKey = $ctx['inst'] . '|' . $ctx['module'];

        if (isset($this->reads[$cacheKey])) {
            return $this->reads[$cacheKey];
        }

        $out = [];

        foreach ($ctx['sources'] as $source) {
            try {
                $result = $this->sources->run($source['name'], $ctx['scope'], ['limit' => self::READ_LIMIT]);
                $out[$source['name']] = $source + ['rows' => $result['rows'], 'total' => $result['total'], 'truncated' => $result['truncated'], 'error' => null];
            } catch (Throwable) {
                $out[$source['name']] = $source + ['rows' => [], 'total' => 0, 'truncated' => false, 'error' => 'This source could not be read.'];
            }
        }

        return $this->reads[$cacheKey] = $out;
    }

    /**
     * How many rows a saved report template would put in a report today.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function reportRows(array $ctx, int $templateId, string $source): int
    {
        $raw = DB::table('ai_templates')->where('id', $templateId)->value('data_arguments');
        $args = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $declared = array_column($this->sources->describe($source)['arguments'] ?? [], 'key');
        $args = array_intersect_key($args, array_flip($declared));
        $limit = max(1, min(self::READ_LIMIT, (int) ($args['limit'] ?? 200)));
        $args['limit'] = $limit;

        try {
            return (int) $this->sources->run($source, $ctx['scope'], $args)['total'];
        } catch (Throwable) {
            return 0;
        }
    }
    /**
     * Placeholder values for a prompt, computed from the module's sources.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{values:array<string,string>, total:int, listed:int, partial:bool}
     */
    private function promptValues(array $ctx): array
    {
        $metrics = [];
        $records = [];
        $total = 0;
        $listed = 0;
        $partial = false;

        foreach ($this->readSources($ctx) as $read) {
            if ($read['error'] !== null) {
                continue;
            }

            $total += $read['total'];
            $partial = $partial || $read['truncated'];
            $metrics[] = $read['label'] . ': ' . $read['total'] . ($read['truncated'] ? '+' : '');

            foreach ($this->facets->compute($read['rows']) as $facet) {
                $metrics[] = '  ' . $read['label'] . ' by ' . $facet['label'] . ': '
                    . implode(', ', array_map(fn ($v) => $v['value'] . ' ' . $v['count'], $facet['values']));
            }

            if ($read['rows'] === []) {
                continue;
            }

            if ($this->grounding->isPersonal(array_keys($read['rows'][0]))) {
                $partial = true;
                $records[] = '- ' . $read['label'] . ': ' . $read['total'] . ' rows, one per person; individual rows are not listed.';

                continue;
            }

            foreach (array_slice($read['rows'], 0, 5) as $row) {
                $pairs = [];
                foreach (array_slice($row, 0, 6, true) as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $pairs[] = $k . ': ' . $v;
                    }
                }
                $records[] = '- ' . $read['label'] . ' (' . implode(', ', $pairs) . ')';
                $listed++;
            }

            if ($read['total'] > 5) {
                $partial = true;
            }
        }

        $recordText = mb_strimwidth(implode("\n", $records), 0, self::RECORD_CHARS, "\n...");

        return [
            'total' => $total,
            'listed' => $listed,
            'partial' => $partial,
            'values' => [
                'module' => $ctx['label'],
                'page_title' => $ctx['label'] . ' AI Stack',
                'page_type' => 'module',
                'filters' => 'none',
                'search_query' => '',
                'entity_label' => '',
                'data_source' => 'the module',
                'metrics' => $metrics === [] ? 'none reported' : implode("\n", $metrics),
                'records' => $recordText === '' ? 'none listed' : $recordText,
                'record_count' => (string) $total,
                'rows_shown' => (string) $listed,
                'is_partial' => $partial ? 'yes' : 'no',
            ],
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function pendingApprovals(array $ctx): int
    {
        return (int) DB::table('ai_action_requests')
            ->where('sub_institute_id', $ctx['inst'])
            ->whereIn('module_key', $ctx['keys'])
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Call one of the module controllers as the tabs do, and return its `data`.
     *
     * Reusing the controller keeps this example's numbers identical to the tab's own.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function internal(object $controller, string $method, array $ctx, bool $rollupAware, array $query = []): ?array
    {
        try {
            $sub = $ctx['request']->duplicate($query + ($rollupAware && $ctx['rollup'] ? ['rollup' => '1'] : []));
            $response = $controller->{$method}($sub, $ctx['module']);
            $json = json_decode((string) $response->getContent(), true);

            return is_array($json) && ($json['success'] ?? false) ? ($json['data'] ?? null) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The G2G definition for a module, or for the top-level module a screen sits under.
     *
     * @return array<string, mixed>|null
     */
    public function definitionFor(string $module): ?array
    {
        foreach (G2gModuleExamples::definitions() as $key => $definition) {
            if ($key === $module || in_array($module, $this->rollUp->keysFor($key), true)) {
                return $definition;
            }
        }

        return null;
    }
}
