<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;

/**
 * Stage 8 - turn what was read into citable evidence.
 *
 * Every figure the answer may state is traceable to an evidence entry (E1, E2 ...) naming its
 * source, how many rows it covers and when it was read. Where a source is one row per person the
 * entry carries the columns and the count but NO rows - the same privacy rule the module
 * briefing applies - so the assistant cannot name individuals.
 */
final class EvidenceStage implements LifecycleStage
{
    private const SAMPLE_ROWS = 8;

    public function key(): StageKey
    {
        return StageKey::Evidence;
    }

    public function run(LifecycleContext $context): StageResult
    {
        $n = 0;

        foreach ($context->results as $name => $result) {
            if ($result['error'] !== null) {
                continue;
            }

            $n++;
            $columns = $result['rows'] === [] ? [] : array_keys($result['rows'][0]);

            $context->evidence[] = [
                'id' => 'E' . $n,
                'source' => $name,
                'label' => (string) $result['source']['label'],
                'module' => (string) $result['source']['module'],
                'total' => $result['total'],
                'truncated' => $result['truncated'],
                'columns' => $columns,
                'personal' => $result['personal'],
                'rows' => $result['personal'] ? [] : array_slice($result['rows'], 0, self::SAMPLE_ROWS),
                'as_of' => now()->toIso8601String(),
            ];
        }

        if ($context->evidence === []) {
            return StageResult::skipped('There is no evidence to cite for this turn.');
        }

        return StageResult::ran(
            sprintf('%d evidence item%s ready to cite.', count($context->evidence), count($context->evidence) === 1 ? '' : 's'),
            ['ids' => array_column($context->evidence, 'id')]
        );
    }
}
