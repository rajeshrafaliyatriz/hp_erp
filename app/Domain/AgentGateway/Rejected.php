<?php

namespace App\Domain\AgentGateway;

/**
 * The gateway turned an action down. `reason` is a short stable code (it is stored in the audit row and tested); the message is for a person.
 * A refusal is a normal outcome of a run, never an error: it is audited and the run goes on with the next proposal.
 */
final class Rejected extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
