<?php

namespace App\Services\Notifications;

use App\Services\Leave\LeaveApprovalWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * THE NAMED-CONSUMER TEST, AS CODE.
 *
 * Every method here answers one question: WHO acts on this, and how do we find
 * them? An event with no answer is not in this class, and is therefore never
 * notified.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MEASUREMENT THAT SHAPED THIS FILE:
 *
 *   THERE IS NO EMPLOYEE -> MANAGER EDGE IN THIS PRODUCT.
 *
 *   tbluser.reporting_manager_id  ......  0 of 387 rows populated
 *   tbluser.supervisor_opt  ............  a FLAG ("Supervisor"/"Subordinate"),
 *                                         4 and 57 rows - it marks people, it
 *                                         does not link them
 *   every other manager-ish or supervisor-ish column in the schema (17 of them)
 *   is PER-CASE, not per-person:
 *      talent_offboarding_cases.manager_id .... 3/3
 *      task_management_projects.manager_id .... 3/3
 *      s_performance_reviews.manager_id ....... 16/228
 *
 * SO: A RECIPIENT COMES FROM THE EVENT OR FROM THE CASE THE EVENT REFERENCES.
 * There is no org-chart fallback because there is no org chart. Any notification
 * whose only plausible recipient is "the employee's manager" cannot be delivered
 * to anyone and is deferred, not shipped.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Every recipient carries a REASON, stored on the notification row. Six months
 * from now "why did I get this?" is a support question, and the answer should be
 * in the data rather than in this file.
 */
class RecipientResolver
{
    /**
     * How many holders of a role one notification may reach.
     *
     * An escalation to "HR" in an institute with forty HR users must not become
     * forty notifications about one leave request. Bounded and repeatable beats
     * unbounded; an on-call rota is a product decision nobody has taken.
     */
    private const MAX_ROLE_RECIPIENTS = 5;

    /**
     * @return array<int, array{user_id:int, reason:string}>
     */
    public function forEvent(object $event): array
    {
        $payload = $this->payload($event);
        $tenant  = (int) $event->sub_institute_id;

        $out = match ($event->type) {
            'task.rejected'             => $this->taskAssignee($event, $payload, $tenant),
            'assessment.completed'      => $this->subjectOfEvent($payload, 'assessed_employee'),
            'certification.expiring'    => $this->certificationHolder($event, $payload, $tenant),
            'development_plan.approved' => $this->developmentPlanOwner($event, $payload, $tenant),
            'employee.offboarded'       => $this->offboardingCaseManager($event, $payload, $tenant),
            'rights.changed'            => $this->subjectOfEvent($payload, 'affected_user'),
            // X-11. The holder, from the payload CertificateIssuer wrote - the
            // certificate row exists before the event is emitted, so this
            // user_id is our record, not a caller's claim.
            'certification.issued'      => $this->subjectOfEvent($payload, 'certificate_holder'),

            // HRIT leave, Sprint 7 (F-128). See the three methods at the bottom
            // of this class for why these can be resolved and a line manager
            // still cannot.
            'leave.submitted'           => $this->approversOfOpenStep($event, $payload, $tenant),
            'leave.decided'             => $this->leaveApplicant($event, $payload, $tenant),
            'leave.escalated'           => $this->escalationTargets($event, $payload, $tenant),

            default                     => [],
        };

        // TENANT CHECK ON THE WAY OUT. A recipient resolved from a case row must
        // still belong to the event's tenant - the case tables are joined by id,
        // but an id is only unique per table, not per tenant, and one bad row
        // would notify a stranger at another company.
        return $this->keepOnlySameTenant($out, $tenant);
    }

    // ── the six ─────────────────────────────────────────────────────────────

    /** The person who has to redo the work. */
    private function taskAssignee(object $event, array $payload, int $tenant): array
    {
        $userId = (int) ($payload['task_allocated_to'] ?? 0);

        if ($userId === 0 && $event->entity_id) {
            $userId = (int) DB::table('task')
                ->where('id', (int) $event->entity_id)
                ->where('sub_institute_id', $tenant)
                ->value('task_allocated_to');
        }

        return $userId > 0 ? [['user_id' => $userId, 'reason' => 'task_assignee']] : [];
    }

    /** The holder, who is the only person who can renew it. */
    private function certificationHolder(object $event, array $payload, int $tenant): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($userId === 0 && $event->entity_id) {
            $userId = (int) DB::table('s_competency_certifications')
                ->where('id', (int) $event->entity_id)
                ->where('sub_institute_id', $tenant)
                ->value('user_id');
        }

        return $userId > 0 ? [['user_id' => $userId, 'reason' => 'certification_holder']] : [];
    }

    /** The person whose plan it is - they are the one who starts the courses. */
    private function developmentPlanOwner(object $event, array $payload, int $tenant): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($userId === 0 && $event->entity_id) {
            $userId = (int) DB::table('s_competency_development_plans')
                ->where('id', (int) $event->entity_id)
                ->where('sub_institute_id', $tenant)
                ->value('user_id');
        }

        return $userId > 0 ? [['user_id' => $userId, 'reason' => 'development_plan_owner']] : [];
    }

    /**
     * THE CASE MANAGER, NOT THE LINE MANAGER.
     *
     * talent_offboarding_cases.manager_id is populated on 3 of 3 rows, which is
     * why this one ships while capability.flag_raised - whose only recipient is a
     * line manager - does not.
     */
    private function offboardingCaseManager(object $event, array $payload, int $tenant): array
    {
        $managerId = (int) ($payload['manager_id'] ?? 0);

        if ($managerId === 0 && $event->entity_id) {
            $managerId = (int) DB::table('talent_offboarding_cases')
                ->where('id', (int) $event->entity_id)
                ->where('sub_institute_id', $tenant)
                ->value('manager_id');
        }

        return $managerId > 0 ? [['user_id' => $managerId, 'reason' => 'offboarding_case_manager']] : [];
    }

    /**
     * The subject of the event - the person it is ABOUT.
     *
     * G-SEC-12's IDENTITY vs SUBJECT distinction, on the delivery side: this reads
     * a user_id out of a payload, which is exactly the shape the security sweep
     * forbids for IDENTITY. It is correct here because the event was written by
     * EventRecorder from an already-authenticated context - the payload is OUR
     * record, not a caller's claim. Nothing in this class reads a request.
     */
    private function subjectOfEvent(array $payload, string $reason): array
    {
        $userId = (int) ($payload['user_id'] ?? $payload['subject_user_id'] ?? 0);

        return $userId > 0 ? [['user_id' => $userId, 'reason' => $reason]] : [];
    }

    // ── HRIT leave, Sprint 7 ────────────────────────────────────────────────

    /**
     * THE PEOPLE WHOSE TURN IT IS. Not "the manager", which is why this ships.
     *
     * The rule at the top of this class stands: a notification whose only
     * plausible recipient is a line manager is deferred, because there is no org
     * chart to resolve one from. This is not that. hrms_leave_approval_steps
     * records the exact ROLE that must decide THIS request, frozen when it was
     * submitted, and role_key resolves that role to real users in this tenant.
     * The recipient is read from our own record of who is being waited on.
     *
     * ONE SOURCE OF TRUTH FOR "WHOSE TURN". This reads the same pending step
     * that LeaveRequestApiController::decision() reads to decide who may act.
     * Two answers to that question would eventually disagree, and the version
     * that told the wrong person is the one nobody would notice.
     */
    private function approversOfOpenStep(object $event, array $payload, int $tenant): array
    {
        $leaveId = (int) ($payload['leave_id'] ?? $event->entity_id ?? 0);

        if ($leaveId <= 0) {
            return [];
        }

        $step = DB::table('hrms_leave_approval_steps')
            ->where('leave_id', $leaveId)
            ->where('sub_institute_id', $tenant)
            ->where('status', 'pending')
            ->orderBy('step_order')
            ->first();

        if (!$step) {
            return [];
        }

        return $this->holdersOfChainRole($step->approver_role, $tenant, 'leave_approver');
    }

    /** The person who asked for the leave, and is waiting to hear. */
    private function leaveApplicant(object $event, array $payload, int $tenant): array
    {
        $userId = (int) ($payload['employee_id'] ?? 0);

        if ($userId === 0 && $event->entity_id) {
            $userId = (int) DB::table('hrms_emp_leaves')
                ->where('id', (int) $event->entity_id)
                ->where('sub_institute_id', $tenant)
                ->value('user_id');
        }

        return $userId > 0 ? [['user_id' => $userId, 'reason' => 'leave_applicant']] : [];
    }

    /**
     * Whoever escalate_to resolves to - the people who can now act on it.
     *
     * Escalating without telling them is a row in a table, not an escalation.
     */
    private function escalationTargets(object $event, array $payload, int $tenant): array
    {
        $role = (string) ($payload['to_role'] ?? '');

        return $role === '' ? [] : $this->holdersOfChainRole($role, $tenant, 'leave_escalation_target');
    }

    /**
     * Everyone in this tenant who holds a chain role.
     *
     * Capped, and the cap is deliberate: an escalation to "HR" in an institute
     * with forty HR users should not become forty notifications about one leave
     * request. The first few by id is arbitrary but bounded and repeatable;
     * a proper on-call rota is a product decision nobody has taken.
     */
    private function holdersOfChainRole(string $chainRole, int $tenant, string $reason): array
    {
        $roleKeys = LeaveApprovalWorkflow::roleKeysFor($chainRole);

        if ($roleKeys === []) {
            return [];
        }

        $ids = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.sub_institute_id', $tenant)
            ->where('u.status', 1)
            ->whereIn('p.role_key', $roleKeys)
            ->orderBy('u.id')
            ->limit(self::MAX_ROLE_RECIPIENTS)
            ->pluck('u.id')
            ->all();

        return array_map(fn ($id) => ['user_id' => (int) $id, 'reason' => $reason], $ids);
    }

    // ── plumbing ────────────────────────────────────────────────────────────

    private function payload(object $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }
        $decoded = json_decode((string) ($event->payload ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function keepOnlySameTenant(array $recipients, int $tenant): array
    {
        if ($recipients === []) {
            return [];
        }

        $ids = array_column($recipients, 'user_id');
        $valid = DB::table('tbluser')
            ->whereIn('id', $ids)
            ->where('sub_institute_id', $tenant)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return array_values(array_filter(
            $recipients,
            fn ($r) => in_array($r['user_id'], $valid, true)
        ));
    }
}
