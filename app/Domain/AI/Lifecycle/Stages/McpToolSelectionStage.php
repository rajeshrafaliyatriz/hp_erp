<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Domain\AI\Lifecycle\Text;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;

/**
 * Stage 5 - which of the module's read-only data sources answer this question?
 *
 * The candidates are ONLY the sources the catalogue lists for the active module (and the screens
 * beneath it) - read from the catalogue, never a list kept here - so a question asked in one
 * module cannot select another module's data. Words in the question are scored against each
 * source's name, label and description; the best few are chosen. When nothing matches, the
 * sources of the page the user has open are used, so a vague question still lands on real data
 * for the screen in front of them.
 */
final class McpToolSelectionStage implements LifecycleStage
{
    private const MAX_SOURCES = 3;

    public function __construct(private readonly ModuleDataSourceCatalog $sources, private readonly ModuleGrounding $grounding)
    {
    }

    public function key(): StageKey
    {
        return StageKey::McpToolSelection;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->intent === 'action') {
            return StageResult::skipped('An action needs no data tool.');
        }

        if ($context->module === null) {
            return StageResult::skipped('Data tools belong to a module; open a module to use them.');
        }

        $candidates = $this->sources->forModule((string) $context->module->module_key);

        if ($candidates === []) {
            return StageResult::skipped('This module has no data sources registered.');
        }

        $words = Text::tokens($context->message);
        $scored = [];

        foreach ($candidates as $source) {
            $haystack = Text::normalise($source['name'] . ' ' . $source['label'] . ' ' . $source['description']);
            $score = 0;

            foreach ($words as $word) {
                if (str_contains(' ' . $haystack . ' ', ' ' . $word) || str_contains($haystack, $word)) {
                    $score++;
                }
            }

            if ($score > 0) {
                $scored[] = ['source' => $source, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $chosen = array_map(fn ($row) => $row['source'], array_slice($scored, 0, self::MAX_SOURCES));
        $basis = 'matched your question';

        if ($chosen === []) {
            $focus = $context->page['module_key'] ?? null;
            $chosen = array_slice(array_values(array_filter($candidates, fn ($s) => $focus !== null && $s['module'] === $focus)), 0, 2);
            $basis = $chosen === [] ? '' : 'the sources of the page you are on';
        }

        if ($chosen === []) {
            return StageResult::skipped('No data source of this module matches the question.');
        }

        $context->selectedSources = $chosen;

        return StageResult::ran(
            sprintf('Selected %d data source%s (%s).', count($chosen), count($chosen) === 1 ? '' : 's', $basis),
            ['sources' => array_map(fn ($s) => ['name' => $s['name'], 'label' => $s['label']], $chosen)]
        );
    }
}
