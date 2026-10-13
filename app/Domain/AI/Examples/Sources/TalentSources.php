<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Talent module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * Every source here mirrors a Talent Management page. Where the page itself is HR-only
 * (Onboarding, Offboarding, Employee Profiles, the Administration audit trail) a caller who is not an
 * administrator gets no rows; where the page has a "mine" view (Certifications, Development plans,
 * Mobility transfers) a non-administrator sees only their own rows, whatever they ask for.
 */
final class TalentSources extends SourceGroup
{
    /** SQL for "the job role this employee holds": jobtitle_id when set, otherwise the first allocated role. */
    private const USER_JOBROLE_ID = 'COALESCE(NULLIF(u.jobtitle_id, 0), CAST(NULLIF(SUBSTRING_INDEX(u.allocated_standards, \',\', 1), \'\') AS UNSIGNED))';

    public function definitions(): array
    {
        $limitArg = self::limitArg();

        return [
            // ---- Recruitment ------------------------------------------------------------------------------
            [
                'name' => 'talent.hiring_funnel',
                'module' => 'talent_recruitment',
                'label' => 'Hiring funnel by stage',
                'description' => 'How many applications sit at each stage (Pending Review, Shortlisted, Interview Scheduled, Hired ...), across how many jobs, and when the first and latest arrived.',
                'arguments' => [
                    self::arg('job_id', 'integer', 'Only applications to this job posting.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('talent_job_applications as a')
                        ->where('a.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('a.deleted_at')
                        ->groupBy('a.status')
                        ->select('a.status as stage')
                        ->selectRaw('COUNT(*) as applications')
                        ->selectRaw('COUNT(DISTINCT a.job_id) as jobs')
                        ->selectRaw('MIN(a.applied_date) as first_applied')
                        ->selectRaw('MAX(a.applied_date) as latest_applied')
                        ->orderByRaw('COUNT(*) DESC');

                    if (($j = $this->int($a, 'job_id')) !== null) {
                        $q->where('a.job_id', $j);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.interviews',
                'module' => 'talent_recruitment',
                'label' => 'Interview schedule',
                'description' => 'Interviews with the candidate, the job, the panel, round, date, time and whether it is scheduled or completed. Ratings and feedback are not included.',
                'arguments' => [
                    self::arg('status', 'string', 'Scheduled or Completed.'),
                    self::arg('job_id', 'integer', 'Only interviews for this job posting.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('talent_interview_schedules as i')
                        ->leftJoin('talent_job_applications as a', function ($join) use ($tenant) {
                            $join->on('a.id', '=', 'i.applicant_id')->where('a.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('talent_job_postings as p', function ($join) use ($tenant) {
                            $join->on('p.id', '=', 'i.job_id')->where('p.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('talent_interview_panel as pn', function ($join) use ($tenant) {
                            $join->on('pn.id', '=', 'i.panel_id')->where('pn.sub_institute_id', '=', $tenant);
                        })
                        ->where('i.sub_institute_id', $tenant)
                        ->whereNull('i.deleted_at')
                        ->select(['i.id as interview_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, ''))) as candidate")
                        ->addSelect(['p.title as job', 'pn.panel_name as panel', 'i.round_no as round', 'i.interview_date', 'i.time', 'i.status'])
                        ->orderByDesc('i.interview_date')
                        ->orderByDesc('i.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('i.status', $s);
                    }
                    if (($j = $this->int($a, 'job_id')) !== null) {
                        $q->where('i.job_id', $j);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.offers',
                'module' => 'talent_recruitment',
                'label' => 'Offers',
                'description' => 'Job offers with the candidate, the job, the position offered, start date, expiry and status. Salary is not included.',
                'arguments' => [
                    self::arg('status', 'string', 'sent or rejected.'),
                    self::arg('job_id', 'integer', 'Only offers for this job posting.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    // talent_offers has no deleted_at column.
                    $q = DB::table('talent_offers as o')
                        ->leftJoin('talent_job_applications as a', function ($join) use ($tenant) {
                            $join->on('a.id', '=', 'o.application_id')->where('a.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('talent_job_postings as p', function ($join) use ($tenant) {
                            $join->on('p.id', '=', 'o.job_id')->where('p.sub_institute_id', '=', $tenant);
                        })
                        ->where('o.sub_institute_id', $tenant)
                        ->select(['o.id as offer_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(a.first_name, ''), ' ', COALESCE(a.last_name, ''))) as candidate")
                        ->addSelect(['p.title as job', 'o.position', 'o.status', 'o.start_date', 'o.expires_at', 'o.sent_at'])
                        ->orderByDesc('o.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('o.status', $s);
                    }
                    if (($j = $this->int($a, 'job_id')) !== null) {
                        $q->where('o.job_id', $j);
                    }

                    return $q;
                },
            ],

            // ---- Onboarding (HR only) ---------------------------------------------------------------------
            [
                'name' => 'talent.onboarding_journeys',
                'module' => 'talent_management',
                'label' => 'Onboarding journeys',
                'description' => 'New-hire onboarding journeys with the position, department, joining date, stage, status, confirmation status and how many onboarding tasks are done. HR and administrators only. No contact details.',
                'arguments' => [
                    self::arg('status', 'string', 'e.g. not-started, in-progress, completed, cancelled.'),
                    self::arg('stage', 'string', 'e.g. preboarding, day-one, probation.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('talent_onboarding_journeys as j')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'j.department_id')
                        ->where('j.sub_institute_id', $tenant)
                        ->whereNull('j.deleted_at')
                        ->select(['j.id as journey_id', 'j.journey_code', 'j.candidate_name as new_hire', 'j.position', 'd.department', 'j.joining_date', 'j.stage', 'j.status', 'j.confirmation_status'])
                        ->selectRaw('(SELECT COUNT(*) FROM talent_onboarding_tasks t WHERE t.journey_id = j.id AND t.deleted_at IS NULL) as tasks')
                        ->selectRaw("(SELECT COUNT(*) FROM talent_onboarding_tasks t WHERE t.journey_id = j.id AND t.deleted_at IS NULL AND t.status = 'completed') as tasks_done")
                        ->orderByDesc('j.joining_date');

                    $this->hrOnly($q, $scope);

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('j.status', $s);
                    }
                    if (($s = $this->text($a, 'stage')) !== null) {
                        $q->where('j.stage', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.onboarding_tasks',
                'module' => 'talent_management',
                'label' => 'Onboarding tasks',
                'description' => 'The checklist tasks of each onboarding journey with category, owner, due date and status. HR and administrators only.',
                'arguments' => [
                    self::arg('status', 'string', 'pending or completed.'),
                    self::arg('category', 'string', 'e.g. it, payroll, compliance, benefits, learning.'),
                    self::arg('journey_id', 'integer', 'Only tasks of this onboarding journey.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('talent_onboarding_tasks as t')
                        ->join('talent_onboarding_journeys as j', function ($join) {
                            $join->on('j.id', '=', 't.journey_id')
                                ->on('j.sub_institute_id', '=', 't.sub_institute_id')
                                ->whereNull('j.deleted_at');
                        })
                        ->where('t.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('t.deleted_at')
                        ->select(['t.id as task_id', 'j.candidate_name as new_hire', 't.title as task', 't.category', 't.owner_label as owner', 't.due_date', 't.status', 't.completed_at'])
                        ->orderBy('t.due_date')
                        ->orderBy('t.id');

                    $this->hrOnly($q, $scope);

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('t.status', $s);
                    }
                    if (($c = $this->text($a, 'category')) !== null) {
                        $q->where('t.category', $c);
                    }
                    if (($j = $this->int($a, 'journey_id')) !== null) {
                        $q->where('t.journey_id', $j);
                    }

                    return $q;
                },
            ],

            // ---- Mobility & Succession --------------------------------------------------------------------
            [
                'name' => 'talent.mobility_jobs',
                'module' => 'talent_management',
                'label' => 'Internal job board',
                'description' => 'Internal vacancies open to existing employees with department, location, grade, deadline, vacancies and how many have applied.',
                'arguments' => [
                    self::arg('status', 'string', 'e.g. Open or Closed.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_mobility_jobs as m')
                        ->where('m.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('m.deleted_at')
                        ->select(['m.id as internal_job_id', 'm.job_id as reference', 'm.title as job', 'm.department', 'm.location', 'm.grade', 'm.posted_on', 'm.deadline', 'm.vacancies', 'm.status'])
                        ->selectRaw('(SELECT COUNT(*) FROM s_mobility_applications x WHERE x.job_posting_id = m.id AND x.deleted_at IS NULL) as applicants')
                        ->orderByDesc('m.posted_on')
                        ->orderByDesc('m.id');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('m.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.mobility_transfers',
                'module' => 'talent_management',
                'label' => 'Internal transfers',
                'description' => 'Internal transfers with the employee, from and to department and job role, effective date and status. A non-administrator sees only their own.',
                'arguments' => [
                    self::arg('status', 'string', 'e.g. Completed.'),
                    self::arg('user_id', 'integer', 'Only this employee (administrators only).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('s_mobility_transfers as t')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 't.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('hrms_departments as fd', 'fd.id', '=', 't.from_department_id')
                        ->leftJoin('hrms_departments as td', 'td.id', '=', 't.to_department_id')
                        ->where('t.sub_institute_id', $tenant)
                        ->whereNull('t.deleted_at')
                        ->select(['t.id as transfer_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->selectRaw('COALESCE(t.from_department, fd.department) as from_department')
                        ->selectRaw('COALESCE(t.to_department, td.department) as to_department')
                        ->addSelect(['t.from_jobrole as from_job_role', 't.to_jobrole as to_job_role', 't.effective_date', 't.status'])
                        ->orderByDesc('t.effective_date')
                        ->orderByDesc('t.id');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;
                    if ($u !== null) {
                        $q->where('t.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('t.status', $s);
                    }

                    return $q;
                },
            ],

            // ---- Employee Profiles (HR only) --------------------------------------------------------------
            [
                'name' => 'talent.employee_profiles',
                'module' => 'talent_management',
                'label' => 'Employee capability profiles',
                'description' => 'Employees with their job role and department and how many skills, certifications and development plans each has on record. HR and administrators only. Counts only, no ratings or contact details.',
                'arguments' => [
                    self::arg('department_id', 'integer', 'Only employees in this department.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('tbluser as u')
                        ->leftJoin('s_user_jobrole as j', function ($join) use ($tenant) {
                            $join->whereRaw('j.id = ' . self::USER_JOBROLE_ID)
                                ->where('j.sub_institute_id', '=', $tenant)
                                ->whereNull('j.deleted_at');
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('u.sub_institute_id', $tenant)
                        ->whereNull('u.deleted_at')
                        ->select(['u.id as user_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->addSelect(['j.jobrole as job_role', 'd.department'])
                        ->selectRaw('(SELECT COUNT(*) FROM s_skill_matrix sm WHERE sm.user_id = u.id AND sm.sub_institute_id = u.sub_institute_id AND sm.deleted_at IS NULL) as skills_on_record')
                        ->selectRaw('(SELECT COUNT(*) FROM s_competency_certifications c WHERE c.user_id = u.id AND c.sub_institute_id = u.sub_institute_id AND c.deleted_at IS NULL) as certifications')
                        ->selectRaw('(SELECT COUNT(*) FROM s_competency_development_plans p WHERE p.user_id = u.id AND p.sub_institute_id = u.sub_institute_id AND p.deleted_at IS NULL) as development_plans')
                        ->orderBy('u.first_name')
                        ->orderBy('u.last_name');

                    $this->hrOnly($q, $scope);

                    if (($d = $this->int($a, 'department_id')) !== null) {
                        $q->where('u.department_id', $d);
                    }

                    return $q;
                },
            ],

            // ---- Development & Career Paths ---------------------------------------------------------------
            [
                'name' => 'talent.development_plans',
                'module' => 'talent_management',
                'label' => 'Development plans',
                'description' => 'Individual development plans with the employee, job role, status, progress, start and due dates and how many plan actions are done. A non-administrator sees only their own.',
                'arguments' => [
                    self::arg('status', 'string', 'e.g. active, completed, on_hold.'),
                    self::arg('user_id', 'integer', 'Only this employee (administrators only).'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('s_competency_development_plans as p')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'p.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->where('p.sub_institute_id', $tenant)
                        ->whereNull('p.deleted_at')
                        ->select(['p.id as plan_id', 'p.title as plan'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->addSelect(['p.jobrole as job_role', 'p.status', 'p.progress', 'p.start_date', 'p.due_date'])
                        ->selectRaw('(SELECT COUNT(*) FROM s_competency_plan_actions x WHERE x.plan_id = p.id AND x.deleted_at IS NULL) as actions')
                        ->selectRaw("(SELECT COUNT(*) FROM s_competency_plan_actions x WHERE x.plan_id = p.id AND x.deleted_at IS NULL AND x.status = 'completed') as actions_done")
                        ->orderByDesc('p.id');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;
                    if ($u !== null) {
                        $q->where('p.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('p.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'talent.career_paths',
                'module' => 'talent_management',
                'label' => 'Career paths',
                'description' => 'Career paths with department, job family, status, how many steps (job roles) each has and how many development plans follow it.',
                'arguments' => [
                    self::arg('status', 'string', 'e.g. active or draft.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $q = DB::table('s_competency_career_paths as c')
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'c.department_id')
                        ->where('c.sub_institute_id', $scope->selectedInstituteId)
                        ->whereNull('c.deleted_at')
                        ->select(['c.id as career_path_id', 'c.name as career_path'])
                        ->selectRaw('COALESCE(d.department, c.department) as department')
                        ->addSelect(['c.job_family', 'c.status'])
                        ->selectRaw('(SELECT COUNT(*) FROM s_competency_career_path_steps s WHERE s.career_path_id = c.id AND s.deleted_at IS NULL) as steps')
                        ->selectRaw('(SELECT COUNT(*) FROM s_competency_development_plans p WHERE p.career_path_id = c.id AND p.deleted_at IS NULL) as plans')
                        ->orderBy('c.name');

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('c.status', $s);
                    }

                    return $q;
                },
            ],

            // ---- Certifications / My Certifications -------------------------------------------------------
            [
                'name' => 'talent.certifications',
                'module' => 'talent_management',
                'label' => 'Certifications',
                'description' => 'Employee certifications with issuer, type, status, verification status, issue and expiry dates and days to expiry. A non-administrator sees only their own. Credential numbers are not included.',
                'arguments' => [
                    self::arg('status', 'string', 'valid, expired or revoked.'),
                    self::arg('user_id', 'integer', 'Only this employee (administrators only).'),
                    self::arg('expiring_within_days', 'integer', 'Only certifications that expire within this many days.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('s_competency_certifications as c')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'c.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->where('c.sub_institute_id', $tenant)
                        ->whereNull('c.deleted_at')
                        ->select(['c.id as certification_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->addSelect(['c.name as certification', 'c.issuing_body', 'c.certification_type as type', 'c.status', 'c.verification_status', 'c.issued_date', 'c.expiry_date'])
                        ->selectRaw('DATEDIFF(c.expiry_date, CURDATE()) as days_to_expiry')
                        ->orderByRaw('CASE WHEN c.expiry_date IS NULL THEN 1 ELSE 0 END, c.expiry_date ASC, c.id DESC');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;
                    if ($u !== null) {
                        $q->where('c.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('c.status', $s);
                    }
                    if (($d = $this->int($a, 'expiring_within_days')) !== null) {
                        $q->whereNotNull('c.expiry_date')->whereRaw('DATEDIFF(c.expiry_date, CURDATE()) <= ?', [$d]);
                    }

                    return $q;
                },
            ],

            // ---- Offboarding (HR only) --------------------------------------------------------------------
            [
                'name' => 'talent.offboarding_cases',
                'module' => 'talent_management',
                'label' => 'Offboarding cases',
                'description' => 'Exit cases with the employee, department, exit type and reason, notice and last working dates, status and whether the exit interview is done. HR and administrators only. Exit-interview notes are not included.',
                'arguments' => [
                    self::arg('status', 'string', 'e.g. Notice Period, Clearance, Completed.'),
                    self::arg('exit_type', 'string', 'voluntary or involuntary.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('talent_offboarding_cases as c')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'c.employee_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'c.department_id')
                        ->where('c.sub_institute_id', $tenant)
                        ->whereNull('c.deleted_at')
                        ->select(['c.id as case_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as employee")
                        ->addSelect(['d.department', 'c.exit_type', 'c.exit_reason', 'c.notice_date', 'c.last_working_day', 'c.status', 'c.exit_interview_done'])
                        ->orderByDesc('c.last_working_day');

                    $this->hrOnly($q, $scope);

                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('c.status', $s);
                    }
                    if (($t = $this->text($a, 'exit_type')) !== null) {
                        $q->where('c.exit_type', $t);
                    }

                    return $q;
                },
            ],

            // ---- My Capability (always the caller) --------------------------------------------------------
            [
                'name' => 'talent.my_capability',
                'module' => 'talent_management',
                'label' => 'My capability requirements',
                'description' => "The signed-in person's own job role: each competency it requires, the proficiency expected, whether it is mandatory and how many of its KASBA items they have rated. Always the caller's own, for everyone.",
                'arguments' => [$limitArg],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;

                    return DB::table('tbluser as u')
                        ->join('jobrole_competency_map as m', function ($join) use ($tenant) {
                            $join->whereRaw('m.jobrole_id = ' . self::USER_JOBROLE_ID)
                                ->where('m.sub_institute_id', '=', $tenant);
                        })
                        ->join('competency as c', function ($join) use ($tenant) {
                            $join->on('c.id', '=', 'm.competency_id')
                                ->where('c.sub_institute_id', '=', $tenant)
                                ->whereNull('c.deleted_at');
                        })
                        ->leftJoin('s_user_jobrole as j', 'j.id', '=', 'm.jobrole_id')
                        ->where('u.id', $scope->userId)
                        ->where('u.sub_institute_id', $tenant)
                        ->select(['j.jobrole as job_role', 'c.name as competency', 'c.code', 'm.required_proficiency', 'm.is_mandatory'])
                        ->selectRaw('(SELECT COUNT(*) FROM competency_kasba_item k WHERE k.competency_id = c.id AND k.sub_institute_id = m.sub_institute_id) as kasba_items')
                        ->selectRaw('(SELECT COUNT(DISTINCT r.kasba_item_id) FROM competency_kasba_rating r JOIN competency_kasba_item k2 ON k2.id = r.kasba_item_id WHERE k2.competency_id = c.id AND r.user_id = u.id AND r.sub_institute_id = m.sub_institute_id) as items_rated')
                        ->orderBy('c.name');
                },
            ],

            // ---- Administration ---------------------------------------------------------------------------
            [
                'name' => 'talent.admin_audit_log',
                'module' => 'talent_administration',
                'label' => 'Talent audit trail',
                'description' => 'The append-only record of what happened in Talent: event type, the record it touched, who did it and when. Event payloads are not included. Administrators only.',
                'arguments' => [
                    self::arg('entity_type', 'string', 'Only events about this kind of record.'),
                    self::arg('type', 'string', 'Only this event type.'),
                    $limitArg,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('g2g_event as e')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'e.actor_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->where('e.sub_institute_id', $tenant)
                        ->select(['e.id as event_id', 'e.type as event', 'e.entity_type', 'e.entity_id'])
                        ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), 'System') as actor")
                        ->addSelect('e.occurred_at')
                        ->orderByDesc('e.occurred_at')
                        ->orderByDesc('e.id');

                    if (! $scope->isAdmin) {
                        $q->whereRaw('1 = 0');
                    }
                    if (($t = $this->text($a, 'type')) !== null) {
                        $q->where('e.type', $t);
                    }
                    if (($t = $this->text($a, 'entity_type')) !== null) {
                        $q->where('e.entity_type', $t);
                    }

                    return $q;
                },
            ],
        ];
    }

    public function pages(): array
    {
        return [
            '/module/talent-management/talent-dashboard' => [
                'sources' => ['talent.hiring_funnel', 'talent.job_postings', 'talent.onboarding_journeys', 'talent.offboarding_cases', 'talent.mobility_transfers'],
                'purpose' => 'A one-screen summary of hiring, onboarding, exits and internal moves across the organisation.',
                'action' => null,
            ],
            '/module/talent-management/recruitment' => [
                'sources' => ['talent.pipeline', 'talent.job_postings', 'talent.hiring_funnel', 'talent.interviews', 'talent.offers'],
                'purpose' => 'Manage job postings, candidates, interviews and offers from application to hire.',
                'action' => 'create_job_posting',
            ],
            '/module/talent-management/onboarding' => [
                'sources' => ['talent.onboarding_journeys', 'talent.onboarding_tasks'],
                'purpose' => 'Track each new hire from offer acceptance through joining tasks to probation confirmation.',
                'action' => null,
            ],
            '/module/talent-management/mobility-and-succession' => [
                'sources' => ['talent.mobility_jobs', 'talent.mobility_transfers'],
                'purpose' => 'Run the internal job board and record transfers between departments and roles.',
                'action' => null,
            ],
            '/module/talent-management/employee-profiles' => [
                'sources' => ['talent.employee_profiles'],
                'purpose' => "Open an employee's capability profile: skills, certifications, development plans and notes.",
                'action' => null,
            ],
            '/module/talent-management/development-and-career-paths' => [
                'sources' => ['talent.development_plans', 'talent.career_paths'],
                'purpose' => 'Plan individual development and define the career paths between job roles.',
                'action' => null,
            ],
            '/module/talent-management/certifications' => [
                'sources' => ['talent.certifications'],
                'purpose' => 'Track which certifications employees hold, whether they are verified and when they expire.',
                'action' => null,
            ],
            '/module/talent-management/offboarding' => [
                'sources' => ['talent.offboarding_cases'],
                'purpose' => 'Manage exits from notice period through clearance to the exit interview.',
                'action' => null,
            ],
            '/module/talent-management/administration' => [
                'sources' => ['talent.workflows', 'talent.admin_audit_log'],
                'purpose' => 'Configure the hiring and talent workflows and review the audit trail of changes.',
                'action' => null,
            ],
            '/module/talent-management/talent-pool-management/internal-and-external-talent-database' => [
                'sources' => [],
                'purpose' => 'A combined database of internal employees and external candidates (a menu entry only).',
                'action' => null,
                'no_data_reason' => 'This menu row has no page behind it: its parent menu no longer exists and the frontend has no screen for it. The talent pool tables (s_mobility_talent_pools) are empty.',
            ],
            '/module/talent-management/talent-pool-management/ai-based-recommendations' => [
                'sources' => [],
                'purpose' => 'AI-suggested matches between people and roles (a menu entry only).',
                'action' => null,
                'no_data_reason' => 'This menu row has no page behind it: its parent menu no longer exists and the frontend has no screen for it, so there is no data to read.',
            ],
            '/module/talent-management/capability-progress' => [
                'sources' => [],
                'purpose' => "Show how a person's competency ratings have moved over time and which courses moved them.",
                'action' => null,
                'no_data_reason' => 'The page reads competency_rating_history, which is empty for every organisation in this database, so there is nothing to show yet. It fills as ratings change.',
            ],
            '/module/talent-management/my-certifications' => [
                'sources' => ['talent.certifications'],
                'purpose' => 'See your own certifications, whether they are current and what is about to expire.',
                'action' => null,
            ],
            '/module/talent-management/my-capability' => [
                'sources' => ['talent.my_capability'],
                'purpose' => 'See the competencies your job role needs and how much of it you have rated yourself on.',
                'action' => null,
            ],
        ];
    }

    /** HR-only pages: a caller who is not an administrator gets no rows. */
    private function hrOnly(Builder $query, AiRequestScope $scope): void
    {
        if (! $scope->isAdmin) {
            $query->whereRaw('1 = 0');
        }
    }
}
