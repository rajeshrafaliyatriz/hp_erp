<?php

namespace App\Domain\AgentGateway;

/** Internal to ProposalApplier: the conditional UPDATE that claims a pending proposal changed no row, because another request claimed it first. */
final class LostTheClaim extends \RuntimeException
{
}
