<?php

namespace App\Domain\Intelligence\Detectors\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\PeopleScope;
use App\Domain\Intelligence\Taxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * LEAVE ATTENTION: leave requests whose current approval step has been waiting longer than the provisional limit (leave_pending_hours, P-4).
 *
 * Source: hrms_leave_approval_steps joined to hrms_emp_leaves (the request's owner), scoped to the people the caller may see.
 *
 * HOW WAITING TIME IS MEASURED (and why): from `pending_since`, the moment the step became the current one. Never from `created_at`: the approval steps of
 * leaves that existed before the feature were back-filled by a migration that stamped `created_at` (and, for pending steps, `pending_since`) with the
 * MIGRATION time, so a back-filled step looks younger than it really is. Ageing from `pending_since` can therefore only UNDER-state a wait, never invent one.
 * A pending step with no `pending_since` cannot be aged at all: it is counted and reported as "not measured", never as overdue.
 * A step counts as waiting only while its leave request is itself still `pending` (a leave cancelled, rejected or approved out of band can leave a step
 * reading `pending`); the "recorded steps" coverage figure still counts every step.
 *
 *   employee             -> their own requests that are waiting
 *   team / department    -> requests of the people in scope that are waiting
 *   organization         -> everyone's; executives and auditors see aggregates only (no record references)
 */
final class LeaveAttentionDetector extends AbstractHrDetector
{
    private const DEEP_LINK = '/dashboard/leave-management';

    public function id(): string
    {
        return 'hr.leave_attention';
    }

    public function title(): string
    {
        return 'Leave approvals waiting';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_HR_DASHBOARD, Taxonomy::SURFACE_LEAVE, Taxonomy::SURFACE_ME_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole(...self::PEOPLE_ROLES);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('hrms_leave_approval_steps', ['leave_id', 'status', 'pending_since', 'escalated_at', 'sub_institute_id'])
            || ! $this->canRead('hrms_emp_leaves', ['id', 'user_id', 'sub_institute_id', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Leave approval records are not available for this organisation.');
        }

        $hours = $this->thresholds->int('leave_pending_hours');
        $cutoff = $clock->hoursAgo($hours);
        $self = $caller->scope() === 'self';

        $base = fn (): Builder => $this->db->table('hrms_leave_approval_steps as s')
            ->join('hrms_emp_leaves as l', 'l.id', '=', 's.leave_id')
            ->where('s.sub_institute_id', $caller->tenantId)
            ->where('l.sub_institute_id', $caller->tenantId)
            ->whereNull('l.deleted_at')
            ->whereIn('l.user_id', PeopleScope::userIds($this->db, $caller));

        $total = (int) $base()->count();
        $minimum = $self ? 0 : $this->thresholds->int('leave_min_steps');
        $basis = 'leave approval steps recorded for the people in scope';
        if ($total < $minimum) {
            return DetectorResult::insufficient($this, Coverage::of($total, null, $basis, $minimum), $asOf);
        }
        $coverage = new Coverage($total, null, $basis, true, null);

        // A step is "waiting" only while its LEAVE is still pending: a request cancelled, rejected or approved out of band can leave a step reading `pending`.
        // Compared case-insensitively; a NULL leave status is not asserted to be waiting.
        $waiting = fn (): Builder => $base()->where('s.status', 'pending')->whereRaw("LOWER(COALESCE(l.status, '')) = 'pending'");

        $stats = $waiting()->selectRaw(
            'COUNT(*) AS pending_n,'
            . ' SUM(CASE WHEN s.pending_since IS NULL THEN 1 ELSE 0 END) AS unmeasured,'
            . ' SUM(CASE WHEN s.pending_since IS NOT NULL AND s.pending_since < ? THEN 1 ELSE 0 END) AS overdue,'
            . ' SUM(CASE WHEN s.pending_since IS NOT NULL AND s.pending_since < ? AND s.escalated_at IS NOT NULL THEN 1 ELSE 0 END) AS escalated,'
            . ' COUNT(DISTINCT CASE WHEN s.pending_since IS NOT NULL AND s.pending_since < ? THEN l.user_id END) AS owners,'
            . ' MIN(CASE WHEN s.pending_since < ? THEN s.pending_since END) AS oldest',
            [$cutoff, $cutoff, $cutoff, $cutoff],
        )->first();

        $overdue = (int) ($stats->overdue ?? 0);
        $pending = (int) ($stats->pending_n ?? 0);
        $unmeasured = (int) ($stats->unmeasured ?? 0);
        if ($overdue === 0) {
            return DetectorResult::ok($this, [], $coverage, $asOf);
        }

        $owners = (int) ($stats->owners ?? 0);
        $escalated = (int) ($stats->escalated ?? 0);
        $oldestHours = $stats->oldest ? (int) CarbonImmutable::parse((string) $stats->oldest, 'UTC')->diffInHours($clock->instant()) : null;

        $evidence = [
            Evidence::metric(sprintf('Approval steps waiting more than %d hours', $hours), $overdue, 'steps', 'hrms_leave_approval_steps'),
            Evidence::metric('Approval steps waiting in total', $pending, 'steps', 'hrms_leave_approval_steps'),
            Evidence::metric('Already escalated', $escalated, 'steps', 'hrms_leave_approval_steps'),
        ];
        if ($oldestHours !== null) {
            $evidence[] = Evidence::metric('Longest wait', $oldestHours, 'hours', 'hrms_leave_approval_steps');
        }
        if ($unmeasured > 0) {
            $evidence[] = Evidence::metric('Waiting steps with no start time (not measured)', $unmeasured, 'steps', 'hrms_leave_approval_steps');
        }
        if ($caller->mayReferenceIndividuals()) {
            $refs = $waiting()->whereNotNull('s.pending_since')->where('s.pending_since', '<', $cutoff)
                ->orderBy('s.pending_since')->limit(10)->pluck('l.id');
            foreach ($refs as $leaveId) {
                $evidence[] = Evidence::entity('Leave request', 'leave_request', (int) $leaveId);
            }
        }

        $severity = ($overdue >= 5 || ($oldestHours !== null && $oldestHours >= 168) || $escalated > 0) ? 'high' : 'attention';

        return DetectorResult::ok($this, [new Insight(
            key: $this->keyFor('waiting', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'leave_approval_delay', severity: $severity,
            title: $self ? 'A leave request of yours is still waiting for approval' : 'Leave requests are waiting for approval',
            whatHappened: $self
                ? sprintf('%d of your leave approval step(s) have been waiting more than %d hours.', $overdue, $hours)
                : sprintf('%d leave approval step(s) for %d person(s) have been waiting more than %d hours.', $overdue, $owners, $hours),
            whyItMatters: 'People cannot plan time off while a request is undecided, and unanswered approvals are what the escalation rules exist to catch.',
            evidence: $evidence, coverage: $coverage, asOf: $asOf,
            suggestedAction: $self ? 'Ask the approver to decide, or check who currently holds the request.' : 'Open leave management and decide or reassign the waiting requests.',
            deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
            surfaces: $this->surfaces(), population: $owners,
        )], $coverage, $asOf);
    }
}
