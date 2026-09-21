<?php

namespace App\Domain\AI\Support;

use RuntimeException;

/**
 * A call was refused because the organisation's token quota is spent.
 *
 * Its own class so a caller can tell it apart from a provider failure. They need
 * different responses: a quota refusal is fixed by raising a limit or waiting for the
 * period to reset, and retrying will not help, whereas a provider error often clears
 * on its own. Collapsing the two would have every screen suggest "try again" to
 * somebody who has hit a ceiling.
 *
 * `AiController::handle()` renders a RuntimeException as a 422 with its message shown
 * to the caller, which is the treatment this wants — so the message names the screen
 * that raises the limit.
 */
class AiQuotaExceededException extends RuntimeException
{
}
