<?php

namespace App\Domain\Gtm\Agents;

/** There is nothing real to analyse. Reported to the user as such; the model is never asked to fill the gap. */
final class NoDataException extends \DomainException
{
}
