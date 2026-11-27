<?php

namespace App\Domain\ProductMaster\Kit;

/**
 * The Sales Kit's database change has not been applied on this host (a table is missing), so nothing can be read or written. The controller answers 503 `not_set_up` with the message,
 * the same way the Outreach tab does on a host that has not had its migration yet.
 */
final class KitNotSetUp extends \RuntimeException
{
    public function __construct(string $message = 'The Sales Kit is not set up on this server yet (its database change has not been applied).')
    {
        parent::__construct($message);
    }
}
