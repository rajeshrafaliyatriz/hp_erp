<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;

/**
 * Stage 11 - does this need a person's decision before anything is written?
 *
 * Every proposed write needs the requester's explicit Confirm; when approvals are enabled
 * (`ai.chat_actions.require_approval`) it also needs an administrator other than the requester.
 * This stage records WHICH applies. The decision itself lives in the approval ledger
 * (ai_action_requests), enforced server-side by ActionRequestController.
 */
final class HumanApprovalStage implements LifecycleStage
{
    public function key(): StageKey
    {
        return StageKey::HumanApproval;
    }

    public function run(LifecycleContext $context): StageResult
    {
        if ($context->matchedAction === null) {
            return StageResult::skipped('Nothing is being written, so nothing needs approval.');
        }

        $context->requiresApproval = (bool) config('ai.chat_actions.require_approval', false);

        return $context->requiresApproval
            ? StageResult::pending('An administrator must approve "' . $context->matchedAction['label'] . '" before it runs.', ['approval' => 'administrator'])
            : StageResult::pending('"' . $context->matchedAction['label'] . '" waits for your Confirm.', ['approval' => 'requester']);
    }
}
