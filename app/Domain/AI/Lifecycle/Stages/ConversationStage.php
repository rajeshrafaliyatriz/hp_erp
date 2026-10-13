<?php

namespace App\Domain\AI\Lifecycle\Stages;

use App\Domain\AI\Conversation\ConversationStore;
use App\Domain\AI\Conversation\ModuleGrounding;
use App\Domain\AI\Lifecycle\LifecycleContext;
use App\Domain\AI\Lifecycle\LifecycleStage;
use App\Domain\AI\Lifecycle\StageKey;
use App\Domain\AI\Lifecycle\StageResult;
use App\Domain\AI\Workspace\AiStackTabContext;
use App\Domain\AI\Workspace\PageContextResolver;
use App\Domain\AI\Workspace\PageSnapshot;

/**
 * Stage 1 - open or resume the conversation and establish where the user is.
 *
 * The module comes from the registered `ai_modules` row (the controller already refused an
 * unknown key). The page counts only when it genuinely sits under that module's menu tree: a
 * menu id from another module is ignored, never trusted into the prompt. What the browser read
 * off the screen is rebuilt and capped by PageSnapshot before anything uses it.
 */
final class ConversationStage implements LifecycleStage
{
    public function __construct(
        private readonly ConversationStore $conversations,
        private readonly ModuleGrounding $grounding,
        private readonly PageContextResolver $pages,
        private readonly AiStackTabContext $stackTabs,
    ) {
    }

    public function key(): StageKey
    {
        return StageKey::Conversation;
    }

    public function run(LifecycleContext $context): StageResult
    {
        $institute = $context->scope->selectedInstituteId;

        $conversation = $this->conversations->resume(
            $context->sessionKey,
            $institute,
            $context->scope->userId,
            $context->moduleKey,
            $context->scope->clientId
        );

        // The transcript BEFORE this question, or the model would see the question twice.
        $context->history = $this->conversations->contextMessages((int) $conversation->id, $institute);
        $this->conversations->addTurn((int) $conversation->id, $institute, 'user', $context->message);
        $this->conversations->titleFromFirstMessage((int) $conversation->id, $context->message);
        $context->conversation = $conversation;

        $context->module = $context->moduleKey === null ? null : $this->grounding->module($context->moduleKey, $institute);

        if ($context->module !== null && $context->menuId !== null) {
            $resolved = $this->pages->resolve($context->scope, $context->menuId);

            if ($resolved['page'] !== null && $resolved['module'] !== null
                && $resolved['module']['root_key'] === (string) $context->module->module_key) {
                $context->page = [
                    'title' => $resolved['page']['title'],
                    'breadcrumb' => $resolved['page']['breadcrumb'],
                    'module_key' => $resolved['module']['key'],
                ];

                $snapshot = PageSnapshot::clean($context->pageData);
                $context->screen = $snapshot === null ? null : PageSnapshot::describe($snapshot);
            }
        }

        // A page outside every AI module still has a title and a screen the user is looking at.
        if ($context->module === null && $context->menuId !== null) {
            $resolved = $this->pages->resolve($context->scope, $context->menuId);

            if ($resolved['page'] !== null) {
                $context->page = ['title' => $resolved['page']['title'], 'breadcrumb' => $resolved['page']['breadcrumb'], 'module_key' => ''];
                $snapshot = PageSnapshot::clean($context->pageData);
                $context->screen = $snapshot === null ? null : PageSnapshot::describe($snapshot);
            }
        }

        // On a tab of the module's AI Stack the page IS the tab. Only a real tab id counts.
        $tab = $this->stackTabs->valid($context->aiStackTab);
        $context->aiStackTab = $context->module === null ? null : $tab;

        if ($context->aiStackTab !== null) {
            $moduleLabel = (string) $context->module->label;
            $context->page = [
                'title' => $moduleLabel . ' AI Stack - ' . $this->stackTabs->label($context->aiStackTab),
                'breadcrumb' => [$moduleLabel, 'AI Stack', $this->stackTabs->label($context->aiStackTab)],
                'module_key' => (string) $context->module->module_key,
            ];
            $snapshot = PageSnapshot::clean($context->pageData);
            $context->screen = $snapshot === null ? null : PageSnapshot::describe($snapshot);
        }

        $where = $context->module === null ? 'the organisation-wide assistant' : (string) $context->module->label;

        return StageResult::ran(
            sprintf('Conversation #%d in %s%s.', $conversation->id, $where, $context->page ? ', on "' . $context->page['title'] . '"' : ''),
            [
                'conversation_id' => (int) $conversation->id,
                'module_key' => $context->module?->module_key,
                'page' => $context->page['title'] ?? null,
                'screen_read' => $context->screen !== null,
                'history_turns' => count($context->history),
            ]
        );
    }
}
