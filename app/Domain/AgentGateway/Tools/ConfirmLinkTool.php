<?php

namespace App\Domain\AgentGateway\Tools;

use App\Domain\AgentGateway\AgentContext;
use App\Domain\AgentGateway\AgentSubjects;
use App\Domain\AgentGateway\Rejected;
use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgLinkConflict;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgLinkNotFound;
use App\Domain\OrgAccounts\OrgSources;

/**
 * Confirms a probable link: two records the Organisation 360 suggests are the same organisation. The model does not type the ids of the pair, it names
 * a suggestion from the context (`pair_key`), and the pair is taken from the context's own list: a pair the model invented, or one about another
 * organisation, is not in it. Applying runs the same OrgLinker a person's "Same organisation" button runs; its conflicts become a failed proposal.
 */
final class ConfirmLinkTool implements AgentTool
{
    public const NAME = 'confirm_link';

    public function name(): string
    {
        return self::NAME;
    }

    public function validate(array $raw, AgentContext $context): array
    {
        $key = $raw['pair_key'] ?? null;
        $suggestion = is_string($key) ? $context->suggestion($key) : null;
        if ($suggestion === null) {
            throw new Rejected('unknown_link_suggestion', 'That pair is not one of the suggestions the agent was shown.');
        }

        return [
            'payload' => ['pair_key' => $suggestion['key'], 'left' => $suggestion['left'], 'right' => $suggestion['right'], 'basis' => $suggestion['basis'], 'score' => $suggestion['score']],
            'hash' => sha1(self::NAME . '|' . implode('|', $this->sortedKeys($suggestion['left'], $suggestion['right']))),
            'entity_type' => null,
            'entity_id' => null,
            'extra_evidence' => [['type' => 'link_suggestion', 'id' => $suggestion['key']]],
        ];
    }

    public function apply(int $tenantId, int $actorId, OrgAccount $subject, array $payload, string $reason): string
    {
        $left = $payload['left'];
        $right = $payload['right'];
        // One end must still be this organisation's own record; otherwise the proposal is about something that has since moved.
        $mine = [];
        foreach (OrgAccount::POINTERS as $type => $column) {
            if ($subject->{$column} !== null) {
                $mine[OrgSources::key($type, (int) $subject->{$column})] = true;
            }
        }
        if (! isset($mine[OrgSources::key($left['type'], (int) $left['id'])]) && ! isset($mine[OrgSources::key($right['type'], (int) $right['id'])])) {
            throw new Rejected('target_changed', 'The organisation no longer owns either end of this pair.');
        }

        try {
            $identity = app(OrgLinker::class)->confirm($tenantId, $actorId, $left['type'], (int) $left['id'], $right['type'], (int) $right['id']);
        } catch (OrgLinkNotFound) {
            throw new Rejected('record_gone', 'One of the two records was not found. It may have been deleted.');
        } catch (OrgLinkConflict $e) {
            throw new Rejected('link_conflict', $e->getMessage());
        }

        // When the two organisations were each already an identity, the older one survives and this subject may be the one that was removed: its runs
        // and proposals (this one included) follow it, or they would sit on a deleted identity and the waiting ones could never be decided.
        $survivor = (int) ($identity['id'] ?? $subject->id);
        if ($survivor !== (int) $subject->id) {
            AgentSubjects::repoint($tenantId, (int) $subject->id, $survivor);
        }

        return 'g2g_org_accounts:' . $survivor;
    }

    /**
     * @param  array{type: string, id: int}  $left
     * @param  array{type: string, id: int}  $right
     * @return list<string>
     */
    private function sortedKeys(array $left, array $right): array
    {
        $keys = [OrgSources::key($left['type'], (int) $left['id']), OrgSources::key($right['type'], (int) $right['id'])];
        sort($keys);

        return $keys;
    }
}
