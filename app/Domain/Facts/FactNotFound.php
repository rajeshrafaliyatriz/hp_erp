<?php

namespace App\Domain\Facts;

use RuntimeException;

/** The fact does not exist for this organisation (another organisation's, or no such id): both are the same 404. */
final class FactNotFound extends RuntimeException
{
}
