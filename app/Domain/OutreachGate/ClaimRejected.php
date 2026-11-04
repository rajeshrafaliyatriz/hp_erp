<?php

namespace App\Domain\OutreachGate;

use RuntimeException;

/** A claim request that cannot be accepted, with a message a person can read. The controller answers it with a 422. */
final class ClaimRejected extends RuntimeException
{
}
