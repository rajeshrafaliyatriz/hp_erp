<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Capability module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * Existing catalogue sources reused here: capability.competencies, capability.jobroles (Competency Library,
 * Capability Library, Dashboard) and capability.entity_mappings (Capability Explorer).
 */
final class CapabilitySources extends SourceGroup
{
    public function definitions(): array
    {
        $limitArg = self::limitArg();

        return [
            [
                'name' => 'capability.frameworks',
                'module' => 'capability_intelligence',
                'label' => 'Competency frameworks',
                'description' => 'Competency frameworks with version, status, the department and job role they are for, and how many competencies each contains.',
                'arguments' => [
                    self::arg('status', 'string', 'draft, active or archived.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_competency_frameworks as f')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'f.department_id')
                        ->where('f.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('f.deleted_at')
                        ->select(['f.id as framework_id', 'f.name as framework', 'f.version', 'f.status', 'd.department', 'f.jobrole as job_role'])
                        ->selectRaw('(SELECT COUNT(*) FROM s_competency_framework_items i WHERE i.framework_id = f.id AND i.deleted_at IS NULL) as competencies')
                        ->addSelect('f.updated_at')
                        ->orderBy('f.name');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('f.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.role_requirements',
                'module' => 'capability_intelligence',
                'label' => 'Competencies required by each job role',
                'description' => 'Which competencies each job role needs, the proficiency level expected (1-5) and whether it is mandatory.',
                'arguments' => [
                    self::arg('jobrole_id', 'integer', 'Only this job role.'),
                    self::arg('competency_id', 'integer', 'Only this competency.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('jobrole_competency_map as m')
                        ->join('s_user_jobrole as j', function ($join) use ($tenant) {
                            $join->on('j.id', '=', 'm.jobrole_id')
                                ->where('j.sub_institute_id', '=', $tenant)
                                ->whereNull('j.deleted_at');
                        })
                        ->join('competency as c', function ($join) use ($tenant) {
                            $join->on('c.id', '=', 'm.competency_id')
                                ->where('c.sub_institute_id', '=', $tenant)
                                ->whereNull('c.deleted_at');
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'j.department_id')
                        ->where('m.sub_institute_id', $tenant)
                        ->select(['m.id as mapping_id', 'j.jobrole as job_role'])
                        ->selectRaw('COALESCE(d.department, j.department) as department')
                        ->addSelect(['c.name as competency', 'm.required_proficiency', 'm.is_mandatory'])
                        ->orderBy('j.jobrole')
                        ->orderBy('c.name');

                    if (($r = $this->int($a, 'jobrole_id')) !== null) {
                        $q->where('m.jobrole_id', $r);
                    }
                    if (($c = $this->int($a, 'competency_id')) !== null) {
                        $q->where('m.competency_id', $c);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.audit_activity',
                'module' => 'capability_intelligence',
                'label' => 'Capability audit and activity',
                'description' => 'The activity log of the capability module: when, who, what they did and to which record. Field-by-field change details are not included.',
                'arguments' => [
                    self::arg('record_type', 'string', 'Only this kind of record, e.g. competency, framework, jobrole, skill.'),
                    self::arg('action', 'string', 'Only this action, e.g. approved_competency.'),
                    self::arg('since', 'string', 'Only activity on or after this date (YYYY-MM-DD).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_competency_activity_log as l')
                        ->where('l.sub_institute_id', $scope->selectedInstituteId)
                        ->select(['l.id as activity_id', 'l.created_at as occurred_at'])
                        ->selectRaw("COALESCE(NULLIF(l.actor_name, ''), 'System') as actor")
                        ->addSelect(['l.action', 'l.subject_type as record_type', 'l.subject_name as record', 'l.description'])
                        ->orderByDesc('l.created_at')
                        ->orderByDesc('l.id');

                    if (($t = $this->text($a, 'record_type')) !== null) {
                        $q->where('l.subject_type', $t);
                    }
                    if (($x = $this->text($a, 'action')) !== null) {
                        $q->where('l.action', $x);
                    }
                    if (($s = $this->text($a, 'since')) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
                        $q->where('l.created_at', '>=', $s . ' 00:00:00');
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.kasba_items',
                'module' => 'capability_library',
                'label' => 'KASBA items of each competency',
                'description' => 'The skill, knowledge, ability, attitude and behaviour items that make up each competency, with their weight.',
                'arguments' => [
                    self::arg('kasba_type', 'string', 'skill, knowledge, ability, attitude or behaviour.'),
                    self::arg('competency_id', 'integer', 'Only this competency.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('competency_kasba_item as k')
                        ->join('competency as c', function ($join) use ($tenant) {
                            $join->on('c.id', '=', 'k.competency_id')
                                ->where('c.sub_institute_id', '=', $tenant)
                                ->whereNull('c.deleted_at');
                        })
                        ->where('k.sub_institute_id', $tenant)
                        ->select(['k.id as kasba_item_id', 'c.name as competency', 'k.kasba_type', 'k.item_label as item', 'k.weight'])
                        ->orderBy('c.name')
                        ->orderBy('k.kasba_type')
                        ->orderBy('k.item_label');

                    if (($t = $this->text($a, 'kasba_type')) !== null) {
                        $q->where('k.kasba_type', $t);
                    }
                    if (($c = $this->int($a, 'competency_id')) !== null) {
                        $q->where('k.competency_id', $c);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.skills',
                'module' => 'capability_library',
                'label' => 'Skill library',
                'description' => 'Skills in the organisation\'s skill library with category, sub-category, type, importance and approval status.',
                'arguments' => [
                    self::arg('category', 'string', 'Only this skill category.'),
                    self::arg('status', 'string', 'Active or Inactive.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_users_skills as s')
                        ->where('s.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('s.deleted_at')
                        ->select(['s.id as skill_id', 's.title as skill', 's.category', 's.sub_category', 's.competency_type as type', 's.skill_importance as importance', 's.status', 's.approve_status'])
                        ->orderByRaw('s.category IS NULL')
                        ->orderBy('s.category')
                        ->orderBy('s.title');

                    if (($c = $this->text($a, 'category')) !== null) {
                        $q->where('s.category', $c);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('s.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'capability.library_overview',
                'module' => 'capability_library',
                'label' => 'Capability library overview',
                'description' => 'How many entries each library holds (skills, knowledge, abilities, attitudes, behaviours, job roles, job role tasks) and across how many categories.',
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $part = fn (string $table, string $label, string $categoryColumn) => DB::table($table)
                        ->where('sub_institute_id', $tenant)
                        ->whereNull('deleted_at')
                        ->selectRaw("'{$label}' as library, COUNT(*) as entries, COUNT(DISTINCT {$categoryColumn}) as categories");

                    $union = $part('s_users_skills', 'Skills', 'category')
                        ->unionAll($part('s_user_knowledge', 'Knowledge', 'category'))
                        ->unionAll($part('s_user_ability', 'Abilities', 'category'))
                        ->unionAll($part('s_user_attitude', 'Attitudes', 'category'))
                        ->unionAll($part('s_user_behaviour', 'Behaviours', 'category'))
                        ->unionAll($part('s_user_jobrole_task', 'Job role tasks', 'task_category'))
                        ->unionAll(
                            DB::table('s_user_jobrole')
                                ->where('sub_institute_id', $tenant)
                                ->whereNull('deleted_at')
                                ->where(fn ($w) => $w->where('status', 'Active')->orWhereNull('status'))
                                ->selectRaw("'Job roles' as library, COUNT(*) as entries, COUNT(DISTINCT jobrole_category) as categories")
                        );

                    return DB::query()->fromSub($union, 'l')->select(['l.library', 'l.entries', 'l.categories'])->orderByDesc('l.entries');
                },
            ],
        ];
    }

    public function pages(): array
    {
        return [
            '/module/capability-intelligence/competency-library' => [
                'sources' => ['capability.competencies', 'capability.kasba_items'],
                'purpose' => 'Define and approve the competencies the organisation measures, with the KASBA items behind each.',
                'action' => 'create_competency',
            ],
            '/module/capability-intelligence/dashboard' => [
                'sources' => ['capability.competencies', 'capability.jobroles', 'capability.frameworks', 'capability.audit_activity'],
                'purpose' => 'A command-centre view of competency coverage, job-role mapping and recent capability activity.',
                'action' => null,
            ],
            '/module/capability-intelligence/capability-explorer' => [
                'sources' => ['capability.entity_mappings'],
                'purpose' => 'Explore the capability graph (an external graph service) and how this organisation\'s records map onto it.',
                'action' => null,
            ],
            '/module/capability-intelligence/competency-framework' => [
                'sources' => ['capability.frameworks', 'capability.role_requirements'],
                'purpose' => 'Build competency frameworks and map which competencies, at what level, each job role requires.',
                'action' => null,
            ],
            '/module/capability-intelligence/audit' => [
                'sources' => ['capability.audit_activity'],
                'purpose' => 'Review who changed which competency, framework, mapping or library record, and when.',
                'action' => null,
            ],
            '/module/capability-intelligence/capability-library' => [
                'sources' => ['capability.skills', 'capability.jobroles', 'capability.library_overview', 'capability.kasba_items'],
                'purpose' => 'Maintain the libraries behind capability: skills, knowledge, abilities, attitudes, behaviours, job roles and their tasks.',
                'action' => 'create_skill',
            ],
        ];
    }
}
