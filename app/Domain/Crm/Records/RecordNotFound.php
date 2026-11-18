<?php

namespace App\Domain\Crm\Records;

/** The CRM record a note or a task was meant for does not exist in this organisation (or was deleted). The same answer for both, so a probe learns nothing. */
final class RecordNotFound extends \RuntimeException
{
}
