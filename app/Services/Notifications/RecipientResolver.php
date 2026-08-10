<?php

namespace App\Services\Notifications;

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
