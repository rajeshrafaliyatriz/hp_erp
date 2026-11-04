<?php

namespace App\Domain\Crm\Records;

/**
 * The calendar turned a task down (RecordTask): its own sentence says why ("An event cannot end before it starts."). A class of its own so a caller can
 * tell "the task was refused" from a database failure, which is also a RuntimeException and whose message must never reach a person.
 */
final class RecordRefused extends \RuntimeException
{
}
