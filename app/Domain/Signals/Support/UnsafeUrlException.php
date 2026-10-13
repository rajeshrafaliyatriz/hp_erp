<?php

namespace App\Domain\Signals\Support;

/** A URL or page that must not be fetched. The message is safe to show to the user. */
class UnsafeUrlException extends \RuntimeException
{
}
