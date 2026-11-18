<?php

namespace App\Domain\Signals\Backfill;

/**
 * A backfill cannot run on this connection because something it needs is not there yet (a table or a column a migration adds). Nothing was
 * written. The command prints the message and exits with a failure; any other exception is a real error and is not caught.
 */
final class BackfillBlocked extends \RuntimeException {}
