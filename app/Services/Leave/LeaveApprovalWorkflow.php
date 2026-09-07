<?php

namespace App\Services\Leave;

use App\Models\HRMS\HrmsLeaveWorkflowSetting;
use App\Support\RoleKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * The chain a tenant has configured right now, as an ordered list of roles.
     *
     * @return array<int, string>
     */
    public function chainFor(int $subInstituteId): array
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

        foreach ($chain as $index => $role) {
            $rows[] = [
                'leave_id'         => $leaveId,
                'sub_institute_id' => $subInstituteId,
                'step_order'       => $index + 1,
                'approver_role'    => $role,
                // Only the first step is anyone's to act on. The rest wait their turn,
                // which is what stops step 2 being approved before step 1.
                'status'           => $index === 0 ? 'pending' : 'waiting',
                'pending_since'    => $index === 0 ? $now : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        DB::table('hrms_leave_approval_steps')->insert($rows);

        return $chain;
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
    public function roleMayDecide(array $step, ?string $roleKey): bool
    {
        if ($roleKey === null) {
            return false;
        }

        if ($roleKey === 'administrator') {
            return true;
        }

        $allowed = self::ROLE_KEYS[$step['approver_role']] ?? [];

        if (in_array($roleKey, $allowed, true)) {
            return true;
        }

        // Escalated: escalate_to may now decide it too. The assigned role keeps
        // its right to decide - escalation widens, it does not reassign.
        if (!empty($step['escalated_at']) && !empty($step['escalated_to'])) {
            $escalated = self::ROLE_KEYS[$step['escalated_to']] ?? [];

            return in_array($roleKey, $escalated, true);
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

        $approverName = DB::table('tbluser')
            ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS employee_name")
            ->where('id', $context['user_id'])
            ->value('employee_name') ?: 'User #' . $context['user_id'];

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
            $approverName, $approved, $sentBack
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
                    'approver_id'   => $context['user_id'],
                    'approver_name' => $approverName,
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
        $tenantIds = DB::table('hrms_leave_approval_steps')
            ->where('status', 'pending')
            ->whereNull('escalated_at')
            ->when($onlyTenant, fn ($q) => $q->where('sub_institute_id', $onlyTenant))
            ->distinct()
            ->pluck('sub_institute_id');

        if ($tenantIds->isEmpty()) {
            return [];
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
        })->filter(fn ($setting) => (bool) $setting->escalation_enabled);

        $escalated = [];
        $now = now();

        foreach ($settings as $setting) {
            // A target nobody can act as is not an escalation. Skip the tenant
            // rather than stamping steps that then have no possible approver.
            $target = self::normaliseEscalateTo($setting->escalate_to);

            if ($target === null) {
                continue;
            }

            $amount = max(1, (int) $setting->escalation_time);
            $unit   = $setting->escalation_unit === 'days' ? 'days' : 'hours';
            $cutoff = Carbon::parse($now)->sub($unit, $amount);

            $due = DB::table('hrms_leave_approval_steps')
                ->where('sub_institute_id', $setting->sub_institute_id)
                ->where('status', 'pending')
                ->whereNull('escalated_at')
                ->whereNotNull('pending_since')
                ->where('pending_since', '<=', $cutoff)
                ->get();

            foreach ($due as $step) {
                // Escalating a step to the role that already owns it would change
                // nothing and would still consume its one-shot escalated_at.
                if ($step->approver_role === $target) {
                    continue;
                }

                DB::table('hrms_leave_approval_steps')
                    ->where('id', $step->id)
                    ->update([
                        'escalated_at' => $now,
                        'escalated_to' => $target,
                        'updated_at'   => $now,
                    ]);

                $escalated[] = [
                    'step_id'          => (int) $step->id,
                    'leave_id'         => (int) $step->leave_id,
                    'sub_institute_id' => (int) $step->sub_institute_id,
                    'from'             => $step->approver_role,
                    'to'               => $target,
                    'waiting_since'    => $step->pending_since,
                ];
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
                'role_label'    => self::label($step['approver_role']),
                'status'        => $step['status'],
                // What was actually decided, which 'status' cannot carry: a
                // step whose status is 'rejected' may have been a rejection, a
                // cancellation or a send-back, and the employee is told very
                // different things in each case.
                'decision'      => $step['decision'] ?? null,
                'approver_id'   => $step['approver_id'] ? (int) $step['approver_id'] : null,
                'approver_name' => $step['approver_name'],
                'comment'       => $step['comment'],
                'decided_at'    => $step['decided_at'],
                'pending_since' => $step['pending_since'],
                'escalated_at'  => $step['escalated_at'],
                'escalated_to'  => $step['escalated_to'],
                'escalated_to_label' => $step['escalated_to'] ? self::label($step['escalated_to']) : null,
            ];
        }, $this->stepsFor($leaveId));
    }

    public static function label(string $role): string
    {
        return match ($role) {
            'reporting_manager' => 'Reporting Manager',
            'department_head'   => 'Department Head',
            'hr'                => 'HR',
            default             => ucwords(str_replace('_', ' ', $role)),
        };
    }

    /** The role_keys that may decide a step for this chain role. Used by the queue filter. */
    public static function roleKeysFor(string $chainRole): array
    {
        return self::ROLE_KEYS[$chainRole] ?? [];
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
