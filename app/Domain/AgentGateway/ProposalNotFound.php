<?php

namespace App\Domain\AgentGateway;

/** The proposal does not exist in the caller's organisation: the same answer for "never existed" and "somebody else's", so a probe learns nothing. */
final class ProposalNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Proposal not found.');
    }
}
