<?php

namespace App\Domain\ProductMaster\Kit;

/**
 * A Sales Kit request that cannot be done, with the HTTP status the API answers with (404 an unknown or other-organisation record, 422 invalid input) and, for a refusal the screen
 * acts on, a short `code` (`not_shared`, `already_in_kit`, `no_file`). Nothing has been written when it is thrown: the writes run in one transaction.
 */
final class KitRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'That product was not found.'): self
    {
        return new self($what, 404);
    }

    public static function invalid(string $message, ?string $reason = null): self
    {
        return new self($message, 422, $reason);
    }
}
