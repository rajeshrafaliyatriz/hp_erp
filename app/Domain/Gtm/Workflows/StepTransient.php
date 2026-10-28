<?php

namespace App\Domain\Gtm\Workflows;

/** A step that failed for a reason that may clear by itself (AI quota or overload): the run is retried later. */
final class StepTransient extends \RuntimeException
{
}
