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
use Illuminate\Database\Query\Builder;

/**
 * ONBOARDING & PROBATION: probation periods that are ending (or have ended) without a confirmation, and onboarding tasks that are overdue.
 *
 *   probation ending  - probation_end within probation_window_days and the confirmation still pending
 *   probation overdue - probation_end already passed and the confirmation still pending
 *   tasks overdue     - due date passed and the task is not complete: complete means status 'completed' (the product's rule) OR a completion time;
 *                       cancelled/skipped tasks are left out
 *
 * ONLY OPEN JOURNEYS: status in the product's own live set (not-started, in-progress, on-hold). Closed journeys (cancelled, terminated, completed) keep the
 * default confirmation_status 'pending' and are never assessed, counted in coverage, or used to scope tasks.
 *
 * WHO IS IN SCOPE: a journey belongs to a person (employee_id) and to a manager (manager_id). Self: the caller's own journey. Team: journeys of the
 * caller's direct reports plus journeys where the caller is the named manager. Department: the people in the caller's department. Organisation: every
 * journey of the tenant, including those of people not yet on the payroll (no employee_id). Executives and auditors see counts only.
 *
 * Sources: talent_onboarding_journeys, talent_onboarding_tasks. The probation_* and confirmation_* columns were added later and are guarded.
 */
final class OnboardingProbationDetector extends AbstractHrDetector
{
    private const DEEP_LINK = '/module/talent-management/onboarding';
    /**
     * The journeys the product itself calls "live". Copied from App\Http\Controllers\Api\Onboarding\OnboardingOverviewController::OPEN_STATUSES
     * (`private const OPEN_STATUSES = ['not-started', 'in-progress', 'on-hold']`, the set behind every KPI tile there). A cancelled, terminated or
     * completed journey keeps the column default confirmation_status 'pending', so without this filter it would raise a probation alarm for nobody.
     */
    private const OPEN_JOURNEY_STATUSES = ['not-started', 'in-progress', 'on-hold'];

    /**
     * A task that is not "overdue" whatever its date: done (the product's rule is status 'completed'; the completion timestamp is checked separately)
     * or deliberately dropped.
     */
    private const SETTLED_TASKS = ['completed', 'cancelled', 'canceled', 'skipped', 'not_applicable', 'n/a'];

    public function id(): string
    {
        return 'hr.onboarding_probation';
    }

    public function title(): string
    {
        return 'Onboarding and probation';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_ONBOARDING, Taxonomy::SURFACE_HR_DASHBOARD, Taxonomy::SURFACE_ME_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole(...self::PEOPLE_ROLES);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('talent_onboarding_journeys', ['id', 'employee_id', 'manager_id', 'joining_date', 'probation_end', 'confirmation_status', 'sub_institute_id', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Onboarding and probation records are not available for this organisation.');
        }

        $self = $caller->scope() === 'self';
        $journeys = fn (): Builder => $this->scopedJourneys($caller);
        $total = (int) $journeys()->count();
        if ($self && $total === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'No onboarding journey is recorded for you.');
        }
        $minimum = $self ? 1 : $this->thresholds->int('onboarding_min_journeys');
        $coverage = Coverage::of($total, null, 'onboarding journeys recorded for the people in scope', $minimum);
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $today = $clock->today();
        $windowEnd = $clock->daysAhead($this->thresholds->int('probation_window_days'));
        $pending = fn (): Builder => $journeys()->whereNotNull('j.probation_end')->whereRaw("LOWER(COALESCE(j.confirmation_status, '')) = 'pending'");
        $insights = [];

        $defs = [
            'ending' => [fn (): Builder => $pending()->where('j.probation_end', '>=', $today)->where('j.probation_end', '<=', $windowEnd), 'attention',
                'Probation periods are ending without a confirmation', '%1$d probation period(s) end within %2$d days and are not yet confirmed',
                'A confirmation decision is needed before the probation ends, or the person is left in limbo.', 'Review the people and record the confirmation or an extension.'],
            'overdue' => [fn (): Builder => $pending()->where('j.probation_end', '<', $today), 'high',
                'Probation periods have ended without a confirmation', '%1$d probation period(s) have already ended and are still not confirmed',
                'An unconfirmed person past the end of probation has an unclear employment status.', 'Record the confirmation, an extension or the end of employment.'],
        ];
        foreach ($defs as $bucket => [$query, $severity, $title, $phrase, $why, $action]) {
            $n = (int) $query()->count();
            if ($n === 0) {
                continue;
            }
            $evidence = [
                Evidence::metric('Journeys matching', $n, 'journeys', 'talent_onboarding_journeys'),
                Evidence::metric('Onboarding journeys in scope', $total, 'journeys', 'talent_onboarding_journeys'),
                Evidence::window('Probation end dates', $bucket === 'ending' ? $today : '1000-01-01', $bucket === 'ending' ? $windowEnd : $today),
            ];
            if ($caller->mayReferenceIndividuals()) {
                foreach ($query()->orderBy('j.probation_end')->limit(10)->pluck('j.id') as $id) {
                    $evidence[] = Evidence::entity('Onboarding journey', 'onboarding_journey', (int) $id);
                }
            }
            $insights[] = new Insight(
                key: $this->keyFor('probation', $caller, $clock, $bucket),
                detector: $this->id(), domain: $this->domain(), category: 'probation_' . $bucket, severity: $severity, title: $title,
                whatHappened: sprintf($phrase . '.', $n, $this->thresholds->int('probation_window_days')),
                whyItMatters: $why, evidence: $evidence, coverage: $coverage, asOf: $asOf, suggestedAction: $action,
                deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $n,
            );
        }

        if ($this->canRead('talent_onboarding_tasks', ['id', 'journey_id', 'due_date', 'status', 'completed_at', 'sub_institute_id', 'deleted_at'])) {
            $overdue = fn (): Builder => $this->db->table('talent_onboarding_tasks as t')
                ->where('t.sub_institute_id', $caller->tenantId)->whereNull('t.deleted_at')
                ->whereNull('t.completed_at')->whereNotNull('t.due_date')->where('t.due_date', '<', $clock->daysAgo($this->thresholds->int('onboarding_task_overdue_days')))
                ->whereRaw("LOWER(COALESCE(t.status, '')) NOT IN ('" . implode("','", self::SETTLED_TASKS) . "')")
                ->whereIn('t.journey_id', $journeys()->select('j.id'));
            $stats = $overdue()->selectRaw('COUNT(*) AS n, COUNT(DISTINCT t.journey_id) AS journeys_n')->first();
            $n = (int) ($stats->n ?? 0);
            if ($n > 0) {
                $people = (int) ($stats->journeys_n ?? 0);
                $evidence = [
                    Evidence::metric('Onboarding tasks past their due date and not completed', $n, 'tasks', 'talent_onboarding_tasks'),
                    Evidence::metric('Journeys affected', $people, 'journeys', 'talent_onboarding_tasks'),
                ];
                if ($caller->mayReferenceIndividuals()) {
                    foreach ($overdue()->orderBy('t.due_date')->limit(10)->pluck('t.journey_id') as $id) {
                        $evidence[] = Evidence::entity('Onboarding journey', 'onboarding_journey', (int) $id);
                    }
                }
                $insights[] = new Insight(
                    key: $this->keyFor('tasks_overdue', $caller, $clock),
                    detector: $this->id(), domain: $this->domain(), category: 'onboarding_tasks_overdue', severity: $n >= 10 ? 'high' : 'attention',
                    title: $self ? 'Some of your onboarding tasks are overdue' : 'Onboarding tasks are overdue',
                    whatHappened: sprintf('%d onboarding task(s) are past their due date and not completed, across %d journey(s).', $n, $people),
                    whyItMatters: 'Unfinished onboarding tasks (documents, access, introductions) slow down a new joiner and are usually someone else\'s hand-off.',
                    evidence: $evidence, coverage: $coverage, asOf: $asOf,
                    suggestedAction: 'Open the onboarding journeys and complete or reassign the overdue tasks.',
                    deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
                    surfaces: $this->surfaces(), population: $people,
                );
            }
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }

    /** The journeys of this tenant that belong to the caller's scope (see the class note). */
    private function scopedJourneys(IntelligenceCaller $caller): Builder
    {
        $q = $this->db->table('talent_onboarding_journeys as j')->where('j.sub_institute_id', $caller->tenantId)->whereNull('j.deleted_at')
            ->whereRaw("LOWER(COALESCE(j.status, '')) IN (?, ?, ?)", self::OPEN_JOURNEY_STATUSES);

        return match ($caller->scope()) {
            'self' => $q->where('j.employee_id', $caller->userId),
            'team' => $q->where(fn (Builder $w) => $w->whereIn('j.employee_id', PeopleScope::userIds($this->db, $caller))->orWhere('j.manager_id', $caller->userId)),
            'department' => $q->whereIn('j.employee_id', PeopleScope::userIds($this->db, $caller)),
            default => $q,
        };
    }
}
