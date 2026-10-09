<?php

namespace App\Domain\AI\Workspace;

use App\Domain\AI\Examples\ModuleExampleBuilder;
use App\Domain\AI\Examples\PageExampleBuilder;
use App\Services\Ai\AiRequestScope;
use Throwable;

/**
 * What the chat knows when the user is on one tab of a module's AI Stack.
 *
 * The AI Stack tabs are one shared set for every module (the frontend's `buildAiStackScreens`),
 * so the tab ids below are that UI's own vocabulary, not a module list. Everything about a tab
 * that is DATA - its facts, the items on it, the questions worth asking - is read live from the
 * same descriptors the tab's Example panel shows (`ModuleExampleBuilder`), so a suggestion names
 * a real prompt, template or source of this module in this organisation, and the answer can be
 * grounded in exactly what the tab shows.
 */
final class AiStackTabContext
{
    /** @var array<string, string> tab id => the label the tab strip shows */
    public const TABS = [
        'policies' => 'Policies',
        'models' => 'Models',
        'prompts' => 'Prompts',
        'templates' => 'Templates',
        'knowledge-base' => 'Knowledge Base',
        'automations' => 'Automations',
        'usage-cost' => 'Usage & Cost',
        'guardrails' => 'Guardrails',
        'activity' => 'Activity',
        'approvals' => 'Approvals',
        'examples' => 'Examples by page',
    ];

    private const MAX_QUESTIONS = 6;

    private const FACT_CHARS = 3000;

    public function __construct(
        private readonly ModuleExampleBuilder $examples,
        private readonly PageExampleBuilder $pageExamples,
    ) {
    }

    /** The tab id when it is one of the AI Stack's tabs, else null (never trusted as sent). */
    public function valid(?string $tab): ?string
    {
        return $tab !== null && isset(self::TABS[$tab]) ? $tab : null;
    }

    public function label(string $tab): string
    {
        return self::TABS[$tab] ?? $tab;
    }

    /**
     * Questions for one tab of one module, built from that tab's live descriptor.
     *
     * @return array<int, string>
     */
    public function questions(AiRequestScope $scope, string $module, string $moduleLabel, string $tab): array
    {
        $descriptor = $this->descriptor($scope, $module, $tab);
        $items = array_values(array_filter(array_map(fn ($item) => trim((string) ($item['title'] ?? '')), (array) ($descriptor['items'] ?? []))));
        $runMessage = ($descriptor['run']['kind'] ?? null) === 'chat' ? (string) ($descriptor['run']['message'] ?? '') : '';
        $label = $moduleLabel;
        $out = [];

        $add = function (string $question) use (&$out): void {
            if ($question !== '' && count($out) < self::MAX_QUESTIONS && ! in_array($question, $out, true)) {
                $out[] = $question;
            }
        };

        switch ($tab) {
            case 'policies':
                $add($runMessage);
                $add("Does any policy stop AI being used in {$label}?");
                break;
            case 'models':
                $add("Which AI model and provider does {$label} use, and where does that setting come from?");
                $add($runMessage);
                break;
            case 'prompts':
                foreach (array_slice($items, 0, 2) as $title) {
                    $add("What does the \"{$title}\" prompt do, and what data does it use?");
                }
                $add("Which prompts are available for {$label}?");
                break;
            case 'templates':
                foreach (array_slice($items, 0, 2) as $title) {
                    $add("What does the \"{$title}\" report show?");
                }
                $add("Which reports can I generate in {$label}?");
                break;
            case 'knowledge-base':
                foreach (array_slice($items, 0, 2) as $title) {
                    $add("How many {$title} records are there?");
                }
                $add($runMessage);
                break;
            case 'automations':
                $add("Which automations are set up for {$label}, and what can they read?");
                $add("Do any {$label} automations need approval before they write anything?");
                break;
            case 'usage-cost':
                $add("How much has AI been used in {$label}, and by whom?");
                $add($runMessage);
                break;
            case 'guardrails':
                $add("What stops someone changing {$label} data through the assistant?");
                $add("Which checks apply before an assistant action runs in {$label}?");
                break;
            case 'activity':
                $add("What AI activity happened recently in {$label}?");
                $add("Were any AI requests in {$label} refused or blocked?");
                break;
            case 'approvals':
                $add("Which assistant actions in {$label} are waiting for approval?");
                $add($runMessage);
                break;
            case 'examples':
                $add("Which pages of {$label} have live data behind them?");
                $add("Which {$label} pages have no data yet, and why?");
                break;
        }

        // A module with nothing on this tab (no data source, no template) still has a tab to ask about.
        if ($out === []) {
            $add("What can the assistant see in {$label}, and what does the {$this->label($tab)} tab show for it?");
        }

        return $out;
    }

    /**
     * What the tab is showing, as grounding for the model: its title, what it demonstrates, its
     * live facts and its first items. Null when the tab or module is unknown.
     */
    public function facts(AiRequestScope $scope, string $module, string $moduleLabel, string $tab): ?string
    {
        if ($tab === 'examples') {
            return $this->pageCoverageFacts($scope, $module, $moduleLabel);
        }

        $descriptor = $this->descriptor($scope, $module, $tab);

        if ($descriptor === null) {
            return null;
        }

        $lines = [
            sprintf('THE USER IS ON THE %s TAB OF THE %s AI STACK. This is what the tab shows right now:', mb_strtoupper($this->label($tab)), $moduleLabel),
            trim((string) ($descriptor['title'] ?? '')) . ' - ' . trim((string) ($descriptor['explanation'] ?? '')),
        ];

        foreach ((array) ($descriptor['facts'] ?? []) as $fact) {
            $lines[] = sprintf('- %s: %s%s', $fact['label'] ?? '', is_scalar($fact['value'] ?? null) ? $fact['value'] : json_encode($fact['value'] ?? null), isset($fact['detail']) ? ' (' . $fact['detail'] . ')' : '');
        }

        foreach (array_slice((array) ($descriptor['items'] ?? []), 0, 8) as $item) {
            $lines[] = sprintf('* %s%s', $item['title'] ?? '', isset($item['detail']) ? ': ' . $item['detail'] : '');
        }

        return mb_strimwidth(implode("\n", $lines), 0, self::FACT_CHARS, '...');
    }

    /** What the Examples-by-page tab shows: each page, whether real data stands behind it, and how much. */
    private function pageCoverageFacts(AiRequestScope $scope, string $module, string $moduleLabel): ?string
    {
        try {
            $built = $this->pageExamples->forModule($scope, $module);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($built === null) {
            return null;
        }

        $c = $built['coverage'];
        $lines = [
            sprintf('THE USER IS ON THE EXAMPLES BY PAGE TAB OF THE %s AI STACK. It shows one example per page, from that page\'s own data.', $moduleLabel),
            sprintf('- Pages: %d; with live data: %d; no records yet: %d; no data behind them: %d; not mapped: %d', $c['pages'], $c['ready'], $c['empty'], $c['no_data'], $c['unmapped']),
        ];

        foreach (array_slice($built['pages'], 0, 40) as $page) {
            $sources = implode(', ', array_map(fn ($s) => sprintf('%s %d', $s['label'], $s['rows']), $page['sources']));
            $lines[] = sprintf('* %s [%s]%s', $page['page']['title'], $page['status'], $sources === '' ? '' : ': ' . $sources);
        }

        return mb_strimwidth(implode("\n", $lines), 0, self::FACT_CHARS, '...');
    }

    /** @return array<string, mixed>|null */
    private function descriptor(AiRequestScope $scope, string $module, string $tab): ?array
    {
        if ($this->valid($tab) === null) {
            return null;
        }

        try {
            $built = $this->examples->build(request(), $scope, $module, true, $tab);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $built['tabs'][$tab] ?? null;
    }
}
