<?php

namespace App\Domain\Facts;

use InvalidArgumentException;

/** A value that cannot be stored as a fact of its field (not an address, too long, a link to the wrong network). The message is for a person. */
final class InvalidFactValue extends InvalidArgumentException
{
}
