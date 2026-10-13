<?php

namespace App\Domain\Gtm\Workflows;

/** A step that did nothing because there was nothing to do (not a failure): recorded as `skipped`. */
final class StepSkipped extends \RuntimeException
{
}
