<?php

namespace App\Domain\Facts;

use RuntimeException;

/** Thrown inside the transaction of a dry run so that everything it did is rolled back: the dry run is the real run, undone. */
final class DryRunRollback extends RuntimeException
{
}
