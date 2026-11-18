<?php

namespace App\Domain\AgentGateway\Tools;

use App\Domain\AgentGateway\AgentContext;
use App\Domain\AgentGateway\Rejected;
use App\Domain\Crm\Records\RecordNote;
use App\Domain\Crm\Records\RecordNotFound;
use App\Domain\OrgAccounts\OrgAccount;

/** Adds a note to the subject's CRM organisation. The note is plain text, written as the person the agent acts for, and marked `created_via = agent`. */
final class AddNoteTool implements AgentTool
{
    public const NAME = 'add_note';

    /** Written in front of the text so every screen that lists the comments shows who wrote it, even one that does not read `created_via`. */
    public const PREFIX = '[Agent] ';

    public function name(): string
    {
        return self::NAME;
    }

    public function validate(array $raw, AgentContext $context): array
    {
        if ($context->crmOrganizationId === null) {
            throw new Rejected('no_crm_organisation', 'This organisation has no CRM record to attach a note to.');
        }
        $text = Text::within(Text::plain($raw['text'] ?? null, true), (int) config('agent_actions.max_note_chars', 1000), 'text');

        return [
            'payload' => ['text' => $text],
            'hash' => sha1(self::NAME . '|' . Text::fold($text)),
            'entity_type' => 'organization',
            'entity_id' => $context->crmOrganizationId,
            'extra_evidence' => [],
        ];
    }

    public function apply(int $tenantId, int $actorId, OrgAccount $subject, array $payload, string $reason): string
    {
        // The target is re-derived from the subject NOW, not read from the proposal: a link that has changed since the proposal was made cannot redirect it.
        if ($subject->crm_organization_id === null) {
            throw new Rejected('no_crm_organisation', 'This organisation has no CRM record to attach a note to.');
        }
        try {
            $id = (new RecordNote())->record($tenantId, 'organization', (int) $subject->crm_organization_id, $actorId, self::PREFIX . (string) $payload['text'], 'agent');
        } catch (RecordNotFound) {
            throw new Rejected('record_gone', 'The CRM organisation was not found. It may have been deleted.');
        }

        return "crm_organization_comments:{$id}";
    }
}
