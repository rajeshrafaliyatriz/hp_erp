<?php

namespace App\Domain\AI\Chat;

use RuntimeException;

/** The report does not exist in the caller's organisation (mapped to HTTP 404). */
class NotFoundReport extends RuntimeException
{
}
