<?php

namespace App\Domain\AgentGateway\Tools;

use App\Domain\AgentGateway\AgentContext;
use App\Domain\AgentGateway\Rejected;
use App\Domain\Crm\Records\RecordNotFound;
use App\Domain\Crm\Records\RecordRefused;
use App\Domain\Crm\Records\RecordTask;
use App\Domain\OrgAccounts\OrgAccount;

/**
 * Adds a follow-up task to the subject's CRM organisation, for the person the agent acts for (never for anybody else: a task assigned to another
 * person is a message to that person). The due date must be a real date between today and the configured window.
 */
final class AddTaskTool implements AgentTool
{
    public const NAME = 'add_task';

    public function name(): string
    {
        return self::NAME;
    }

    public function validate(array $raw, AgentContext $context): array
    {
        if ($context->crmOrganizationId === null) {
            throw new Rejected('no_crm_organisation', 'This organisation has no CRM record to attach a task to.');
        }
        $title = Text::within(Text::plain($raw['title'] ?? null, false), (int) config('agent_actions.max_task_title_chars', 150), 'title');
        $descriptionText = Text::plain($raw['description'] ?? null, true);
        $description = $descriptionText === '' ? null : Text::within($descriptionText, (int) config('agent_actions.max_task_description_chars', 500), 'description');

        $due = $raw['due_date'] ?? null;
        $date = is_string($due) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $due) : false;
        if ($date === false || $date->format('Y-m-d') !== $due) {
            throw new Rejected('bad_due_date', 'The due date is not a real date written YYYY-MM-DD.');
        }
        $window = (int) config('agent_actions.due_window_days', 90);
        $today = new \DateTimeImmutable($context->today);
        if ($date < $today || $date > $today->modify("+{$window} days")) {
            throw new Rejected('due_date_out_of_window', "The due date is outside the next {$window} days.");
        }

        return [
            'payload' => ['title' => $title, 'due_date' => $date->format('Y-m-d')] + ($description !== null ? ['description' => $description] : []),
            'hash' => sha1(self::NAME . '|' . Text::fold($title) . '|' . $date->format('Y-m-d')),
            'entity_type' => 'organization',
            'entity_id' => $context->crmOrganizationId,
            'extra_evidence' => [],
        ];
    }

    public function apply(int $tenantId, int $actorId, OrgAccount $subject, array $payload, string $reason): string
    {
        if ($subject->crm_organization_id === null) {
            throw new Rejected('no_crm_organisation', 'This organisation has no CRM record to attach a task to.');
        }
        $description = 'Added by the Organisation agent.' . ($reason !== '' ? " Why: {$reason}" : '') . (isset($payload['description']) ? "\n\n" . $payload['description'] : '');
        try {
            $id = (new RecordTask())->record($tenantId, 'organization', (int) $subject->crm_organization_id, $actorId, $actorId, (string) $payload['title'], $description, (string) $payload['due_date']);
        } catch (RecordNotFound) {
            throw new Rejected('record_gone', 'The CRM organisation was not found. It may have been deleted.');
        } catch (RecordRefused $e) {
            // The calendar refused the event, with its own sentence: a refusal like any other, so the proposal is marked failed with the reason and the
            // run goes on, rather than the whole run failing after earlier actions were applied. (Only this class: a database failure is never shown.)
            throw new Rejected('task_not_scheduled', $e->getMessage());
        }
        if ($id === null) {
            throw new Rejected('duplicate_open_task', 'An open task with this title already exists on the organisation.');
        }

        return "task_management_calendar_events:{$id}";
    }
}
