<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Stage 12 - hand the user something they can act on.
 *
 * - An ACTION is proposed, not performed: the browser opens the form and carries it out as the
 *   signed-in user after Confirm (and approval, when required). This stage never writes.
 * - A REPORT is generated and saved here, from the module's real data, through the same
 *   service the report screens use. A report is a draft artifact, not a change to business
 *   records, so it needs no approval.
 */
final class ActionStage implements LifecycleStage
{
    public function __construct(private readonly Container $container)
    {
    }

    public function key(): StageKey
    {
        return StageKey::Action;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->matchedAction !== null) {
            $context->proposedAction = $context->matchedAction;

            return StageResult::pending('Opened "' . $context->matchedAction['label'] . '" for you to review.', ['action' => $context->matchedAction['key']]);
        }

        if ($context->intent === 'report' && $context->module !== null) {
            $class = 'App\\Domain\\AI\\Chat\\ChatReportService';

            if (! class_exists($class)) {
                return StageResult::skipped('Report generation is not installed on this deployment.');
            }

            try {
                $attempt = $this->container->make($class)->attempt($context->scope, (string) $context->module->module_key, $context->message);
                $report = $attempt['report'];
                $reason = $attempt['reason'];
            } catch (Throwable $exception) {
                report($exception);

                return StageResult::failed('The report could not be generated.', ['error' => $exception->getMessage()]);
            }

            if ($report === null) {
                $context->reportNote = $reason;

                return StageResult::skipped($reason ?? 'This module has no report template or data source matching the request.');
            }

            $context->report = $report;

            return StageResult::ran('Generated "' . ($report['title'] ?? 'report') . '" from ' . ($report['row_count'] ?? 0) . ' rows.', ['report_id' => $report['id'] ?? null]);
        }

        return StageResult::skipped('No action or report was requested.');
    }
}
