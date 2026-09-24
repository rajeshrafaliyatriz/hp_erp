<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Approval chains, against the workflow points the registry declares.
 *
 * ── A POINT IS NOT A CHAIN ──────────────────────────────────────────────────
 *
 * A POINT is a place in the product where an action can pause for a sign-off. It is
 * ours, declared in `config/platform_services.php`, and an organisation cannot add one.
 * A CHAIN is the ladder of approvers a particular organisation puts at that point. It
 * is theirs, stored in `g2g_platform_workflows`, and there can be several at one point
 * chosen between by `condition`.
 *
 * `index` returns every point with its chains, including the points that have none —
 * because "which of our approvals are ungoverned" is the question an administrator
 * opens this screen to answer, and a list of only the configured ones cannot answer it.
 *
 * ── VALIDATION IS AGAINST THE REGISTRY, NOT A RULE ARRAY ────────────────────
 *
 * `flow_key`, `approver_type` and `on_breach` are checked for membership in the
 * catalogue rather than against a `Rule::in([...])` written here. A list written here
 * would be a second copy of the registry, and the copy that drifts is always the one
 * doing the validating.
 */
class WorkflowController extends PlatformController
{
    private const TABLE = 'g2g_platform_workflows';

    /** A ladder longer than this is a process problem, not a configuration one. */
    private const MAX_STEPS = 12;

    /** 90 days. An SLA longer than this is indistinguishable from none. */
    private const MAX_SLA_HOURS = 2160;

    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly \App\Services\Platform\WorkflowChainSelector $selector,
    ) {
    }

    /**
     * Every workflow point, with the chains this organisation has defined on it.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $module = trim((string) $request->input('module', ''));

            $chains = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->orderBy('flow_key')
                ->orderBy('id')
                ->get()
                ->groupBy('flow_key');

            $points = [];
            $active = 0;
            $draft = 0;
            $governed = 0;
            $total = 0;

            foreach ($this->registry->workflowPoints() as $key => $point) {
                if ($module !== '' && PlatformRegistry::moduleOf($key) !== $module) {
                    continue;
                }

                $rows = ($chains[$key] ?? collect())
                    ->map(function ($row) use ($scope, $key) {
                        /*
                         * Three "active" chains at one point do not all govern it.
                         * Only one is selected, and the console must say which —
                         * otherwise somebody edits the shadowed one and wonders
                         * why nothing changed.
                         */
                        $reason = $this->selector->ineffectiveReason(
                            $scope->selectedInstituteId,
                            $key,
                            $row
                        );

                        return $this->present($row) + [
                            'effective' => $reason === null,
                            'ineffective_reason' => $reason,
                        ];
                    })
                    ->values()
                    ->all();

                foreach ($rows as $row) {
                    $total++;
                    $row['status'] === 'active' ? $active++ : ($row['status'] === 'draft' ? $draft++ : null);
                }

                // GOVERNED COUNTS ACTIVE CHAINS ONLY. A draft governs nothing, and
                // counting it here would produce the flattering number that hides
                // exactly the gap this screen exists to show.
                if (collect($rows)->contains(fn ($row) => $row['status'] === 'active')) {
                    $governed++;
                }

                $points[] = [
                    'key' => $key,
                    'module' => PlatformRegistry::moduleOf($key),
                    'component' => PlatformRegistry::componentOf($key),
                    'label' => $point['label'] ?? $key,
                    'description' => $point['description'] ?? '',
                    'subject' => $point['subject'] ?? 'Record',
                    /*
                     * Whether anything reads a chain saved here.
                     *
                     * The console shows three states rather than two: enforced and
                     * governed, enforced and ungoverned, and declared-but-not-
                     * enforced. Without this it showed the same green pill for all
                     * three and promised sign-offs that do not happen.
                     */
                    'enforced' => $this->registry->isEnforced($key),
                    'enforced_note' => $this->registry->enforcementNote($key),
                    // This point's own suggestion, offered when somebody adds the first
                    // chain here. Never applied on its own.
                    'suggested_steps' => $this->normaliseSuggested($point['suggested_steps'] ?? []),
                    'workflows' => $rows,
                ];
            }

            return $this->success('Workflow points.', [
                'points' => $points,
                'summary' => [
                    'points' => count($points),
                    // Kept meaning exactly what it meant — "has an active chain" —
                    // because renaming a field the frontend already reads is a
                    // silent contract change. The honest numbers are added beside
                    // it rather than in place of it.
                    'governed' => $governed,
                    'workflows' => $total,
                    'active' => $active,
                    'draft' => $draft,
                    // How many points anything actually reads, and how many of
                    // those have a chain. These are the two the screen should lead
                    // with: "1 of 8 points is enforced" is the true statement.
                    'enforced_points' => count(array_filter($points, fn ($p) => $p['enforced'])),
                    'enforced_governed' => count(array_filter(
                        $points,
                        fn ($p) => $p['enforced']
                            && collect($p['workflows'])->contains(fn ($w) => $w['status'] === 'active')
                    )),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $flowKey = trim((string) $request->input('flow_key', ''));

            if (! $this->registry->hasWorkflowPoint($flowKey)) {
                return $this->failure('That is not a workflow point this platform declares.', 422, [
                    'flow_key' => ['Unknown workflow point.'],
                ]);
            }

            $point = $this->registry->workflowPoints()[$flowKey];

            // No steps given means the point's own suggestion, so "add a chain here"
            // produces something usable rather than an empty ladder somebody has to
            // build before they can see what one looks like.
            $steps = $request->has('steps')
                ? $request->input('steps')
                : $this->normaliseSuggested($point['suggested_steps'] ?? []);

            $status = (string) $request->input('status', 'draft');

            $problem = $this->validateChain($steps, $status, $flowKey);

            if ($problem !== null) {
                return $this->failure($problem, 422, ['steps' => [$problem]]);
            }

            $now = now();

            $id = DB::table(self::TABLE)->insertGetId([
                'sub_institute_id' => $scope->selectedInstituteId,
                'flow_key' => $flowKey,
                'module' => PlatformRegistry::moduleOf($flowKey),
                'component' => PlatformRegistry::componentOf($flowKey),
                'name' => $this->text($request, 'name', 150) ?: ($point['label'] ?? $flowKey),
                'description' => $this->text($request, 'description', 1000),
                // DRAFT BY DEFAULT. Nothing starts intercepting real records the moment
                // somebody experiments with a chain.
                'status' => $status,
                'condition' => (string) $request->input('condition', ''),
                'steps' => json_encode($this->normaliseSteps($steps)),
                'on_reject' => $this->rejectMode($request),
                'notify_requester' => (bool) $request->input('notify_requester', true),
                'created_by' => $this->actor($scope),
                'updated_by' => $this->actor($scope),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->success('Workflow created.', ['workflow' => $this->find($scope->selectedInstituteId, $id)], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $row = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('id', $id)
                ->first();

            /*
             * A cross-tenant id reads as 404, never 403.
             *
             * 403 would confirm the row exists and belongs to somebody else, which is a
             * fact about another organisation's configuration. 404 says only that this
             * organisation has no such workflow, which is true.
             */
            if ($row === null) {
                return $this->failure('That workflow no longer exists.', 404);
            }

            $steps = $request->has('steps') ? $request->input('steps') : json_decode($row->steps, true);
            $status = (string) $request->input('status', $row->status);

            $problem = $this->validateChain($steps, $status, $row->flow_key, $row->status === 'active');

            if ($problem !== null) {
                return $this->failure($problem, 422, ['steps' => [$problem]]);
            }

            $values = [
                'steps' => json_encode($this->normaliseSteps($steps)),
                'status' => $status,
                'updated_by' => $this->actor($scope),
                'updated_at' => now(),
            ];

            // Absent means unchanged, not reset. A PATCH-shaped PUT: the screen sends
            // what the person edited, and omitting a field must never blank it.
            if ($request->has('name')) {
                $values['name'] = $this->text($request, 'name', 150) ?: $row->name;
            }

            if ($request->has('description')) {
                $values['description'] = $this->text($request, 'description', 1000);
            }

            if ($request->has('condition')) {
                $values['condition'] = (string) $request->input('condition', '');
            }

            if ($request->has('on_reject')) {
                $values['on_reject'] = $this->rejectMode($request);
            }

            if ($request->has('notify_requester')) {
                $values['notify_requester'] = (bool) $request->input('notify_requester');
            }

            // `flow_key` is deliberately not updatable. Moving a chain to another point
            // is creating a different chain, and allowing it in place would silently
            // change what a stored `condition` is evaluated against.
            DB::table(self::TABLE)->where('id', $id)->update($values);

            return $this->success('Workflow updated.', [
                'workflow' => $this->find($scope->selectedInstituteId, $id),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $deleted = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('id', $id)
                ->delete();

            if ($deleted === 0) {
                return $this->failure('That workflow no longer exists.', 404);
            }

            return $this->success('Workflow deleted.', ['deleted' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Whether a chain is coherent, as a sentence an administrator can act on.
     *
     * Returns null when it is fine. The messages are written as instructions rather
     * than as field errors — "Step 3: set an SLA, or there is nothing for Escalate to
     * happen after" says what to do; "steps.2.sla_hours is invalid" does not.
     */
    private function validateChain(mixed $steps, string $status, string $flowKey, bool $wasActive = false): ?string
    {
        if (! in_array($status, ['draft', 'active', 'disabled'], true)) {
            return 'A workflow is draft, active or disabled.';
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * A CHAIN CANNOT BE ACTIVATED AT A POINT NOTHING READS
         * ═══════════════════════════════════════════════════════════════════
         *
         * This is the strongest of the honesty measures, because it stops the
         * false claim at the source instead of apologising for it on screen. An
         * active chain at an unenforced point would sit there looking like a
         * control, and the first person to find out it is not would be whoever
         * expected an approval that never happened.
         *
         * Only the transition INTO active is refused. An already-active chain —
         * one saved before this rule existed — must stay editable and, above all,
         * deactivatable: refusing every save would leave a tenant unable to clean
         * up the very rows this rule exists to prevent.
         */
        if ($status === 'active' && ! $wasActive && ! $this->registry->isEnforced($flowKey)) {
            return 'Nothing in the product reads this point yet, so an active chain here would '
                . 'claim a sign-off that will not happen. Save it as a draft instead.';
        }

        if (! is_array($steps)) {
            return 'The steps must be a list.';
        }

        if (count($steps) > self::MAX_STEPS) {
            return 'A chain can have at most ' . self::MAX_STEPS . ' steps.';
        }

        /*
         * AN ACTIVE CHAIN WITH NO STEPS IS REFUSED HOWEVER IT IS REACHED.
         *
         * Both by emptying the steps of an active chain and by activating an empty one.
         * Such a chain would intercept every matching record and have nobody to send it
         * to, which is an approval that can never complete — the worst failure this
         * screen can configure, and the easiest to create by accident.
         */
        if ($status === 'active' && count($steps) === 0) {
            return 'An active workflow needs at least one step, or nothing can ever approve it.';
        }

        foreach (array_values($steps) as $index => $step) {
            $position = $index + 1;

            if (! is_array($step)) {
                return "Step {$position} is not a step.";
            }

            $name = trim((string) ($step['name'] ?? ''));

            if ($name === '') {
                return "Step {$position} needs a name.";
            }

            if (mb_strlen($name) > 120) {
                return "Step {$position}'s name is too long.";
            }

            $type = (string) ($step['approver_type'] ?? '');

            if (! $this->registry->hasApproverType($type)) {
                return "Step {$position} uses an approver type this platform does not offer.";
            }

            if ($this->registry->approverTypeNeedsValue($type) && trim((string) ($step['approver'] ?? '')) === '') {
                return "Step {$position} needs somebody named, because its approver type is not resolved from the record.";
            }

            $sla = (int) ($step['sla_hours'] ?? 0);

            if ($sla < 0 || $sla > self::MAX_SLA_HOURS) {
                return "Step {$position}'s SLA must be between 0 and " . self::MAX_SLA_HOURS . ' hours.';
            }

            $breach = (string) ($step['on_breach'] ?? 'none');

            if (! $this->registry->hasEscalationAction($breach)) {
                return "Step {$position} uses a breach action this platform does not offer.";
            }

            if ($breach !== 'none' && $sla === 0) {
                return "Step {$position}: set an SLA, or there is nothing for its breach action to happen after.";
            }
        }

        return null;
    }

    /**
     * Steps as they are stored: server-assigned id and order, derived approvers blanked.
     *
     * A client-supplied `order` is IGNORED and recomputed from array position. Two
     * sources of ordering is one too many, and the one that wins should be the one the
     * operator actually arranged on screen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseSteps(array $steps): array
    {
        $out = [];
        $order = 1;

        foreach (array_values($steps) as $step) {
            $type = (string) $step['approver_type'];

            $out[] = [
                'id' => (string) ($step['id'] ?? ('stp_' . bin2hex(random_bytes(5)))),
                'order' => $order,
                'name' => trim((string) $step['name']),
                'approver_type' => $type,
                // Blanked for the types the engine resolves from the record. Storing a
                // value the engine will never read is storing a lie the screen shows.
                'approver' => $this->registry->approverTypeNeedsValue($type)
                    ? trim((string) ($step['approver'] ?? ''))
                    : '',
                'sla_hours' => (int) ($step['sla_hours'] ?? 0),
                'on_breach' => (string) ($step['on_breach'] ?? 'none'),
                'allow_delegate' => (bool) ($step['allow_delegate'] ?? true),
                'require_comment' => (bool) ($step['require_comment'] ?? false),
            ];

            $order++;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
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

    /** @return array<string, mixed>|null */
    private function find(int $tenantId, int $id): ?array
    {
        $row = DB::table(self::TABLE)->where('sub_institute_id', $tenantId)->where('id', $id)->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $steps = json_decode($row->steps, true) ?: [];

        return [
            'id' => (int) $row->id,
            'flow_key' => $row->flow_key,
            'module' => $row->module,
            'component' => $row->component,
            'name' => $row->name,
            'description' => $row->description,
            'status' => $row->status,
            'condition' => $row->condition,
            'steps' => $steps,
            'step_count' => count($steps),
            // The question a manager actually asks: how long can this take at worst?
            'total_sla_hours' => array_sum(array_map(fn ($s) => (int) ($s['sla_hours'] ?? 0), $steps)),
            'on_reject' => $row->on_reject,
            'notify_requester' => (bool) $row->notify_requester,
            'created_at' => $row->created_at,
            'created_by' => $row->created_by,
            'updated_at' => $row->updated_at,
            'updated_by' => $row->updated_by,
        ];
    }

    private function rejectMode(Request $request): string
    {
        $value = (string) $request->input('on_reject', 'return_to_requester');

        return in_array($value, ['return_to_requester', 'close'], true) ? $value : 'return_to_requester';
    }

    private function text(Request $request, string $key, int $max): ?string
    {
        $value = trim((string) $request->input($key, ''));

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * "Name (id)", denormalised at write time.
     *
     * An id alone stops resolving the day the person is deactivated, and an audit column
     * that cannot be read is not one.
     */
    private function actor(\App\Services\Ai\AiRequestScope $scope): string
    {
        $name = DB::table('tbluser')->where('id', $scope->userId)->value('first_name');

        return trim(((string) $name) . ' (' . $scope->userId . ')');
    }
}
