<?php

namespace App\Domain\OrgAccounts;

/**
 * The link cannot be made as asked. Nothing was written. `reason` is one of the constants below so a caller can answer in the user's words.
 */
final class OrgLinkConflict extends \RuntimeException
{
    /** Both ends are the same source row. */
    public const SAME_ROW = 'same_row';

    /** The two identities each already hold a row of the same source, and one organisation holds one row per source. */
    public const SAME_SOURCE = 'same_source';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
