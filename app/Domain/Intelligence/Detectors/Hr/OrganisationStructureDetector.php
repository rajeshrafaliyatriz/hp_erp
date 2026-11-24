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
 * ORGANISATION STRUCTURE: how complete the organisation's own structure data is. This is data quality, not a judgement about any person or department.
 *
 * It counts, among the ACTIVE people in the caller's scope (ActiveEmployees + PeopleScope):
 *   - people with no reporting manager   (`tbluser.reporting_manager_id` empty or 0)
 *   - people with no department          (`tbluser.department_id` empty or 0)
 *   - people with no job title           (`tbluser.jobtitle_id` empty or 0)
 * and, among the departments in scope, departments with no head (`hrms_departments.head_user_id` empty or 0; deleted and inactive departments are left out).
 *
 * JOB TITLE, AND WHAT THIS DOES NOT CLAIM. A person's job role lives under two keys: `jobtitle_id` (the real foreign key, promoted by
 * 2026_08_22_100000_add_jobtitle_id_foreign_key_to_tbluser, which also back-filled it from the legacy text column) and `allocated_standards` (the legacy text
 * column that is still written in step during a deprecation window). This detector reads `jobtitle_id` ONLY, and its sentences say exactly that ("no job title
 * recorded in the job-title field"): it does not claim the person has no role anywhere in the system. Nor does it check that the id points at an existing role.
 *
 * A missing reporting manager is not always an error: the head of an organisation legitimately has none, and the sentence says so. A department head who is
 * no longer active is not checked (that would need a second join on people the caller may not be scoped to).
 *
 * WHO: HR manager, HR executive (their department, via PeopleScope), administrator, and, as aggregates only with no record references, executive and auditor.
 * Employees and managers are not shown the structure data of others, so they are not authorised at all.
 *
 * It reads no name, no e-mail, no free text, and names no individual in a sentence; people are only ever pointed at by id and only when the caller may.
 */
final class OrganisationStructureDetector extends AbstractHrDetector
{
    private const PEOPLE_LINK = '/module/organizational-management/user-management/employee-directory';
    private const DEPARTMENT_LINK = '/module/organizational-management/organization-setup/department-management';

    public function id(): string
    {
        return 'hr.organisation_structure';
    }

    public function title(): string
    {
        return 'Organisation structure';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_ORGANISATION, Taxonomy::SURFACE_HR_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole('hr_manager', 'hr_executive', 'administrator', 'executive', 'auditor');
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('tbluser', ['id', 'department_id', 'reporting_manager_id', 'jobtitle_id', 'status', 'terminated_date', 'deleted_at', 'sub_institute_id'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'People records are not available for this organisation.');
        }

        $people = PeopleScope::count($this->db, $caller);
        $minimum = $this->thresholds->int('org_structure_min_people');
        $basis = 'active people in scope';
        if ($people < $minimum) {
            return DetectorResult::insufficient($this, Coverage::of($people, null, $basis, $minimum), $asOf);
        }
        $coverage = new Coverage($people, null, $basis, true, null);

        $scoped = fn (): Builder => $this->db->table('tbluser as u')
            ->where('u.sub_institute_id', $caller->tenantId)
            ->whereIn('u.id', PeopleScope::userIds($this->db, $caller));
        $empty = static fn (string $col): string => "({$col} IS NULL OR {$col} = 0)";

        $stats = $scoped()->selectRaw(
            'SUM(CASE WHEN ' . $empty('u.reporting_manager_id') . ' THEN 1 ELSE 0 END) AS no_manager,'
            . ' SUM(CASE WHEN ' . $empty('u.department_id') . ' THEN 1 ELSE 0 END) AS no_department,'
            . ' SUM(CASE WHEN ' . $empty('u.jobtitle_id') . ' THEN 1 ELSE 0 END) AS no_title',
        )->first();

        $defs = [
            'no_manager' => ['column' => 'u.reporting_manager_id', 'category' => 'org_no_reporting_manager', 'title' => 'People have no reporting manager',
                'what' => 'have no reporting manager recorded', 'label' => 'People with no reporting manager',
                'why' => 'Approvals and team views follow the reporting line; a person without one falls outside both. (The head of an organisation may legitimately have none.)',
                'action' => 'Open the employee directory and set the reporting manager where one applies.'],
            'no_department' => ['column' => 'u.department_id', 'category' => 'org_no_department', 'title' => 'People are not placed in a department',
                'what' => 'are not placed in any department', 'label' => 'People with no department',
                'why' => 'Department views, department heads and department-level reports cannot include a person who belongs to no department.',
                'action' => 'Open the employee directory and assign the department.'],
            'no_title' => ['column' => 'u.jobtitle_id', 'category' => 'org_no_job_title', 'title' => 'People have no job title recorded',
                'what' => 'have no job title recorded in the job-title field', 'label' => 'People with no job title in the job-title field',
                'why' => 'Role-based views and capability mapping start from the job title; the older allocated-role field is not checked here, so this shows the job-title field only.',
                'action' => 'Open the employee directory and set the job title.'],
        ];

        $insights = [];
        foreach ($defs as $metric => $d) {
            $n = (int) ($stats->{$metric} ?? 0);
            if ($n === 0) {
                continue;
            }
            $share = $this->pct($n, $people);
            $evidence = [
                Evidence::metric($d['label'], $n, 'people', 'tbluser'),
                Evidence::metric('Active people assessed', $people, 'people', 'tbluser'),
                Evidence::metric('Share of active people', $share, '%', 'tbluser'),
            ];
            if ($caller->mayReferenceIndividuals()) {
                $refs = $scoped()->whereRaw($empty($d['column']))->orderBy('u.id')->limit(10)->pluck('u.id');
                foreach ($refs as $id) {
                    $evidence[] = Evidence::entity('Employee', 'employee', (int) $id);
                }
            }
            $insights[] = new Insight(
                key: $this->keyFor($metric, $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: $d['category'],
                severity: $share >= $this->thresholds->float('org_structure_gap_pct') ? 'attention' : 'info',
                title: $d['title'],
                whatHappened: sprintf('%d of %d active people %s.', $n, $people, $d['what']),
                whyItMatters: $d['why'],
                evidence: $evidence, coverage: $coverage, asOf: $asOf,
                suggestedAction: $d['action'],
                deepLink: self::PEOPLE_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $n,
            );
        }

        $headless = $this->departmentsWithoutHead($caller, $clock, $asOf);
        if ($headless !== null) {
            $insights[] = $headless;
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }

    /** Departments in scope with no head. Not about people, so it carries no population. */
    private function departmentsWithoutHead(IntelligenceCaller $caller, DetectionClock $clock, string $asOf): ?Insight
    {
        if (! $this->canRead('hrms_departments', ['id', 'head_user_id', 'status', 'sub_institute_id', 'deleted_at'])) {
            return null;
        }
        $departments = function () use ($caller): Builder {
            $q = $this->db->table('hrms_departments as d')
                ->where('d.sub_institute_id', $caller->tenantId)
                ->whereNull('d.deleted_at')
                ->where('d.status', 1);
            if ($caller->scope() === 'department') {
                // A department-scope caller sees their own department only; with none, nothing (fail closed).
                $caller->departmentId === null ? $q->whereRaw('1 = 0') : $q->where('d.id', $caller->departmentId);
            }

            return $q;
        };

        $total = (int) $departments()->count();
        if ($total === 0) {
            return null;
        }
        $headless = fn (): Builder => $departments()->where(fn ($w) => $w->whereNull('d.head_user_id')->orWhere('d.head_user_id', 0));
        $n = (int) $headless()->count();
        if ($n === 0) {
            return null;
        }

        $evidence = [
            Evidence::metric('Departments with no head', $n, 'departments', 'hrms_departments'),
            Evidence::metric('Active departments assessed', $total, 'departments', 'hrms_departments'),
        ];
        if ($caller->mayReferenceIndividuals()) {
            foreach ($headless()->orderBy('d.id')->limit(10)->pluck('d.id') as $id) {
                $evidence[] = Evidence::entity('Department', 'department', (int) $id);
            }
        }

        return new Insight(
            key: $this->keyFor('no_head', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'org_department_without_head', severity: 'attention',
            title: 'Departments have no head',
            whatHappened: sprintf('%d of %d active department(s) have no head recorded.', $n, $total),
            whyItMatters: 'Department-level approvals and views are addressed to the department head; a department without one has nobody to receive them.',
            evidence: $evidence, coverage: new Coverage($total, null, 'active departments in scope', true, null), asOf: $asOf,
            suggestedAction: 'Open department management and name a head for each department.',
            deepLink: self::DEPARTMENT_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
            surfaces: $this->surfaces(), population: null,
        );
    }
}
