<?php

namespace App\Domain\AgentGateway;

/**
 * A request the agent layer will not carry out, with a stable `code` (the screen and the tests read it), a sentence for a person and the HTTP status
 * the API answers with. Raised before anything is written: agent_off (kill switch), policy_off, daily_cap, already_running, not_found.
 */
final class AgentRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
