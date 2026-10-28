<?php

namespace App\Domain\AI\Lifecycle;

/**
 * What happened at one stage. `Pending` means the stage did its part and the next move belongs
 * to a person (an action card waiting for Confirm, a request waiting for an administrator).
 */
enum StageStatus: string
{
    case Ran = 'ran';
    case Skipped = 'skipped';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Pending = 'pending';
    case NotReached = 'not_reached';
}
