<?php

namespace App\Domain\AI\Lifecycle;

interface LifecycleStage
{
    public function key(): StageKey;

    /**
     * Do this stage's work, reading from and writing to the shared context.
     *
     * A stage never throws for an expected condition (no data, no model, a refused policy): it
     * returns the matching result. An unexpected exception is caught by the pipeline and reported
     * as a failed stage, so one broken stage cannot take down the whole turn.
     */
    public function run(LifecycleContext $context): StageResult;
}
