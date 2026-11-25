<?php

namespace App\Domain\Intelligence\Access;

use RuntimeException;

/** The caller cannot be placed in an organisation (or not at all). Maps to HTTP 403 with a fixed body; the message never says why in a way that helps probing. */
final class IntelligenceDenied extends RuntimeException
{
}
