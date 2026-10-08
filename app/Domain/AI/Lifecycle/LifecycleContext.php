<?php

namespace App\Domain\AI\Lifecycle;

use App\Services\Ai\AiRequestScope;

/**
 * Everything one chat turn knows, passed from stage to stage.
 *
 * Nothing in here is application data of its own: the scope (who, which tenant) comes from the
 * token, the module and page are validated by the stages, and every fact later stages use is
 * read by the stage that fetched it. Public and mutable on purpose - it is a bag handed along a
 * fixed line, not a model.
 */
final class LifecycleContext
{
    public ?object $conversation = null;

    /** @var array<int, array{role:string, content:string}> */
    public array $history = [];

    /** The registered `ai_modules` row, when the chat is open inside one. */
    public ?object $module = null;

    /** @var array{title:string, breadcrumb:array<int,string>, module_key:string}|null */
    public ?array $page = null;

    public ?string $screen = null;

    /** `data` | `action` | `report` | `template` */
    public string $intent = 'data';

    /** @var array{key:string, label:string, description:string}|null */
    public ?array $matchedAction = null;

    /** @var array<int, array<string, mixed>> Data sources chosen to answer. */
    public array $selectedSources = [];

    /** @var array<string, array{source:array<string,mixed>, rows:array<int,array<string,mixed>>, total:int, truncated:bool, error:?string, personal:bool}> */
    public array $results = [];

    /** @var array<int, array<string, mixed>> */
    public array $evidence = [];

    public ?string $briefing = null;

    public ?string $answer = null;

    /** @var array<string, mixed>|null Provider usage for the model call, when one was made. */
    public ?array $usage = null;

    public bool $truncated = false;

    public ?string $error = null;

    /** False when the failure is "nobody has set a provider up" rather than a fault. */
    public bool $configured = true;

    /** @var array<string, mixed>|null The refusing policy decision. */
    public ?array $refusal = null;

    /** Set by a blocking stage; every later stage is reported as not reached. */
    public bool $halted = false;

    /** @var array<int, array<string, mixed>> */
    public array $recommendations = [];

    /** @var array{key:string, label:string, description:string}|null */
    public ?array $proposedAction = null;

    public bool $requiresApproval = false;

    /** @var array<string, mixed>|null A report generated for this turn. */
    public ?array $report = null;

    /** @var array<int, array<string, mixed>> */
    public array $reportSuggestions = [];

    /** @var array<int, array<string, mixed>> */
    public array $templateSuggestions = [];

    /** @var array<int, array<string, mixed>> Automations configured for the module. */
    public array $agents = [];

    public ?int $assistantTurnId = null;

    /** Why a requested report could not be built, in words for the user. */
    public ?string $reportNote = null;

    /**
     * @param  array<int, array{key:string, label:string, description:string, phrases?:array<int,string>}>  $availableActions
     *   Actions the page offers, sent by the client. They are metadata only - never data - and an
     *   action still needs an explicit Confirm in the browser before anything is written.
     */
    public function __construct(
        public readonly AiRequestScope $scope,
        public readonly string $sessionKey,
        public readonly string $message,
        public readonly ?string $moduleKey,
        public readonly ?int $menuId,
        public readonly ?array $pageData,
        public readonly string $organisation,
        public readonly array $availableActions = [],
        /** The AI Stack tab the chat is opened on, when it is (validated by the conversation stage). */
        public ?string $aiStackTab = null,
    ) {
    }
}
