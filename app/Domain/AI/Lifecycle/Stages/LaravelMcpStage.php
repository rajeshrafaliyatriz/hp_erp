<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use Throwable;

/**
 * Stage 6 - run the chosen data tools.
 *
 * Each source is a read-only query scoped to the caller's organisation from the token; the scope
 * is not an argument and nothing the user typed reaches a query. One unreadable source is
 * recorded and the others still run.
 */
final class LaravelMcpStage implements LifecycleStage
{
    private const ROW_LIMIT = 100;

    public function __construct(private readonly ModuleDataSourceCatalog $sources, private readonly ModuleGrounding $grounding)
    {
    }

    public function key(): StageKey
    {
        return StageKey::LaravelMcp;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->selectedSources === []) {
            return StageResult::skipped('No data tool was selected.');
        }

        $failed = 0;

        foreach ($context->selectedSources as $source) {
            try {
                $result = $this->sources->run($source['name'], $context->scope, ['limit' => self::ROW_LIMIT]);
                $columns = $result['rows'] === [] ? [] : array_keys($result['rows'][0]);

                $context->results[$source['name']] = [
                    'source' => $source,
                    'rows' => $result['rows'],
                    'total' => $result['total'],
                    'truncated' => $result['truncated'],
                    'error' => null,
                    'personal' => $columns !== [] && $this->grounding->isPersonal($columns),
                ];
            } catch (Throwable $exception) {
                $failed++;
                $context->results[$source['name']] = [
                    'source' => $source, 'rows' => [], 'total' => 0, 'truncated' => false,
                    'error' => 'This source could not be read.', 'personal' => false,
                ];
            }
        }

        $ran = count($context->results) - $failed;

        if ($ran === 0) {
            return StageResult::failed('None of the selected data tools could be read.');
        }

        return StageResult::ran(
            sprintf('Ran %d data tool%s%s.', $ran, $ran === 1 ? '' : 's', $failed > 0 ? " ($failed could not be read)" : ''),
            ['tools' => array_keys($context->results)]
        );
    }
}
