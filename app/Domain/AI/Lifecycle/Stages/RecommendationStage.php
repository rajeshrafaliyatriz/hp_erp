<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Domain\AI\Workspace\SourceQuestions;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Stage 10 - what could the user sensibly do next?
 *
 * Everything offered here is derived from this module's real configuration, never written by the
 * model: report types and templates that actually exist for the module, and follow-up questions
 * for the data sources this turn read. Nothing is offered that the module cannot do.
 */
final class RecommendationStage implements LifecycleStage
{
    public function __construct(private readonly Container $container, private readonly SourceQuestions $questions)
    {
    }

    public function key(): StageKey
    {
        return StageKey::Recommendation;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->module === null) {
            return StageResult::skipped('Recommendations are module-specific; open a module.');
        }

        $moduleKey = (string) $context->module->module_key;

        $context->reportSuggestions = $this->safely(
            'App\\Domain\\AI\\Chat\\ChatReportService',
            fn ($service) => $service->suggest($context->scope, $moduleKey, $context->message)
        );
        $context->templateSuggestions = $this->safely(
            'App\\Domain\\AI\\Chat\\ChatTemplateService',
            fn ($service) => $service->suggest($context->scope, $moduleKey)
        );

        // Follow-up questions for what was just read.
        foreach (array_slice($context->selectedSources, 0, 2) as $source) {
            foreach (array_slice($this->questions->forSource($source), 0, 2) as $question) {
                $context->recommendations[] = ['kind' => 'follow_up', 'title' => $question, 'prompt' => $question];
            }
        }

        foreach (array_slice($context->reportSuggestions, 0, 3) as $report) {
            $context->recommendations[] = [
                'kind' => 'report',
                'title' => 'Generate: ' . ($report['name'] ?? $report['label'] ?? 'report'),
                'prompt' => 'Generate the ' . ($report['name'] ?? $report['label'] ?? 'report') . ' report',
            ];
        }

        $count = count($context->recommendations);

        return $count === 0
            ? StageResult::skipped('Nothing further to suggest for this module.')
            : StageResult::ran(sprintf('%d suggestion%s for what to do next.', $count, $count === 1 ? '' : 's'));
    }

    /**
     * @param  callable(object): array<int, array<string, mixed>>  $call
     * @return array<int, array<string, mixed>>
     */
    private function safely(string $class, callable $call): array
    {
        if (! class_exists($class)) {
            return [];
        }

        try {
            return $call($this->container->make($class));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
