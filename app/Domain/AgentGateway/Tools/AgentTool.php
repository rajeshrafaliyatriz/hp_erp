<?php

namespace App\Domain\AgentGateway\Tools;

use App\Domain\AgentGateway\AgentContext;
use App\Domain\AgentGateway\Rejected;
use App\Domain\OrgAccounts\OrgAccount;

/**
 * One thing an agent may ask for. The registry holds three of them and there is no way to add a fourth at run time: a model that names anything else
 * is refused as `unknown_tool`. A tool has two halves, and the gateway calls them at different moments:
 *
 *   validate  - at proposal time, from the model's raw arguments and the context the model was sent. Builds the normalised payload. Never writes.
 *   apply     - when the action is carried out (at once for an `alone` level, or when a person accepts). Writes through a shared service, as the person
 *               the agent acts for. Re-derives its target from the subject, so a proposal that has gone stale cannot reach a record it was not made for.
 */
interface AgentTool
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $raw
     * @return array{payload: array<string, mixed>, hash: string, entity_type: ?string, entity_id: ?int, extra_evidence: list<array{type: string, id: string}>}
     *
     * @throws Rejected
     */
    public function validate(array $raw, AgentContext $context): array;

    /**
     * @param  array<string, mixed>  $payload  as validate() returned it
     * @return string what was written, as table:id
     *
     * @throws Rejected
     */
    public function apply(int $tenantId, int $actorId, OrgAccount $subject, array $payload, string $reason): string;
}
