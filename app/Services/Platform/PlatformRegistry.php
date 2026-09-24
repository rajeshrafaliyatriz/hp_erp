<?php

namespace App\Services\Platform;

/**
 * Reads `config/platform_services.php` and answers questions about it.
 *
 * Every write in the Platform Services API validates its keys through this class, and
 * the same catalogue is served to the frontend at `GET /api/platform/registry`. That is
 * what makes it impossible for a screen to offer a setting the API would refuse: both
 * are reading the same declaration.
 *
 * ── `problems()` REPORTS, IT DOES NOT THROW ─────────────────────────────────
 *
 * A component naming a module that does not exist is a mistake in the config file. The
 * tempting response is to throw on boot so nobody can miss it. That would take down the
 * whole application — including the console screen that would have told you which line
 * was wrong — for a typo. So inconsistencies ride along beside a working payload, and
 * the console renders them.
 */
class PlatformRegistry
{
    /** `hrms.leave.approval` belongs to `hrms`. */
    public static function moduleOf(string $key): string
    {
        return explode('.', $key)[0] ?? '';
    }

    /** `hrms.leave.approval` sits on `hrms.leave`. */
    public static function componentOf(string $key): string
    {
        $parts = explode('.', $key);

        return count($parts) >= 2 ? $parts[0] . '.' . $parts[1] : $key;
    }

    /** @return array<string, mixed> */
    public function modules(): array
    {
        return (array) config('platform_services.modules', []);
    }

    /** @return array<string, mixed> */
    public function components(): array
    {
        return (array) config('platform_services.components', []);
    }

    /** @return array<string, mixed> */
    public function workflowPoints(): array
    {
        return (array) config('platform_services.workflows', []);
    }

    /** @return array<string, mixed> */
    public function approverTypes(): array
    {
        return (array) config('platform_services.approver_types', []);
    }

    /** @return array<string, mixed> */
    public function escalationActions(): array
    {
        return (array) config('platform_services.escalation_actions', []);
    }

    /** @return array<string, mixed> */
    public function scheduledTasks(): array
    {
        return (array) config('platform_services.tasks', []);
    }

    /**
     * The declared task whose artisan command this is, or null.
     *
     * `ScheduleReader` walks Laravel's live schedule and needs to know which of
     * those events the catalogue describes — matching on the command name is what
     * joins the two without a second list of tasks to keep in step.
     *
     * @return array{key: string, task: array<string, mixed>}|null
     */
    public function taskForCommand(string $command): ?array
    {
        foreach ($this->scheduledTasks() as $key => $task) {
            if (($task['command'] ?? null) === $command) {
                return ['key' => $key, 'task' => $task];
            }
        }

        return null;
    }

    /**
     * Whether a task can honour a per-tenant override.
     *
     * Only two of the six commands take `--tenant`. Offering an override on the
     * others would be a control that silently does nothing — see the config
     * file's note on this list.
     */
    public function taskIsTenantScoped(string $taskKey): bool
    {
        return (bool) ($this->scheduledTasks()[$taskKey]['tenant_scoped'] ?? false);
    }

    public function hasScheduledTask(string $taskKey): bool
    {
        return array_key_exists($taskKey, $this->scheduledTasks());
    }

    /** @return array<string, string> */
    public function customFieldTables(): array
    {
        return (array) config('platform_services.custom_field_tables', []);
    }

    /**
     * The allowlisted tables as `[{key, label}]`, for a dropdown.
     *
     * The screen offers only these, and the API refuses anything else — the same list,
     * read twice, which is the whole point of the registry living in one place.
     *
     * @return array<int, array<string, string>>
     */
    public function customFieldTableOptions(): array
    {
        $out = [];

        foreach ($this->customFieldTables() as $table => $label) {
            $out[] = ['key' => $table, 'label' => (string) $label];
        }

        return $out;
    }

    public function hasWorkflowPoint(string $key): bool
    {
        return array_key_exists($key, $this->workflowPoints());
    }

    /**
     * Whether anything in the product actually reads a chain saved at this point.
     *
     * The distinction the console exists to make honest. A point without
     * `enforced_by` can be configured and stored, and nothing will act on it — so
     * an active chain there must not be presented as a sign-off that will happen.
     */
    public function isEnforced(string $key): bool
    {
        return ! empty($this->workflowPoints()[$key]['enforced_by']);
    }

    /** What enforcement means for this point, for the screen to show beside it. */
    public function enforcementNote(string $key): ?string
    {
        return $this->workflowPoints()[$key]['enforced_note'] ?? null;
    }

    public function hasApproverType(string $key): bool
    {
        return array_key_exists($key, $this->approverTypes());
    }

    public function hasEscalationAction(string $key): bool
    {
        return array_key_exists($key, $this->escalationActions());
    }

    public function approverTypeNeedsValue(string $key): bool
    {
        return (bool) ($this->approverTypes()[$key]['needs_value'] ?? false);
    }

    /**
     * Whether a custom field may be added to this table.
     *
     * The gate that LMS K-12's equivalent does not have. See the config file's note.
     */
    public function allowsCustomFieldTable(string $table): bool
    {
        return array_key_exists($table, $this->customFieldTables());
    }

    /**
     * The whole catalogue, shaped for the frontend.
     *
     * Keys are lifted into the rows so the client never has to reconstruct an address
     * from an object key — `moduleOf` and `componentOf` live on the server, and a
     * client reimplementing them would be a second copy of the addressing rule.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $modules = [];

        foreach ($this->modules() as $key => $module) {
            $modules[] = ['key' => $key] + $module + [
                'counts' => [
                    'components' => count($this->componentsOfModule($key)),
                    'workflows' => count($this->workflowPointsOfModule($key)),
                ],
            ];
        }

        $components = [];

        foreach ($this->components() as $key => $component) {
            $components[] = ['key' => $key, 'module' => self::moduleOf($key)] + $component;
        }

        $workflows = [];

        foreach ($this->workflowPoints() as $key => $point) {
            $workflows[] = [
                'key' => $key,
                'module' => self::moduleOf($key),
                'component' => self::componentOf($key),
                'label' => $point['label'] ?? $key,
                'description' => $point['description'] ?? '',
                'subject' => $point['subject'] ?? 'Record',
                'enforced' => $this->isEnforced($key),
                'enforced_note' => $this->enforcementNote($key),
                'suggested_steps' => $this->normaliseSuggested($point['suggested_steps'] ?? []),
            ];
        }

        return [
            'modules' => $modules,
            'components' => $components,
            'workflows' => $workflows,
            'approver_types' => $this->options($this->approverTypes()),
            'escalation_actions' => $this->options($this->escalationActions()),
            'custom_field_tables' => $this->options($this->customFieldTables()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function options(array $map): array
    {
        $out = [];

        foreach ($map as $key => $value) {
            $out[] = is_array($value)
                ? ['key' => $key] + $value
                : ['key' => $key, 'label' => (string) $value];
        }

        return $out;
    }

    /**
     * Suggested steps, given the same shape a stored step has.
     *
     * The config writes them shorthand — no id, no order, and `approver` omitted where
     * the type derives it. Filling those in here means the screen renders a suggestion
     * and a saved chain with one component instead of two.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseSuggested(array $steps): array
    {
        $out = [];
        $order = 1;

        foreach ($steps as $step) {
            $out[] = [
                'id' => 'sug_' . $order,
                'order' => $order,
                'name' => $step['name'] ?? 'Approval',
                'approver_type' => $step['approver_type'] ?? 'reporting_manager',
                'approver' => (string) ($step['approver'] ?? ''),
                'sla_hours' => (int) ($step['sla_hours'] ?? 0),
                'on_breach' => $step['on_breach'] ?? 'none',
                'allow_delegate' => (bool) ($step['allow_delegate'] ?? true),
                'require_comment' => (bool) ($step['require_comment'] ?? false),
            ];
            $order++;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function componentsOfModule(string $module): array
    {
        return array_filter(
            $this->components(),
            fn ($key) => self::moduleOf($key) === $module,
            ARRAY_FILTER_USE_KEY
        );
    }

    /** @return array<string, mixed> */
    private function workflowPointsOfModule(string $module): array
    {
        return array_filter(
            $this->workflowPoints(),
            fn ($key) => self::moduleOf($key) === $module,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Where the catalogue contradicts itself.
     *
     * Reported beside a working payload rather than thrown — see the class note. Each
     * entry names the offending key and what is wrong with it, because "the registry is
     * inconsistent" without the key is a message that sends somebody reading 300 lines
     * of config.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];
        $modules = $this->modules();
        $components = $this->components();

        foreach ($components as $key => $_) {
            $module = self::moduleOf($key);

            if (! array_key_exists($module, $modules)) {
                $problems[] = "Component '{$key}' names module '{$module}', which is not declared.";
            }
        }

        foreach ($this->workflowPoints() as $key => $point) {
            $component = self::componentOf($key);

            if (! array_key_exists($component, $components)) {
                $problems[] = "Workflow point '{$key}' sits on component '{$component}', which is not declared.";
            }

            /*
             * A point claiming enforcement by a class nobody kept.
             *
             * This is the one problem that would make the console LIE rather than
             * merely be incomplete: the screen would show a green pill and promise
             * a sign-off that the named class can no longer deliver. Worth
             * reporting loudly, and cheap to check.
             */
            $enforcer = $point['enforced_by'] ?? null;

            if ($enforcer !== null && ! class_exists((string) $enforcer)) {
                $problems[] = "Workflow point '{$key}' claims to be enforced by '{$enforcer}', which does not exist.";
            }

            foreach (($point['suggested_steps'] ?? []) as $index => $step) {
                $type = $step['approver_type'] ?? '';

                if (! $this->hasApproverType($type)) {
                    $problems[] = "Workflow point '{$key}' step " . ($index + 1) . " uses approver type '{$type}', which is not declared.";
                    continue;
                }

                if ($this->approverTypeNeedsValue($type) && ($step['approver'] ?? '') === '') {
                    $problems[] = "Workflow point '{$key}' step " . ($index + 1) . " uses '{$type}', which needs a value, but names none.";
                }

                $breach = $step['on_breach'] ?? 'none';

                if (! $this->hasEscalationAction($breach)) {
                    $problems[] = "Workflow point '{$key}' step " . ($index + 1) . " uses breach action '{$breach}', which is not declared.";
                }
            }
        }

        return $problems;
    }
}
