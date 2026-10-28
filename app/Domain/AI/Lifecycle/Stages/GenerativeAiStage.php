<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Services\Ai\AiPolicyResolver;

/**
 * Stage 2 - may this request use AI at all?
 *
 * The narrowest policy assignment wins (the module, then the whole organisation). This runs
 * BEFORE any data is read or any model is called, so a refusal costs nothing and exposes
 * nothing. A refusal halts the turn.
 */
final class GenerativeAiStage implements LifecycleStage
{
    public function __construct(private readonly AiPolicyResolver $policies)
    {
    }

    public function key(): StageKey
    {
        return StageKey::GenerativeAi;
    }

    public function run(LifecycleContext $context): StageResult
    {
        $decision = $this->policies->resolve($context->scope->selectedInstituteId, [
            'operation' => 'ai_request',
            'module_id' => $context->module === null ? null : (int) $context->module->id,
        ]);

        if (! $decision['allowed']) {
            $context->refusal = $decision;
            $context->error = (string) $decision['message'];

            return StageResult::blocked((string) $decision['message'], ['policy_id' => $decision['policy_id']]);
        }

        return StageResult::ran(
            $decision['policy_id'] === null ? 'No restricting policy applies.' : 'Permitted under policy #' . $decision['policy_id'] . '.',
            ['policy_id' => $decision['policy_id']]
        );
    }
}
