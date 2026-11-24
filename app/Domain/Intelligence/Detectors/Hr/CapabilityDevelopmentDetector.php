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
 * CAPABILITY DEVELOPMENT: development plans and competency assessments that are past their due date and still open, for the people the caller may see.
 *
 * Sources (columns confirmed in 2026_07_28_100100_create_competency_module_tables.php): `s_competency_development_plans` and `s_competency_assessments`,
 * each with user_id, status, due_date, completed_at, sub_institute_id and deleted_at.
 *
 * THE DUE DATE IS THE FACT; THE `status` COLUMN IS NOT. The migration documents `status` as free vocabulary (plans: active | completed | overdue | on_hold;
 * assessments: open | in_progress | completed | overdue) and a stored `overdue` can lag behind the calendar. So a record is OPEN only when its status is one of the
 * known open words (plans: active, overdue; assessments: open, in_progress, overdue) and it has no `completed_at`; it is OVERDUE when it is open and its
 * `due_date` is earlier than today. A plan that is on hold, completed or archived, or any status this detector does not know, is never counted: the rule
 * can only under-state, never invent. An open record with no due date cannot be aged; it is counted and reported as "not measured". `progress` is not used:
 * a plan's percentage and its status are written by different screens and nothing says which wins.
 *
 * WHAT THIS DOES NOT DO: CAPABILITY GAP ANALYSIS IS NOT IMPLEMENTED. Whether a person lacks a competency their role requires would have to be read from the
 * competency / competency_kasba_item / jobrole_competency_map tables, whose creation migrations are not in the source tree, so their columns and their
 * meaning cannot be verified from source. Nothing in this class reads them, and it makes no claim about skills, proficiency, readiness or fit.
 *
 * WHOSE RECORDS: the person on the record (`user_id`) must be an active person in the caller's scope (PeopleScope); executives and auditors see aggregates
 * only. It never reads a title, a score, a review status, a jobrole name or any other free text.
 */
final class CapabilityDevelopmentDetector extends AbstractHrDetector
{
    private const PLAN_LINK = '/module/capability-intelligence/dashboard';
    private const SELF_PLAN_LINK = '/profile';
    private const ASSESSMENT_LINK = '/module/lms/assessments';

    /** Statuses that mean "still to be done"; anything else (completed, on_hold, archived, unknown) is left out. */
    private const OPEN = [
        'plan' => ['active', 'overdue'],
        'assessment' => ['open', 'in_progress', 'overdue'],
    ];

    public function id(): string
    {
        return 'hr.capability_development';
    }

    public function title(): string
    {
        return 'Capability development';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_CAPABILITY, Taxonomy::SURFACE_ME_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole(...self::PEOPLE_ROLES);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        $columns = ['id', 'user_id', 'status', 'due_date', 'completed_at', 'sub_institute_id', 'deleted_at'];
        $kinds = [];
        if ($this->canRead('s_competency_development_plans', $columns)) {
            $kinds['plan'] = 's_competency_development_plans';
        }
        if ($this->canRead('s_competency_assessments', $columns)) {
            $kinds['assessment'] = 's_competency_assessments';
        }
        if ($kinds === []) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Development plans and assessments are not available for this organisation.');
        }

        $self = $caller->scope() === 'self';
        $today = $clock->today();
        $open = fn (string $kind): Builder => $this->db->table($kinds[$kind] . ' as r')
            ->where('r.sub_institute_id', $caller->tenantId)
            ->whereNull('r.deleted_at')
            ->whereNull('r.completed_at')
            ->whereRaw("LOWER(COALESCE(r.status, '')) IN (" . implode(', ', array_fill(0, count(self::OPEN[$kind]), '?')) . ')', self::OPEN[$kind])
            ->whereIn('r.user_id', PeopleScope::userIds($this->db, $caller));

        $stats = [];
        $openTotal = 0;
        $measurable = 0;
        foreach (array_keys($kinds) as $kind) {
            $row = $open($kind)->selectRaw(
                'COUNT(*) AS open_n,'
                . ' SUM(CASE WHEN r.due_date IS NULL THEN 1 ELSE 0 END) AS undated,'
                . ' SUM(CASE WHEN r.due_date IS NOT NULL AND r.due_date < ? THEN 1 ELSE 0 END) AS overdue_n,'
                . ' COUNT(DISTINCT CASE WHEN r.due_date IS NOT NULL AND r.due_date < ? THEN r.user_id END) AS owners,'
                . ' MIN(CASE WHEN r.due_date < ? THEN r.due_date END) AS oldest',
                [$today, $today, $today],
            )->first();
            $stats[$kind] = [
                'open' => (int) ($row->open_n ?? 0), 'undated' => (int) ($row->undated ?? 0), 'overdue' => (int) ($row->overdue_n ?? 0),
                'owners' => (int) ($row->owners ?? 0), 'oldest' => $row->oldest ?? null,
            ];
            $openTotal += $stats[$kind]['open'];
            $measurable += $stats[$kind]['open'] - $stats[$kind]['undated'];
        }

        if ($self && $openTotal === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'You have no open development plans or assessments.');
        }
        $minimum = $self ? 0 : $this->thresholds->int('capability_min_records');
        $coverage = new Coverage($measurable, $openTotal, 'open development plans and assessments for the people in scope that carry a due date', $measurable >= $minimum,
            $measurable >= $minimum ? null : sprintf('Only %d open record(s) with a due date are recorded; at least %d are needed before they are assessed.', $measurable, $minimum));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $defs = [
            'plan' => ['category' => 'development_plan_overdue', 'entity' => ['Development plan', 'development_plan'], 'noun' => 'development plan',
                'what' => 'Development plans are past their due date', 'selfWhat' => 'A development plan of yours is past its due date',
                'link' => $self ? self::SELF_PLAN_LINK : self::PLAN_LINK, 'metric' => 'Open development plans past their due date', 'total' => 'Development plans still open',
                'why' => 'A plan past its due date is either still needed and late, or finished and not closed; either way it no longer tells the person or their manager what is next.',
                'action' => 'Update the plan: finish it, close it, or set a due date that is real.'],
            'assessment' => ['category' => 'assessment_overdue', 'entity' => ['Competency assessment', 'competency_assessment'], 'noun' => 'competency assessment',
                'what' => 'Competency assessments are past their due date', 'selfWhat' => 'A competency assessment of yours is past its due date',
                'link' => self::ASSESSMENT_LINK, 'metric' => 'Open assessments past their due date', 'total' => 'Assessments still open',
                'why' => 'An assessment that is not completed leaves the capability picture for that person out of date.',
                'action' => 'Complete the assessment, or ask the assessor to.'],
        ];

        $insights = [];
        foreach ($defs as $kind => $d) {
            if (! isset($kinds[$kind]) || $stats[$kind]['overdue'] === 0) {
                continue;
            }
            $s = $stats[$kind];
            $table = $kinds[$kind];
            $evidence = [
                Evidence::metric($d['metric'], $s['overdue'], 'records', $table),
                Evidence::metric($d['total'], $s['open'], 'records', $table),
            ];
            if ($s['oldest']) {
                $evidence[] = Evidence::metric('Oldest due date', substr((string) $s['oldest'], 0, 10), null, $table);
            }
            if ($s['undated'] > 0) {
                $evidence[] = Evidence::metric('Open records with no due date (not measured)', $s['undated'], 'records', $table);
            }
            $evidence[] = Evidence::window('Due before', '1000-01-01', $today);
            if ($caller->mayReferenceIndividuals()) {
                $refs = $open($kind)->whereNotNull('r.due_date')->where('r.due_date', '<', $today)->orderBy('r.due_date')->orderBy('r.id')->limit(10)->pluck('r.id');
                foreach ($refs as $id) {
                    $evidence[] = Evidence::entity($d['entity'][0], $d['entity'][1], (int) $id);
                }
            }
            $share = $this->pct($s['overdue'], $s['open']);
            $insights[] = new Insight(
                key: $this->keyFor($kind . '_overdue', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: $d['category'],
                severity: $share >= $this->thresholds->float('capability_overdue_pct') ? 'high' : 'attention',
                title: $self ? $d['selfWhat'] : $d['what'],
                whatHappened: $self
                    ? sprintf('%d of your %s(s) are open and past their due date.', $s['overdue'], $d['noun'])
                    : sprintf('%d open %s(s) for %d person(s) are past their due date.', $s['overdue'], $d['noun'], $s['owners']),
                whyItMatters: $d['why'],
                evidence: $evidence, coverage: $coverage, asOf: $asOf,
                suggestedAction: $d['action'],
                deepLink: $d['link'], confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $s['owners'],
            );
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }
}
