<?php

namespace App\Domain\Signals\Run;

/** A verification run reached one of its hard call ceilings. Never retried. */
final class RunBudgetExceeded extends \RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        parent::__construct('The verification run reached its ' . $kind . ' limit.');
    }
}
