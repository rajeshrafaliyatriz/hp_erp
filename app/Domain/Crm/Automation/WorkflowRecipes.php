<?php

namespace App\Domain\Crm\Automation;

use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\Workflows\WorkflowCatalog;
use App\Domain\Gtm\Workflows\WorkflowSentence;

/**
 * The recommended workflows (LeadAutomationPresets) as a gallery a person can read and add from, one at a time: the list for the Workflows screen and the
 * single install behind its "Add as draft" button. Nothing here is new automation: a recipe is a preset, an install is the installer's own code path
 * (LeadAutomationInstaller::installOne), so a recipe added here is the same row `php artisan crm:lead-automation-install` would have made.
 *
 * The prefix is the command's default on purpose: the installer finds "already added" by name, so a workflow added from the console and one added from
 * the screen are recognised as the same recipe. Support tickets that a recipe opens are assigned to the person who adds it (the one value a recipe needs
 * from the organisation); it is an ordinary parameter they can change while the workflow is still a draft.
 */
final class WorkflowRecipes
{
    public const PREFIX = 'Lead Automation';

    /**
     * @return list<array{key: string, title: string, sentence: string, trigger: string, trigger_label: string, actions: list<array{type: string, label: string}>, consequential: bool, requires_approval: bool, installed_workflow_id: ?int}>
     */
    public static function list(int $tenant, int $userId): array
    {
        $recipes = LeadAutomationPresets::all(self::options($userId));
        $installed = LeadAutomationInstaller::existingIds($tenant, array_column($recipes, 'name'), false);
        $catalog = WorkflowCatalog::actions();

        return array_map(function (array $d) use ($installed, $catalog): array {
            $consequential = WorkflowCatalog::hasConsequential($d['actions']);
            $requiresApproval = (bool) ($d['requires_approval'] ?? false);

            return [
                'key' => $d['key'], 'title' => $d['title'],
                'sentence' => WorkflowSentence::build($d['trigger_event'], $d['conditions'], $d['actions'], $consequential || $requiresApproval),
                'trigger' => $d['trigger_event'], 'trigger_label' => GtmEvents::TYPES[$d['trigger_event']]['label'] ?? $d['trigger_event'],
                'actions' => array_map(fn (array $a) => ['type' => (string) $a['type'], 'label' => (string) ($catalog[$a['type']]['label'] ?? $a['type'])], $d['actions']),
                'consequential' => $consequential, 'requires_approval' => $requiresApproval,
                'installed_workflow_id' => $installed[$d['name']] ?? null,
            ];
        }, $recipes);
    }

    /**
     * Adds one recipe as a DRAFT for the organisation.
     *
     * @return array{outcome: 'created'|'existing'|'invalid'|'unknown', id: ?int, errors: list<string>}
     *
     * @throws \InvalidArgumentException when the user is not an active administrator of the organisation
     */
    public static function install(int $tenant, int $adminUserId, string $key): array
    {
        return LeadAutomationInstaller::installOne($tenant, $adminUserId, self::options($adminUserId), $key, 'recipe');
    }

    /** @return array<string, mixed> */
    private static function options(int $userId): array
    {
        return ['prefix' => self::PREFIX, 'support_user_id' => $userId];
    }
}
