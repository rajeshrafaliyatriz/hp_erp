<?php

namespace App\Domain\AgentGateway;

/**
 * A model's proposal after the gateway has checked it: only the fields the action defines, normalised, with the records it cites as the CONTEXT holds
 * them (the label and address come from the context, never from the model), and the hash that makes the same proposal impossible to offer twice.
 */
final class ValidatedAction
{
    /**
     * @param  array<string, mixed>  $payload  the action's normalised arguments; plain text only
     * @param  list<array{type: string, id: string, label: string, url: ?string}>  $evidence
     */
    public function __construct(
        public readonly string $action,
        public readonly array $payload,
        public readonly string $hash,
        public readonly ?string $entityType,
        public readonly ?int $entityId,
        public readonly array $evidence,
        public readonly string $reason,
    ) {
    }
}
