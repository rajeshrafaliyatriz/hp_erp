<?php

namespace App\Services\Leave;

use App\Models\HRMS\HrmsLeaveWorkflowSetting;
use App\Services\Platform\WorkflowChainSelector;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The approval chain. F-124.
 *
 * hrms_leave_workflow_settings was the last configuration table in this module
 * that controlled nothing: three live rows, a working screen that saved and
 * reloaded them, and no read anywhere in the product. One approval from anyone
 * holding approve_leave decided the request, whatever the tenant had configured.
 *
 * This service is the only thing that reads those settings, and everything that
 * decides a leave request goes through it.
 *
 * HOW THE SCREEN'S SWITCHES BECOME A CHAIN
 *
 *   reporting_manager_enabled ─┐
 *   department_head_enabled   ─┼─> the ordered candidate list
 *   hr_enabled                ─┘
 *
 *   multi_level_enabled = false  ->  ONE step: the first enabled role.
 *                                    Which is what "multi-level approval: off"
 *                                    means on the screen.
 *   multi_level_enabled = true   ->  the first `multi_level_count` enabled roles.
 *
 * Nothing enabled at all falls back to a single `hr` step. A tenant that
 * switches every approver off has misconfigured itself; the safe reading is
 * "HR decides", never "it approves itself" and never "it can never be approved".
 *
 * ESCALATION widens, it does not reassign. When a step has been pending longer
 * than escalation_time, escalate_to may decide it *as well as* the assigned
 * role - the department head coming back from leave can still approve their own
 * step. Reassigning would silently take work away from the person it was
 * waiting on, and nothing on the screen says it does that.
 */
class LeaveApprovalWorkflow
{
    /** The chain's fixed order. The screen lists them this way and so does the product. */
    private const CHAIN_ORDER = ['reporting_manager', 'department_head', 'hr'];

    /**
     * The workflow point this module is governed by, as declared in
     * `config/platform_services.php`.
     *
     * This is the ONE point in that catalogue that is actually enforced. The other
     * seven are declared and read by nothing, which is why the console marks them
     * as such rather than showing them all the same way.
     */
    public const LEAVE_FLOW_KEY = 'hrms.leave.approval';

    /**
     * A chain role -> the role_keys that may decide its step.
     *
     * `hr` covers both HR keys for the same reason RoleKey::ALIASES does: the
     * screen offers one "HR" switch, and both hr_manager and hr_executive are HR.
     */
    private const ROLE_KEYS = [
        'reporting_manager' => ['reporting_manager'],
        'department_head'   => ['department_head'],
        'hr'                => ['hr_manager', 'hr_executive'],
        'administrator'     => ['administrator'],
    ];

    /**
     * What the Escalate-To dropdown actually posts -> the chain role it means.
     *
     * The screen's option values are 'department-head', 'hr' and 'admin'
     * (ApprovalWorkflowTab.tsx, escalateToOptions), which is a third spelling
     * of the same three roles - the switches above it use department_head, and
     * role_key uses department_head too. Left unmapped, escalating to
     * "department-head" stamps a step that NOBODY can then decide, because no
     * role_key matches it: the escalation would look like it worked and quietly
     * strand the request.
     *
     * Normalised here rather than in the screen, because the three live rows
     * already in hrms_leave_workflow_settings were written by the old screen
     * and cannot be re-spelled retroactively.
     */
    private const ESCALATE_ALIASES = [
        'department-head'   => 'department_head',
        'departmenthead'    => 'department_head',
        'reporting-manager' => 'reporting_manager',
        'admin'             => 'administrator',
        'administrator'     => 'administrator',
        'hr'                => 'hr',
    ];

    /** The chain role an escalate_to value means, or null if it names nothing. */
    public static function normaliseEscalateTo(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = strtolower(trim($value));

        if (isset(self::ESCALATE_ALIASES[$key])) {
            return self::ESCALATE_ALIASES[$key];
        }

        return isset(self::ROLE_KEYS[$key]) ? $key : null;
    }

    /** Steps nobody is being asked to act on. */
    public const OPEN_STATUSES = ['pending', 'waiting'];

    /**
     * The chain a tenant has configured right now, as ordered step descriptors.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * TWO SOURCES, ONE SHAPE
     * ═══════════════════════════════════════════════════════════════════════
     *
     * A platform chain on `hrms.leave.approval` wins when one is in force; this
     * module's own `hrms_leave_workflow_settings` answers otherwise, exactly as it
     * always has. A tenant that has never opened the platform console sees no
     * change at all — every field a platform step adds comes back null, and every
     * reader below falls through to today's behaviour on null.
     *
     * `openFor()` is the ONLY production caller, so the richer return shape costs
     * nothing elsewhere. `rolesOf()` gives the flat list of roles that the rest of
     * the product still speaks.
     *
     * @return array<int, array{
     *   approver_role: string, approver_user_id: ?int, approver_user_name: ?string,
     *   step_name: ?string, sla_hours: ?int, on_breach: ?string,
     *   require_comment: bool, source: string, workflow_id: ?int
     * }>
     */
    public function chainFor(int $subInstituteId): array
    {
        $platform = $this->platformChainFor($subInstituteId);

        if ($platform !== null) {
            return $platform;
        }

        return array_map(
            fn (string $role) => self::settingsStep($role),
            $this->settingsChainFor($subInstituteId)
        );
    }

    /** Just the roles, in order — the flat shape everything outside this class speaks. */
    public static function rolesOf(array $chain): array
    {
        return array_column($chain, 'approver_role');
    }

    /**
     * A step descriptor for the settings path.
     *
     * Everything a platform step carries is null here, and that is what routes each
     * reader back to its existing behaviour: no per-step SLA means the tenant-wide
     * escalation clock, no breach action means the tenant-wide escalate_to, and no
     * named user means the role decides.
     */
    private static function settingsStep(string $role): array
    {
        return [
            'approver_role'      => $role,
            'approver_user_id'   => null,
            'approver_user_name' => null,
            'step_name'          => null,
            'sla_hours'          => null,
            'on_breach'          => null,
            'require_comment'    => false,
            'source'             => 'settings',
            'workflow_id'        => null,
        ];
    }

    /**
     * The chain from `hrms_leave_workflow_settings`. Behaviour unchanged.
     *
     * @return array<int, string>
     */
    private function settingsChainFor(int $subInstituteId): array
    {
        $settings = HrmsLeaveWorkflowSetting::where('sub_institute_id', $subInstituteId)->first();

        $values = $settings
            ? $settings->only(array_keys(HrmsLeaveWorkflowSetting::defaults()))
            : HrmsLeaveWorkflowSetting::defaults();

        $enabled = [];
        foreach (self::CHAIN_ORDER as $role) {
            $flag = $role === 'hr' ? 'hr_enabled' : $role . '_enabled';
            if (!empty($values[$flag])) {
                $enabled[] = $role;
            }
        }

        if ($enabled === []) {
            return ['hr'];
        }

        if (empty($values['multi_level_enabled'])) {
            return [$enabled[0]];
        }

        $count = max(1, (int) ($values['multi_level_count'] ?? 1));

        return array_slice($enabled, 0, $count);
    }

    /**
     * The platform chain, translated — or null to fall back to the settings path.
     *
     * ── IT IS ALL OR NOTHING ────────────────────────────────────────────────
     *
     * A single step that cannot be translated abandons the WHOLE chain. A
     * half-translated ladder is worse than either source: it is a chain nobody
     * configured, and the person who configured the real one has no way to see
     * that it was not the one being used. Falling back is at least a chain
     * somebody chose, and the log line says which chain was skipped and why.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function platformChainFor(int $subInstituteId): ?array
    {
        $chain = app(WorkflowChainSelector::class)
            ->select($subInstituteId, self::LEAVE_FLOW_KEY)['chain'];

        if ($chain === null) {
            return null;
        }

        $steps = json_decode((string) $chain->steps, true);

        if (!is_array($steps) || $steps === []) {
            return null;
        }

        $out = [];

        foreach ($steps as $step) {
            $translated = is_array($step)
                ? $this->translateStep($step, $subInstituteId, (int) $chain->id)
                : null;

            if ($translated === null) {
                Log::warning('leave.platform_chain.untranslatable', [
                    'sub_institute_id' => $subInstituteId,
                    'workflow_id'      => (int) $chain->id,
                    'step'             => is_array($step) ? ($step['name'] ?? null) : null,
                    'approver_type'    => is_array($step) ? ($step['approver_type'] ?? null) : null,
                ]);

                return null;
            }

            $out[] = $translated;
        }

        return $out;
    }

    /**
     * One platform step as a chain step, or null if it cannot be honoured.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * A ROLE KEY IS STORED VERBATIM AND NEVER COLLAPSED TO `hr`
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `ROLE_KEYS['hr']` is `['hr_manager', 'hr_executive']`, and its own note
     * explains why: the LEAVE screen offers a single "HR" switch, so both keys are
     * HR there.
     *
     * The platform console does not. It offers a role-key picker, and somebody who
     * chose `hr_manager` did not choose `hr_executive`. Mapping the platform's
     * `hr_manager` onto the chain role `hr` would silently hand approval rights to
     * a second role nobody selected — a widening of access disguised as a tidy-up.
     * So the role key is written as itself, and `roleKeysFor()` resolves it to
     * exactly that one key.
     *
     * @return array<string, mixed>|null
     */
    private function translateStep(array $step, int $subInstituteId, int $workflowId): ?array
    {
        $type = (string) ($step['approver_type'] ?? '');

        $common = [
            'step_name'       => trim((string) ($step['name'] ?? '')) ?: null,
            'sla_hours'       => (int) ($step['sla_hours'] ?? 0),
            'on_breach'       => (string) ($step['on_breach'] ?? 'none'),
            'require_comment' => (bool) ($step['require_comment'] ?? false),
            'source'          => 'platform',
            'workflow_id'     => $workflowId,
        ];

        if ($type === 'reporting_manager' || $type === 'department_head') {
            return $common + [
                'approver_role'      => $type,
                'approver_user_id'   => null,
                'approver_user_name' => null,
            ];
        }

        if ($type === 'role') {
            $roleKey = strtolower(trim((string) ($step['approver'] ?? '')));

            // A role this platform does not define is a dead end, not a step: the
            // administrator escape hatch would be the only way past it, which is a
            // lockout rather than a chain.
            if ($roleKey === '' || !in_array($roleKey, RoleKey::ALL, true)) {
                return null;
            }

            return $common + [
                'approver_role'      => $roleKey,
                'approver_user_id'   => null,
                'approver_user_name' => null,
            ];
        }

        if ($type === 'user') {
            $userId = (int) ($step['approver'] ?? 0);

            if ($userId <= 0) {
                return null;
            }

            /*
             * The person must be ACTIVE and IN THIS TENANT.
             *
             * Without the tenant check a chain could name somebody in another
             * organisation and hand them a decision about an employee they have no
             * relationship with. Without the active check the step is decidable by
             * nobody but an administrator, which is a stranded request.
             */
            $user = DB::table('tbluser')
                ->where('id', $userId)
                ->where('sub_institute_id', $subInstituteId)
                ->where('status', 1)
                ->first(['id', 'first_name', 'middle_name', 'last_name']);

            if ($user === null) {
                return null;
            }

            $name = trim(implode(' ', array_filter([
                trim((string) $user->first_name),
                trim((string) $user->middle_name),
                trim((string) $user->last_name),
            ])));

            /*
             * `approver_role` is STILL written, and it is not decoration.
             *
             * `LeaveRequestApiController` reads the chain as
             * `array_column(stepsFor($id), 'approver_role')` in two places, and a
             * null there would produce a chain with holes in it. The sentinel
             * 'user' says "this step is a person"; `roleMayDecide()` then reads
             * `approver_user_id` to decide which person.
             */
            return $common + [
                'approver_role'      => 'user',
                'approver_user_id'   => $userId,
                'approver_user_name' => $name !== '' ? $name : ('User #' . $userId),
            ];
        }

        return null;
    }

    /**
     * Create the steps for a newly submitted request.
     *
     * The chain is FROZEN here. If HR changes the configuration tomorrow,
     * requests already in flight keep the chain they entered under - changing
     * the rules must not retroactively approve or strand anything.
     */
    public function openFor(int $leaveId, int $subInstituteId): array
    {
        $chain = $this->chainFor($subInstituteId);
        $now   = now();
        $rows  = [];

        foreach ($chain as $index => $step) {
            $rows[] = [
                'leave_id'         => $leaveId,
                'sub_institute_id' => $subInstituteId,
                'step_order'       => $index + 1,
                'approver_role'    => $step['approver_role'],
                // Only the first step is anyone's to act on. The rest wait their turn,
                // which is what stops step 2 being approved before step 1.
                'status'           => $index === 0 ? 'pending' : 'waiting',
                'pending_since'    => $index === 0 ? $now : null,

                /*
                 * THE FROZEN RULE.
                 *
                 * Copied onto the row and never refreshed. A platform chain can be
                 * EDITED OR DELETED while this request is still in flight —
                 * `WorkflowController::destroy()` is a hard delete — so a step that
                 * resolved its rule by looking the chain up would lose it, and the
                 * request would become undecidable. Everything the engine needs
                 * later travels here.
                 */
                'approver_user_id'   => $step['approver_user_id'],
                'approver_user_name' => $step['approver_user_name'],
                'step_name'          => $step['step_name'],
                'sla_hours'          => $step['sla_hours'],
                'on_breach'          => $step['on_breach'],
                'require_comment'    => $step['require_comment'],
                'source'             => $step['source'],
                'workflow_id'        => $step['workflow_id'],

                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        DB::table('hrms_leave_approval_steps')->insert($rows);

        /*
         * The FLAT list of roles, deliberately.
         *
         * `LeaveRequestApiController` stores this as the request's chain and
         * `LeaveNotifier::submitted()` takes its `count()`. Returning the richer
         * descriptors here would change what both of those mean, for no gain —
         * the rich data is already on the rows, which is where every later reader
         * takes it from.
         */
        return self::rolesOf($chain);
    }

    /**
     * Put a sent-back request back at the top of its chain.
     *
     * recordDecision() already returned steps 2..n to 'waiting' when it sent the
     * request back; this returns step 1 to 'pending' so the amended request is
     * in front of its first approver again. The chain itself is NOT rebuilt -
     * the request keeps the one it was submitted under, for the same reason
     * openFor() freezes it.
     */
    public function reopenFor(int $leaveId): int
    {
        $first = DB::table('hrms_leave_approval_steps')
            ->where('leave_id', $leaveId)
            ->orderBy('step_order')
            ->first();

        if (!$first) {
            return 0;
        }

        $now = now();

        DB::table('hrms_leave_approval_steps')
            ->where('leave_id', $leaveId)
            ->where('step_order', '>', 1)
            ->update([
                'status'        => 'waiting',
                'decision'      => null,
                'approver_id'   => null,
                'approver_name' => null,
                'comment'       => null,
                'decided_at'    => null,
                'pending_since' => null,
                'escalated_at'  => null,
                'escalated_to'  => null,
                'updated_at'    => $now,
            ]);

        return DB::table('hrms_leave_approval_steps')
            ->where('id', $first->id)
            ->update([
                'status'        => 'pending',
                'decision'      => null,
                'approver_id'   => null,
                'approver_name' => null,
                'comment'       => null,
                'decided_at'    => null,
                // The clock restarts. The employee has only just resubmitted, so
                // the approver has not been keeping anybody waiting yet.
                'pending_since' => $now,
                'escalated_at'  => null,
                'escalated_to'  => null,
                'updated_at'    => $now,
            ]);
    }

    /** Every step on a request, in order. */
    public function stepsFor(int $leaveId): array
    {
        return DB::table('hrms_leave_approval_steps')
            ->where('leave_id', $leaveId)
            ->orderBy('step_order')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** The step currently awaiting a decision, or null if the chain is finished. */
    public function currentStep(int $leaveId): ?array
    {
        $row = DB::table('hrms_leave_approval_steps')
            ->where('leave_id', $leaveId)
            ->where('status', 'pending')
            ->orderBy('step_order')
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * May this role decide this step?
     *
     * Administrator always may. That is the same deliberate escape hatch
     * ResolvesLeaveAuthority documents for configure_settings: a tenant that
     * enables a chain of roles nobody holds must not be locked out of its own
     * leave queue, and an administrator is who unlocks it.
     */
    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        if ($roleKey === null) {
            return false;
        }

        if ($roleKey === 'administrator') {
            return true;
        }

        /*
         * A STEP THAT NAMES A PERSON IS DECIDED BY THAT PERSON.
         *
         * Their role is irrelevant — the tenant named them, not their job title —
         * so this branch replaces the role check rather than adding to it.
         * Somebody holding the same role as the named person may NOT decide it.
         *
         * `$userId` is nullable so nothing fails to compile, but both call sites in
         * `LeaveRequestApiController` pass it. If a future caller forgets, the step
         * becomes decidable only by an administrator — restrictive, which is the
         * safe direction for a mistake of this kind to fail in.
         */
        if (!empty($step['approver_user_id'])) {
            if ($userId !== null && (int) $step['approver_user_id'] === $userId) {
                return true;
            }
        } elseif (in_array($roleKey, self::roleKeysFor((string) $step['approver_role']), true)) {
            return true;
        }

        // Escalated: escalate_to may now decide it too. The assigned role — or the
        // named person — keeps its right to decide: escalation widens, it does not
        // reassign. Reachable from both branches above for exactly that reason.
        if (!empty($step['escalated_at']) && !empty($step['escalated_to'])) {
            return in_array($roleKey, self::roleKeysFor((string) $step['escalated_to']), true);
        }

        return false;
    }

    /**
     * Record one approver's decision and say what it means for the request.
     *
     * Returns:
     *   final   bool    whether the request's own status should change now
     *   status  string  what it should change to, when final
     *   step    int     which step was just decided
     *   of      int     how many steps the chain has
     *   next    ?string the role now being waited on, when not final
     */
    public function recordDecision(int $leaveId, array $step, string $decision, array $context, ?string $comment = null): array
    {
        $steps = $this->stepsFor($leaveId);
        $total = count($steps);
        $now   = now();

        /*
         * AN AUTO-DECISION MUST NEVER BE MISTAKABLE FOR A HUMAN ONE.
         *
         * When the SLA sweeper decides a step, `auto_reason` carries why. The
         * approver id stays NULL — nobody decided it — and the name says so in
         * words, because a timeline showing an approval next to a person's name is
         * a claim that person made it.
         *
         * Without this branch the lookup below would produce "User #0" against a
         * null id, which reads like a data fault rather than a system action.
         */
        $autoReason = $context['auto_reason'] ?? null;

        $approverName = $autoReason !== null
            ? 'System — SLA rule'
            : (DB::table('tbluser')
                ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS employee_name")
                ->where('id', $context['user_id'])
                ->value('employee_name') ?: 'User #' . $context['user_id']);

        $approved = in_array($decision, ['approved', 'approved_lwp'], true);

        // 'sent_back' is not a refusal. It returns the request to the employee
        // to amend and re-submit, which means the chain must SURVIVE and restart
        // - not be destroyed. The first version of this method treated anything
        // that was not an approval as a rejection and skipped every remaining
        // step, so a sent-back request could never be approved again: its chain
        // was closed and nothing reopened it. Found by review, not in testing,
        // because no probe exercised sent_back.
        $sentBack = $decision === 'sent_back';

        return DB::transaction(function () use (
            $leaveId, $step, $decision, $context, $comment, $steps, $total, $now,
            $approverName, $approved, $sentBack, $autoReason
        ) {
            /*
             * The `where('status', 'pending')` here is the whole concurrency
             * story, and it was missing.
             *
             * Two approvers (or one approver double-clicking) could both read
             * the same pending step and both write it. The second write's
             * "is anything still waiting?" lookup would miss - the first had
             * already promoted the next step - so it returned final=true and the
             * request was marked approved with a later step still pending, in
             * somebody's queue for ever, and still being chased by the
             * escalation sweep.
             *
             * Claiming the step by predicate makes exactly one caller win. The
             * loser is told to reload rather than silently clobbering the
             * winner's approver_id, comment and timestamp.
             */
            $claimed = DB::table('hrms_leave_approval_steps')
                ->where('id', $step['id'])
                ->where('status', 'pending')
                ->update([
                    'status'        => $approved ? 'approved' : ($sentBack ? 'sent_back' : 'rejected'),
                    'decision'      => $decision,
                    // NULL on an auto-decision: nobody decided it, and putting an
                    // id here would attribute it to somebody who did not.
                    'approver_id'   => $autoReason !== null ? null : $context['user_id'],
                    'approver_name' => $approverName,
                    'auto_decided_reason' => $autoReason,
                    'comment'       => ($comment !== null && $comment !== '') ? $comment : null,
                    'decided_at'    => $now,
                    'updated_at'    => $now,
                ]);

            if ($claimed === 0) {
                return [
                    'final'    => false,
                    'conflict' => true,
                    'status'   => 'pending',
                    'step'     => (int) $step['step_order'],
                    'of'       => $total,
                    'next'     => null,
                ];
            }

            if ($sentBack) {
                // Back to the start. Every step returns to its opening state so
                // the amended request walks the same chain again from step 1.
                DB::table('hrms_leave_approval_steps')
                    ->where('leave_id', $leaveId)
                    ->where('step_order', '>', 1)
                    ->update([
                        'status'        => 'waiting',
                        'decision'      => null,
                        'approver_id'   => null,
                        'approver_name' => null,
                        'comment'       => null,
                        'decided_at'    => null,
                        'pending_since' => null,
                        'escalated_at'  => null,
                        'escalated_to'  => null,
                        'updated_at'    => $now,
                    ]);

                return [
                    'final'  => true,
                    'status' => $decision,
                    'step'   => (int) $step['step_order'],
                    'of'     => $total,
                    'next'   => null,
                ];
            }

            // A rejection ends the chain. There is nothing for a later approver
            // to add to a request that has been refused, and leaving their step
            // 'pending' would keep it in their queue for ever.
            if (!$approved) {
                DB::table('hrms_leave_approval_steps')
                    ->where('leave_id', $leaveId)
                    ->whereIn('status', self::OPEN_STATUSES)
                    ->update(['status' => 'skipped', 'updated_at' => $now]);

                return [
                    'final'  => true,
                    'status' => $decision,
                    'step'   => (int) $step['step_order'],
                    'of'     => $total,
                    'next'   => null,
                ];
            }

            $next = DB::table('hrms_leave_approval_steps')
                ->where('leave_id', $leaveId)
                ->where('status', 'waiting')
                ->orderBy('step_order')
                ->first();

            if (!$next) {
                return [
                    'final'  => true,
                    'status' => $decision,
                    'step'   => (int) $step['step_order'],
                    'of'     => $total,
                    'next'   => null,
                ];
            }

            // Hand the request on. pending_since restarts here, so the next
            // approver's escalation clock measures their own wait and not the
            // previous approver's.
            DB::table('hrms_leave_approval_steps')
                ->where('id', $next->id)
                ->update(['status' => 'pending', 'pending_since' => $now, 'updated_at' => $now]);

            return [
                'final'  => false,
                'status' => 'pending',
                'step'   => (int) $step['step_order'],
                'of'     => $total,
                'next'   => $next->approver_role,
            ];
        });
    }

    /**
     * What a breached step's `on_breach` actually does.
     *
     * Returns the row the command reports, or null when nothing was done.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * `remind` AND `escalate` ARE DIFFERENT THINGS, AND THE STAMP SAYS WHICH
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `escalated_at` does not mean "we chased this" — it means the escalation
     * target MAY NOW DECIDE IT, because `roleMayDecide()` reads it. So a reminder
     * must never set it: a nudge that quietly hands somebody else the right to
     * approve is an escalation wearing the wrong label. `reminded_at` is its own
     * one-shot, and it exists so the hourly sweep does not send the same reminder
     * every hour for as long as the step stays open.
     */
    private function applyBreach(object $step, string $action, ?string $target, $setting, $now): ?array
    {
        $base = [
            'step_id'          => (int) $step->id,
            'leave_id'         => (int) $step->leave_id,
            'sub_institute_id' => (int) $step->sub_institute_id,
            'from'             => $step->approver_role,
            'waiting_since'    => $step->pending_since,
            'to'               => null,
            'action'           => $action,
        ];

        if ($action === 'none') {
            return null;
        }

        if ($action === 'remind') {
            // One-shot. Already reminded means nothing more to do.
            if ($step->reminded_at !== null) {
                return null;
            }

            DB::table('hrms_leave_approval_steps')
                ->where('id', $step->id)
                ->whereNull('reminded_at')
                ->update(['reminded_at' => $now, 'updated_at' => $now]);

            return $base;
        }

        if ($action === 'escalate') {
            // One-shot, and it widens who may decide — so it must not fire twice.
            if ($step->escalated_at !== null) {
                return null;
            }

            /*
             * "Escalate" promises the NEXT step up. The tenant-wide `escalate_to`
             * is the fallback for the last step in a chain, and the only target a
             * settings-path step has ever had.
             */
            $next = DB::table('hrms_leave_approval_steps')
                ->where('leave_id', $step->leave_id)
                ->where('step_order', '>', $step->step_order)
                ->orderBy('step_order')
                ->value('approver_role');

            // A named-person step is nobody's role, so it cannot be an escalation
            // target — `roleKeysFor('user')` is empty and nobody would gain anything.
            $to = ($next !== null && $next !== 'user') ? $next : $target;

            if ($to === null) {
                return null;
            }

            /*
             * Escalating to a role that already owns the step changes nothing and
             * would still burn the one-shot. Compared as SETS, because
             * `hr_manager` escalating to `hr` is a no-op that a string comparison
             * would wave through.
             */
            $owns = self::roleKeysFor((string) $step->approver_role);
            $gets = self::roleKeysFor((string) $to);

            if ($gets === [] || array_diff($gets, $owns) === []) {
                return null;
            }

            DB::table('hrms_leave_approval_steps')
                ->where('id', $step->id)
                ->whereNull('escalated_at')
                ->update(['escalated_at' => $now, 'escalated_to' => $to, 'updated_at' => $now]);

            return array_merge($base, ['to' => $to]);
        }

        if ($action === 'auto_approve' || $action === 'auto_reject') {
            return $this->autoDecide($step, $action, $now, $base);
        }

        return null;
    }

    /**
     * Decide a step because its SLA passed and nobody acted.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THIS IS THE ONLY PLACE IN G2G THAT SETTLES A REQUEST NOBODY READ
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Everything else about leave escalation WIDENS who may decide and never
     * decides. This does, because a tenant asked for it in the console — so it
     * carries the obligations that come with that:
     *
     *   - it goes through `recordDecision()`, so the `where('status','pending')`
     *     claim arbitrates a race with a human approver who is deciding at the
     *     same moment, and exactly one of them wins;
     *   - the decision is attributed to SYSTEM with the rule that fired, never to
     *     a person;
     *   - it refuses a step that demands a comment, because a justification
     *     requirement cannot be satisfied by nobody writing one;
     *   - it is behind a kill switch that defaults OFF, so twelve live tenants do
     *     not begin auto-approving leave on the day this deploys because somebody
     *     saved a chain to see what the screen did.
     *
     * When the switch is off the step is left alone and reported as `skipped`, so
     * the command's output says what WOULD have happened rather than silently
     * doing nothing.
     */
    private function autoDecide(object $step, string $action, $now, array $base): ?array
    {
        if (! config('leave.auto_decisions_enabled', false)) {
            return array_merge($base, ['action' => 'auto_skipped', 'note' => 'auto-decisions are switched off']);
        }

        // A step configured to demand a justification cannot be auto-decided:
        // there would be no justification, which is the one thing it asked for.
        if (! empty($step->require_comment)) {
            return array_merge($base, ['action' => 'auto_skipped', 'note' => 'step requires a comment']);
        }

        $hours = (int) $step->sla_hours;

        // Belt and braces. `WorkflowController::validateChain()` already refuses a
        // breach action with no SLA, but this decides somebody's leave — it does
        // not get to trust a check made in another file at another time.
        if ($hours <= 0) {
            return null;
        }

        $decision = $action === 'auto_approve' ? 'approved' : 'rejected';
        $reason = sprintf('No response within %dh — decided by the SLA rule on this step.', $hours);

        $progress = $this->recordDecision(
            (int) $step->leave_id,
            (array) $step,
            $decision,
            [
                'user_id'          => null,
                'sub_institute_id' => (int) $step->sub_institute_id,
                'auto_reason'      => $reason,
            ]
        );

        // Lost the race to a human approver who decided in the same moment. Their
        // decision stands; this reports nothing rather than claiming a decision.
        if (! empty($progress['conflict'])) {
            return null;
        }

        return array_merge($base, [
            'to'       => null,
            'decision' => $decision,
            'final'    => (bool) ($progress['final'] ?? false),
            'note'     => $reason,
        ]);
    }

    /** Close every open step - the request was withdrawn or cancelled. */
    public function closeOpenSteps(int $leaveId): int
    {
        return DB::table('hrms_leave_approval_steps')
            ->where('leave_id', $leaveId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    /**
     * Stamp every pending step that has waited longer than its tenant allows.
     *
     * Called by `leave:escalate`. Returns one row per escalated step so the
     * command can report what it did rather than just a count.
     */
    public function escalateOverdue(?int $onlyTenant = null): array
    {
        /*
         * Iterate the tenants that HAVE PENDING STEPS, not the tenants that have
         * a settings row.
         *
         * chainFor() falls back to HrmsLeaveWorkflowSetting::defaults() when a
         * tenant has never saved its workflow, so those tenants get a real chain
         * - and the first version of this sweep only looked at the three rows in
         * hrms_leave_workflow_settings, so their requests could never escalate.
         * A default that applies when building the chain and not when enforcing
         * it is worse than no default: it looks configured and is not.
         *
         * The defaults have escalation_enabled = true, so this is not a
         * theoretical gap for them.
         */
        /*
         * F-134. `when($onlyTenant, ...)` and not `when($onlyTenant !== null, ...)`.
         *
         * Laravel's when() skips its closure on any FALSY value, so a tenant id
         * of 0 silently removed the filter and swept every organisation. The
         * caller no longer produces a 0 - it refuses the input - but the guard
         * belongs here too: this method is public, and the next caller will not
         * know that its safety depends on a cast two files away.
         *
         * `!== null` says what is meant: "no tenant given" is the only thing
         * that means "all tenants".
         */
        /*
         * `whereNull('escalated_at')` HAS BEEN DROPPED FROM THIS DISCOVERY QUERY.
         *
         * It was correct when escalation was the only thing the sweep did. It is
         * not any more: a step whose breach action is `remind` is tracked by
         * `reminded_at`, and one that auto-decides is claimed by its own status
         * change. Leaving the filter here would mean a tenant whose only overdue
         * step needs a REMINDER is never even visited, and the reminder would
         * never fire — a bug that looks exactly like the feature not being built.
         */
        $tenantIds = DB::table('hrms_leave_approval_steps')
            ->where('status', 'pending')
            ->when($onlyTenant !== null, fn ($q) => $q->where('sub_institute_id', $onlyTenant))
            ->distinct()
            ->pluck('sub_institute_id');

        if ($tenantIds->isEmpty()) {
            return [];
        }

        /*
         * HONOUR EACH ORGANISATION'S OWN SCHEDULE FOR THIS TASK.
         *
         * A tenant that switched `leave:escalate` off in the Scheduler console is
         * skipped here, and one that gave it a different cron is swept only on the
         * passes their expression names. Without this the override table would be
         * exactly what LMS K-12's is — a screen that saves a preference nothing
         * reads.
         *
         * Not applied when a single tenant was named: `--tenant=6` is somebody
         * running this deliberately, and refusing to act because that tenant's
         * automatic schedule says otherwise would make the flag useless precisely
         * when it is most wanted.
         */
        if ($onlyTenant === null) {
            $tenantIds = collect(
                app(\App\Services\Platform\TenantTaskSchedule::class)
                    ->dueTenants('leave.escalate', $tenantIds->map(fn ($id) => (int) $id)->all())
            );

            if ($tenantIds->isEmpty()) {
                return [];
            }
        }

        $saved = HrmsLeaveWorkflowSetting::whereIn('sub_institute_id', $tenantIds)
            ->get()
            ->keyBy('sub_institute_id');

        $settings = $tenantIds->map(function ($tenantId) use ($saved) {
            return $saved->get($tenantId)
                ?? new HrmsLeaveWorkflowSetting(array_merge(
                    HrmsLeaveWorkflowSetting::defaults(),
                    ['sub_institute_id' => $tenantId]
                ));
        });

        /*
         * THE `escalation_enabled` FILTER MOVED, IT DID NOT DISAPPEAR.
         *
         * It used to drop the whole tenant here. That is right for a settings-path
         * step, whose only clock is the tenant-wide one — and wrong for a platform
         * step, which carries its own SLA and its own breach action. A per-step
         * rule somebody configured on the platform console must not be switched off
         * by a legacy checkbox on a different screen that they may never have seen.
         *
         * So the flag now gates only the fallback branch of the query below.
         */

        $escalated = [];
        $now = now();

        foreach ($settings as $setting) {
            // A target nobody can act as is not an escalation. This now only
            // disables the FALLBACK branch — per-step rules are unaffected.
            $target = self::normaliseEscalateTo($setting->escalate_to);

            $legacyEnabled = (bool) $setting->escalation_enabled && $target !== null;

            $amount = max(1, (int) $setting->escalation_time);
            $unit   = $setting->escalation_unit === 'days' ? 'days' : 'hours';
            $cutoff = Carbon::parse($now)->sub($unit, $amount);

            /*
             * F-135. THE SWEEP MUST LOOK AT THE REQUEST, NOT ONLY AT THE STEP.
             *
             * This query used to read hrms_leave_approval_steps alone. The
             * foreign key to hrms_emp_leaves is ON DELETE CASCADE, which sounds
             * like protection and is inert here: this module SOFT-deletes
             * everywhere, so the cascade never fires and steps outlive their
             * request.
             *
             * The only thing keeping deleted requests out of the sweep was the
             * explicit closeOpenSteps() call inside cancel() and destroy(). Any
             * other route to a soft delete - a repair migration, a support
             * cleanup, a probe - left the steps 'pending', and this ran over
             * them every hour, stamping the one-shot escalated_at on requests
             * that no longer exist and notifying five HR users about each.
             *
             * Observed on live: 17 escalated steps belonging to soft-deleted
             * leaves. Nothing was corrupted, but HR was being told about
             * requests nobody could open.
             *
             * The join also filters on status: an approved or rejected request
             * has nothing left to escalate either, and reaching that state by
             * any path other than decision() would have had the same effect.
             */
            $due = DB::table('hrms_leave_approval_steps as s')
                ->join('hrms_emp_leaves as l', 'l.id', '=', 's.leave_id')
                ->where('s.sub_institute_id', $setting->sub_institute_id)
                ->where('s.status', 'pending')
                ->whereNotNull('s.pending_since')
                ->whereNull('l.deleted_at')
                ->where('l.status', 'pending')
                /*
                 * TWO CLOCKS, AND A STEP IS ON EXACTLY ONE OF THEM.
                 *
                 * A platform step carries its own `sla_hours` and `on_breach`; a
                 * settings step carries neither and is measured against the
                 * tenant-wide `escalation_time`. `sla_hours IS NULL` is what tells
                 * them apart, which is why the migration made it nullable rather
                 * than defaulting it to 0 — 0 means "explicitly no deadline" and
                 * NULL means "this row predates per-step deadlines".
                 */
                ->where(function ($q) use ($now, $cutoff, $legacyEnabled) {
                    $q->where(function ($p) use ($now) {
                        $p->whereNotNull('s.sla_hours')
                          ->where('s.sla_hours', '>', 0)
                          ->where('s.on_breach', '!=', 'none')
                          ->whereRaw('s.pending_since <= DATE_SUB(?, INTERVAL s.sla_hours HOUR)', [$now]);
                    });

                    if ($legacyEnabled) {
                        $q->orWhere(function ($p) use ($cutoff) {
                            $p->whereNull('s.sla_hours')
                              ->whereNull('s.escalated_at')
                              ->where('s.pending_since', '<=', $cutoff);
                        });
                    }
                })
                ->select('s.*')
                ->get();

            foreach ($due as $step) {
                /*
                 * What this step's breach means.
                 *
                 * A settings step has no `on_breach`, and its only behaviour has
                 * ever been to escalate — so that is what it still does, using the
                 * tenant's target. Nothing about the fallback path changes.
                 */
                $action = $step->sla_hours === null
                    ? 'escalate'
                    : (string) ($step->on_breach ?: 'none');

                $row = $this->applyBreach($step, $action, $target, $setting, $now);

                if ($row !== null) {
                    $escalated[] = $row;
                }
            }
        }

        return $escalated;
    }

    /**
     * The chain as the frontend renders it: one entry per step, in order,
     * with everything a timeline needs and nothing it has to derive.
     */
    public function timelineFor(int $leaveId): array
    {
        return array_map(function (array $step) {
            return [
                'step'          => (int) $step['step_order'],
                'role'          => $step['approver_role'],
                // The tenant's own wording where a platform chain gave one, and the
                // role otherwise. Frozen, so renaming a step in the console does not
                // rewrite what an employee was told at the time.
                'role_label'    => $step['step_name'] ?: self::label($step['approver_role']),
                // Who the step is waiting on, when it names a person rather than a
                // role. Null on every settings-path step, which is every row that
                // existed before this.
                'approver_named'    => $step['approver_user_name'] ?? null,
                'approver_named_id' => isset($step['approver_user_id']) ? (int) $step['approver_user_id'] : null,
                // What this step's own deadline was, and what happens when it passes.
                // Null means the tenant-wide escalation clock applies instead.
                'sla_hours'       => isset($step['sla_hours']) ? (int) $step['sla_hours'] : null,
                'on_breach'       => $step['on_breach'] ?? null,
                'require_comment' => (bool) ($step['require_comment'] ?? false),
                // 'settings' or 'platform'. The screen can say where a rule came
                // from rather than leaving somebody to guess which config it obeyed.
                'source'          => $step['source'] ?? 'settings',
                'status'        => $step['status'],
                // What was actually decided, which 'status' cannot carry: a
                // step whose status is 'rejected' may have been a rejection, a
                // cancellation or a send-back, and the employee is told very
                // different things in each case.
                'decision'      => $step['decision'] ?? null,
                'approver_id'   => $step['approver_id'] ? (int) $step['approver_id'] : null,
                'approver_name' => $step['approver_name'],
                /*
                 * Set only when the SLA sweeper decided this step itself.
                 *
                 * An auto-decision must never be mistakable for a human one, so
                 * `approver_id` stays null on those rows and the reason travels
                 * here — the timeline can then say "approved automatically: no
                 * response within 48h" rather than showing an approval that
                 * apparently nobody made.
                 */
                'auto_decided_reason' => $step['auto_decided_reason'] ?? null,
                'comment'       => $step['comment'],
                'decided_at'    => $step['decided_at'],
                'pending_since' => $step['pending_since'],
                'escalated_at'  => $step['escalated_at'],
                'escalated_to'  => $step['escalated_to'],
                'escalated_to_label' => $step['escalated_to'] ? self::label($step['escalated_to']) : null,
            ];
        }, $this->stepsFor($leaveId));
    }

    /**
     * A chain role as a person reads it.
     *
     * The bare role keys are listed explicitly because a platform step stores them
     * verbatim, and the `default` arm would render `hr_manager` as "Hr Manager" —
     * on the employee's own dashboard (`MyHrController`), in the 403 a rejected
     * approver sees, and in the escalation command's output.
     */
    public static function label(string $role): string
    {
        return match ($role) {
            'reporting_manager' => 'Reporting Manager',
            'department_head'   => 'Department Head',
            'hr'                => 'HR',
            'hr_manager'        => 'HR Manager',
            'hr_executive'      => 'HR Executive',
            'administrator'     => 'Administrator',
            // The sentinel for a step that names a person rather than a role. The
            // timeline shows `approver_user_name` beside it, so this only has to
            // read sensibly when the name is missing.
            'user'              => 'A named approver',
            default             => ucwords(str_replace('_', ' ', $role)),
        };
    }

    /**
     * The role_keys that may decide a step for this chain role. Used by the queue
     * filter and by `RecipientResolver`.
     *
     * ── A BARE ROLE KEY STANDS FOR ITSELF AND NOTHING WIDER ─────────────────
     *
     * `ROLE_KEYS` maps the LEAVE screen's three switches, where "HR" legitimately
     * means both HR keys because that screen offers one switch for both. A platform
     * step names a role key directly, and `hr_manager` there means `hr_manager` —
     * resolving it through `ROLE_KEYS['hr']` would grant `hr_executive` a decision
     * nobody gave them.
     *
     * `user` resolves to no keys at all, which is correct: a named-person step is
     * decided by a person, not by a role. `roleMayDecide()` and
     * `RecipientResolver` both read `approver_user_id` for those.
     */
    public static function roleKeysFor(string $chainRole): array
    {
        if (isset(self::ROLE_KEYS[$chainRole])) {
            return self::ROLE_KEYS[$chainRole];
        }

        return in_array($chainRole, RoleKey::ALL, true) ? [$chainRole] : [];
    }

    /** The chain role a role_key acts as, or null. Inverse of ROLE_KEYS. */
    public static function chainRoleFor(?string $roleKey): ?string
    {
        if ($roleKey === null) {
            return null;
        }

        foreach (self::ROLE_KEYS as $chainRole => $keys) {
            if (in_array($roleKey, $keys, true)) {
                return $chainRole;
            }
        }

        return null;
    }
}
