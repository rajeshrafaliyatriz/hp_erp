<?php

namespace App\Services\Platform;

use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;

/**
 * Generic approval-chain enforcement over `g2g_platform_approval_steps`, for
 * every workflow point enforced from Round 4 onward.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS IS `LeaveApprovalWorkflow`'S PROVEN SHAPE, GENERALIZED — NOT A REWRITE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `hrms.leave.approval` has been genuinely enforced since round 1: a chain is
 * read, frozen onto a request at submission, and decided through with a real
 * concurrency guard. Every method below mirrors that class's own logic —
 * `openFor`, `currentStep`, `roleMayDecide`, `recordDecision`,
 * `closeOpenSteps`, `escalateOverdue` — read directly before writing this, not
 * assumed. `LeaveApprovalWorkflow` and `hrms_leave_approval_steps` are
 * completely untouched by this class; leave keeps its own table and its own
 * settings-path fallback, neither of which any of the six domains this engine
 * serves need.
 *
 * ── WHAT THIS CLASS DOES NOT DO ──────────────────────────────────────────────
 *
 * It never writes to a domain's own table (`hrms_attendance_regularisations`,
 * `talent_offers`, ...). It only manages `g2g_platform_approval_steps` and
 * RETURNS what happened — `final`/`status`/`step`/`of`/`next`, the same shape
 * `LeaveApprovalWorkflow::recordDecision()` returns — so each domain's own
 * controller applies that to its own status column itself. This is the same
 * separation `LeaveRequestApiController` already has from `LeaveApprovalWorkflow`.
 *
 * ── NO SETTINGS-PATH FALLBACK, NO `approved_lwp` ────────────────────────────
 *
 * Leave carries fifteen years of legacy baggage (`hrms_leave_workflow_settings`,
 * a tenant-wide escalation clock, a "leave without pay" decision variant) that
 * none of these six domains have. A domain with no active platform chain simply
 * is not enforced — `openFor()` returns an empty step list and the calling
 * controller's existing behaviour is unchanged, which is exactly what "declared
 * but not yet enforced" already means for the other five points today.
 */
class ApprovalEngine
{
    private const TABLE = 'g2g_platform_approval_steps';

    /** A step this open still has a decision left to make. */
    private const OPEN_STATUSES = ['pending', 'waiting'];

    public function __construct(
        private readonly WorkflowChainSelector $selector = new WorkflowChainSelector(),
    ) {
    }

    /**
     * The chain that would govern this subject right now, translated and ready
     * to freeze — or `[]` if no active chain matches (nothing to enforce).
     *
     * @param  array<string, float|int>  $context
     * @return array<int, array<string, mixed>>
     */
    public function chainFor(int $tenantId, string $flowKey, array $context = []): array
    {
        $chain = $this->selector->select($tenantId, $flowKey, $context)['chain'];

        if ($chain === null) {
            return [];
        }

        $steps = json_decode((string) $chain->steps, true);

        if (! is_array($steps) || $steps === []) {
            return [];
        }

        return $this->selector->translateSteps($steps, $tenantId, (int) $chain->id) ?? [];
    }

    /**
     * Freeze the chain onto a newly submitted subject. Returns the flat list of
     * roles, matching `LeaveApprovalWorkflow::openFor()`'s own return shape.
     *
     * Writes NOTHING when there is no active chain — the calling controller's
     * pre-existing single-decision behaviour is what happens for an
     * unconfigured tenant, the same as every other unenforced point today.
     *
     * @param  array<string, float|int>  $context
     * @return array<int, string>
     */
    public function openFor(
        int $tenantId,
        string $flowKey,
        string $subjectType,
        int $subjectId,
        array $context = []
    ): array {
        $chain = $this->chainFor($tenantId, $flowKey, $context);

        if ($chain === []) {
            return [];
        }

        $now = now();
        $rows = [];

        foreach ($chain as $index => $step) {
            $rows[] = [
                'sub_institute_id' => $tenantId,
                'flow_key' => $flowKey,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'step_order' => $index + 1,
                'approver_role' => $step['approver_role'],
                'status' => $index === 0 ? 'pending' : 'waiting',
                'pending_since' => $index === 0 ? $now : null,
                'approver_user_id' => $step['approver_user_id'],
                'approver_user_name' => $step['approver_user_name'],
                'step_name' => $step['step_name'],
                'sla_hours' => $step['sla_hours'],
                'on_breach' => $step['on_breach'],
                'require_comment' => $step['require_comment'],
                'source' => $step['source'],
                'workflow_id' => $step['workflow_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table(self::TABLE)->insert($rows);

        return array_values(array_unique(array_column($chain, 'approver_role')));
    }

    /** @return array<int, array<string, mixed>> */
    public function stepsFor(string $subjectType, int $subjectId): array
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('step_order')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** The step currently awaiting a decision, or null if none is open (either
        finished, or never opened because nothing is enforced here). */
    public function currentStep(string $subjectType, int $subjectId): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'pending')
            ->orderBy('step_order')
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * May this role/person decide this step? Mirrors
     * `LeaveApprovalWorkflow::roleMayDecide()` exactly: administrator always may;
     * a step naming a specific person is decided by that person, not their role;
     * escalation widens who may decide without reassigning it.
     */
    public function roleMayDecide(array $step, ?string $roleKey, ?int $userId = null): bool
    {
        if ($roleKey === null) {
            return false;
        }

        if ($roleKey === 'administrator') {
            return true;
        }

        if (! empty($step['approver_user_id'])) {
            if ($userId !== null && (int) $step['approver_user_id'] === $userId) {
                return true;
            }
        } elseif ($roleKey === $step['approver_role']) {
            return true;
        }

        if (! empty($step['escalated_at']) && ! empty($step['escalated_to'])) {
            return $roleKey === $step['escalated_to'];
        }

        return false;
    }

    /**
     * Record one approver's decision. Returns the same shape
     * `LeaveApprovalWorkflow::recordDecision()` does:
     * `final`/`status`/`step`/`of`/`next`(/`conflict`).
     *
     * @param  array<string, mixed>  $step  the row from currentStep()/stepsFor()
     * @param  array{user_id: ?int, auto_reason?: ?string}  $context
     * @return array<string, mixed>
     */
    public function recordDecision(
        string $subjectType,
        int $subjectId,
        array $step,
        string $decision,
        array $context,
        ?string $comment = null
    ): array {
        $steps = $this->stepsFor($subjectType, $subjectId);
        $total = count($steps);
        $now = now();

        $autoReason = $context['auto_reason'] ?? null;

        $approverName = $autoReason !== null
            ? 'System — SLA rule'
            : (DB::table('tbluser')
                ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS employee_name")
                ->where('id', $context['user_id'])
                ->value('employee_name') ?: 'User #' . $context['user_id']);

        $approved = $decision === 'approved';
        $sentBack = $decision === 'sent_back';

        return DB::transaction(function () use (
            $subjectType, $subjectId, $step, $decision, $comment, $total, $now,
            $approverName, $approved, $sentBack, $autoReason, $context
        ) {
            $claimed = DB::table(self::TABLE)
                ->where('id', $step['id'])
                ->where('status', 'pending')
                ->update([
                    'status' => $approved ? 'approved' : ($sentBack ? 'sent_back' : 'rejected'),
                    'decision' => $decision,
                    'approver_id' => $autoReason !== null ? null : $context['user_id'],
                    'approver_name' => $approverName,
                    'auto_decided_reason' => $autoReason,
                    'comment' => ($comment !== null && $comment !== '') ? $comment : null,
                    'decided_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($claimed === 0) {
                return [
                    'final' => false, 'conflict' => true, 'status' => 'pending',
                    'step' => (int) $step['step_order'], 'of' => $total, 'next' => null,
                ];
            }

            if ($sentBack) {
                DB::table(self::TABLE)
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subjectId)
                    ->where('step_order', '>', 1)
                    ->update([
                        'status' => 'waiting', 'decision' => null, 'approver_id' => null,
                        'approver_name' => null, 'comment' => null, 'decided_at' => null,
                        'pending_since' => null, 'escalated_at' => null, 'escalated_to' => null,
                        'reminded_at' => null, 'updated_at' => $now,
                    ]);

                return [
                    'final' => true, 'status' => $decision,
                    'step' => (int) $step['step_order'], 'of' => $total, 'next' => null,
                ];
            }

            if (! $approved) {
                DB::table(self::TABLE)
                    ->where('subject_type', $subjectType)
                    ->where('subject_id', $subjectId)
                    ->whereIn('status', self::OPEN_STATUSES)
                    ->update(['status' => 'skipped', 'updated_at' => $now]);

                return [
                    'final' => true, 'status' => $decision,
                    'step' => (int) $step['step_order'], 'of' => $total, 'next' => null,
                ];
            }

            $next = DB::table(self::TABLE)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('status', 'waiting')
                ->orderBy('step_order')
                ->first();

            if (! $next) {
                return [
                    'final' => true, 'status' => $decision,
                    'step' => (int) $step['step_order'], 'of' => $total, 'next' => null,
                ];
            }

            DB::table(self::TABLE)
                ->where('id', $next->id)
                ->update(['status' => 'pending', 'pending_since' => $now, 'updated_at' => $now]);

            return [
                'final' => false, 'status' => 'pending',
                'step' => (int) $step['step_order'], 'of' => $total, 'next' => $next->approver_role,
            ];
        });
    }

    /** Withdraw/cancel: everything still open is skipped, nothing decided is touched. */
    public function closeOpenSteps(string $subjectType, int $subjectId): int
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    /**
     * Sweep every enforced point's overdue pending steps, across every tenant,
     * in one pass — the shared escalation command calls this once, not once per
     * domain. Mirrors `LeaveApprovalWorkflow::escalateOverdue()`'s SLA branch
     * only; there is no settings-path fallback here, because no domain this
     * engine serves has a legacy settings screen to fall back to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function escalateOverdue(): array
    {
        $now = now();

        $due = DB::table(self::TABLE)
            ->where('status', 'pending')
            ->whereNotNull('pending_since')
            ->whereNotNull('sla_hours')
            ->where('sla_hours', '>', 0)
            ->where('on_breach', '!=', 'none')
            ->whereRaw('pending_since <= DATE_SUB(?, INTERVAL sla_hours HOUR)', [$now])
            ->get();

        $results = [];

        foreach ($due as $step) {
            $row = $this->applyBreach($step, (string) $step->on_breach, $now);

            if ($row !== null) {
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * What a breached step's `on_breach` actually does. Mirrors
     * `LeaveApprovalWorkflow::applyBreach()`'s remind/escalate/auto_* branches —
     * no `$setting`/`$target` fallback parameter, since there is no tenant-wide
     * legacy target for these domains to fall back to.
     *
     * @return array<string, mixed>|null
     */
    private function applyBreach(object $step, string $action, $now): ?array
    {
        $base = [
            'step_id' => (int) $step->id,
            'subject_type' => $step->subject_type,
            'subject_id' => (int) $step->subject_id,
            'sub_institute_id' => (int) $step->sub_institute_id,
            'from' => $step->approver_role,
            'waiting_since' => $step->pending_since,
            'to' => null,
            'action' => $action,
        ];

        if ($action === 'none') {
            return null;
        }

        if ($action === 'remind') {
            if ($step->reminded_at !== null) {
                return null;
            }

            DB::table(self::TABLE)
                ->where('id', $step->id)
                ->whereNull('reminded_at')
                ->update(['reminded_at' => $now, 'updated_at' => $now]);

            return $base;
        }

        if ($action === 'escalate') {
            if ($step->escalated_at !== null) {
                return null;
            }

            $next = DB::table(self::TABLE)
                ->where('subject_type', $step->subject_type)
                ->where('subject_id', $step->subject_id)
                ->where('step_order', '>', $step->step_order)
                ->orderBy('step_order')
                ->value('approver_role');

            // A named-person step is nobody's role — escalating to it would not
            // widen anything, since only that one person could ever decide it.
            $to = ($next !== null && $next !== 'user') ? $next : null;

            if ($to === null || $to === $step->approver_role) {
                return null;
            }

            DB::table(self::TABLE)
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
     * Decide a step because its SLA passed and nobody acted. Mirrors
     * `LeaveApprovalWorkflow::autoDecide()`, behind the same kind of kill
     * switch — `config('platform_services.auto_decisions_enabled', false)`,
     * defaulting off so no enforced domain starts auto-deciding the day this
     * ships because a tenant saved a chain with an auto_approve/auto_reject
     * rule to see what the screen offered.
     *
     * @return array<string, mixed>|null
     */
    private function autoDecide(object $step, string $action, $now, array $base): ?array
    {
        if (! config('platform_services.auto_decisions_enabled', false)) {
            return array_merge($base, ['action' => 'auto_skipped', 'note' => 'auto-decisions are switched off']);
        }

        if (! empty($step->require_comment)) {
            return array_merge($base, ['action' => 'auto_skipped', 'note' => 'step requires a comment']);
        }

        $hours = (int) $step->sla_hours;

        if ($hours <= 0) {
            return null;
        }

        $decision = $action === 'auto_approve' ? 'approved' : 'rejected';
        $reason = sprintf('No response within %dh — decided by the SLA rule on this step.', $hours);

        $progress = $this->recordDecision(
            $step->subject_type,
            (int) $step->subject_id,
            (array) $step,
            $decision,
            ['user_id' => null, 'auto_reason' => $reason],
        );

        // Lost the race to a human who decided in the same moment.
        if (! empty($progress['conflict'])) {
            return null;
        }

        return array_merge($base, ['action' => $action, 'decision' => $decision, 'reason' => $reason]);
    }

    /** The role_key set that may act as `$chainRole` — no alias collapsing (see
        class note: a platform-sourced step always stores the verbatim role
        key), so this is set membership, not the leave-only ROLE_KEYS map. */
    public static function roleKeysFor(string $chainRole): array
    {
        return in_array($chainRole, RoleKey::ALL, true) ? [$chainRole] : [];
    }
}
