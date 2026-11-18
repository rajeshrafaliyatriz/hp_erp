<?php

namespace App\Domain\OrgAccounts;

/**
 * The backfill cannot write on this connection because a table it needs is not there yet (a migration adds it). Nothing was written. The command
 * prints the message and exits with a failure; any other exception is a real error and is not caught.
 */
final class OrgBackfillBlocked extends \RuntimeException {}
