<?php

namespace App\Domain\Facts;

use RuntimeException;

/** Another request decided the fact between this request's read and its claim: thrown inside the transaction so nothing of this request is kept. */
final class LostTheFactClaim extends RuntimeException
{
}
