<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;

/**
 * Stage 7 - what real data did we actually get?
 *
 * Reports the count honestly, including "none": an empty result is a fact the answer must state,
 * not something to paper over with a plausible figure.
 */
final class RealDataStage implements LifecycleStage
{
    public function key(): StageKey
    {
        return StageKey::RealData;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->results === []) {
            return StageResult::skipped('No tool data was read for this turn.');
        }

        $readable = array_filter($context->results, fn ($r) => $r['error'] === null);
        $rows = array_sum(array_map(fn ($r) => $r['total'], $readable));

        return StageResult::ran(
            $rows === 0
                ? 'The data tools returned no rows for this organisation.'
                : sprintf('Read %d row%s from %d source%s.', $rows, $rows === 1 ? '' : 's', count($readable), count($readable) === 1 ? '' : 's'),
            ['rows' => $rows, 'sources' => count($readable)]
        );
    }
}
