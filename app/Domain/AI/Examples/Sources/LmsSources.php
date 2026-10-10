<?php

namespace App\Domain\AI\Examples\Sources;

use App\Services\Ai\AiRequestScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Lms module's data sources and page examples. See SourceGroup for the rules every source follows.
 *
 * Sources that are about one learner (assignments, completions, certificates, assessments, the enrolment
 * summary) follow the same rule as `lms.my_enrolments`: a caller who is not an administrator is pinned to
 * their own rows whatever arguments they pass; an administrator sees the organisation and may narrow to
 * one learner. Sessions, quizzes and the course catalogue are shown to everybody on their pages, so they
 * are not pinned. Role counts are an administration view and return nothing for a non-administrator.
 */
final class LmsSources extends SourceGroup
{
    public function definitions(): array
    {
        $limit = self::limitArg();

        return [
            [
                'name' => 'lms.assignments',
                'module' => 'lms',
                'label' => 'Learning assignments',
                'description' => 'Courses assigned to learners with the assignment type, due date, status, approval state and progress.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this learner. Ignored for a caller who is not an administrator.'),
                    self::arg('status', 'string', 'Not Started, In Progress, Completed or Overdue.'),
                    self::arg('approval_status', 'string', 'approved, pending or rejected.'),
                    self::arg('assignment_type', 'string', 'Mandatory, Recommended or Optional.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('lms_assignments as a')
                        ->join('sub_std_map as c', 'c.id', '=', 'a.course_id')
                        ->join('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'a.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('a.sub_institute_id', $tenant)
                        ->whereNull('a.deleted_at')
                        ->whereNull('c.deleted_at')
                        ->select(['a.id as assignment_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as learner")
                        ->addSelect(['d.department', 'c.display_name as course', 'a.assignment_type', 'a.due_date', 'a.status', 'a.approval_status', 'a.progress'])
                        ->orderByDesc('a.id');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;

                    if ($u !== null) {
                        $q->where('a.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('a.status', $s);
                    }
                    if (($s = $this->text($a, 'approval_status')) !== null) {
                        $q->where('a.approval_status', $s);
                    }
                    if (($s = $this->text($a, 'assignment_type')) !== null) {
                        $q->where('a.assignment_type', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.sessions',
                'module' => 'lms',
                'label' => 'Training sessions',
                'description' => 'Scheduled training sessions with date, time, type, trainer, venue, seats and how many people are registered.',
                'arguments' => [
                    self::arg('from', 'string', 'Only sessions on or after this date (YYYY-MM-DD).'),
                    self::arg('to', 'string', 'Only sessions on or before this date (YYYY-MM-DD).'),
                    self::arg('session_type', 'string', 'For example virtual or classroom.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('lms_virtual_classroom as v')
                        ->leftJoin('lms_trainers as t', function ($join) use ($tenant) {
                            $join->on('t.id', '=', 'v.trainer_id')->where('t.sub_institute_id', '=', $tenant)->whereNull('t.deleted_at');
                        })
                        ->where('v.sub_institute_id', $tenant)
                        ->whereNull('v.deleted_at')
                        ->select(['v.id as session_id', 'v.room_name as session', 'v.session_type', 'v.event_date', 'v.from_time', 'v.to_time', 'v.venue'])
                        ->selectRaw('COALESCE(t.name, v.trainer_name) as trainer')
                        ->addSelect('v.seats_total')
                        ->selectRaw("(SELECT COUNT(*) FROM lms_session_registrations r WHERE r.session_id = v.id AND r.sub_institute_id = v.sub_institute_id AND r.deleted_at IS NULL AND r.status IN ('registered', 'attended')) as registered")
                        ->orderByDesc('v.event_date')
                        ->orderBy('v.from_time');

                    if (($f = $this->text($a, 'from')) !== null) {
                        $q->where('v.event_date', '>=', $f);
                    }
                    if (($t = $this->text($a, 'to')) !== null) {
                        $q->where('v.event_date', '<=', $t);
                    }
                    if (($s = $this->text($a, 'session_type')) !== null) {
                        $q->where('v.session_type', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.certification_records',
                'module' => 'lms',
                'label' => 'Certificates issued',
                'description' => 'Certificates issued for completed courses with the learner, course, status, issue date and expiry.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this learner. Ignored for a caller who is not an administrator.'),
                    self::arg('status', 'string', 'Certificate status, for example active or revoked.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('lms_certificates as c')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'c.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->where('c.sub_institute_id', $tenant)
                        ->whereNull('c.deleted_at')
                        ->select(['c.id as certificate_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as learner")
                        ->addSelect(['c.course_title as course', 'c.status', 'c.issued_at', 'c.expires_at'])
                        ->selectRaw("CASE WHEN c.expires_at IS NULL THEN 'no expiry' WHEN c.expires_at < NOW() THEN 'expired' WHEN c.expires_at < DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 'expiring' ELSE 'active' END as expiry_state")
                        ->orderByDesc('c.issued_at');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;

                    if ($u !== null) {
                        $q->where('c.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('c.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.completion_records',
                'module' => 'lms',
                'label' => 'Completed courses (transcript)',
                'description' => 'Courses a learner has completed with the department, course and start and end dates. This is the transcript on Certifications & Records.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this learner. Ignored for a caller who is not an administrator.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('lms_course_enroll as e')
                        ->join('sub_std_map as s', 's.id', '=', 'e.course_id')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'e.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
                        ->where('s.sub_institute_id', $tenant)
                        ->where('e.sub_institute_id', $tenant)
                        ->where('e.status', 'completed')
                        ->whereNull('e.deleted_at')
                        ->whereNull('s.deleted_at')
                        ->select(['e.id as enrolment_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as learner")
                        ->addSelect(['d.department', 's.display_name as course', 'e.start_date', 'e.end_date'])
                        ->orderByDesc('e.id');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;

                    if ($u !== null) {
                        $q->where('e.user_id', $u);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.enrolment_summary',
                'module' => 'lms',
                'label' => 'Enrolments by status',
                'description' => 'How many enrolments are enrolled, in progress or completed, with the learners and courses behind each count.',
                'arguments' => [self::arg('user_id', 'integer', 'Only this learner. Ignored for a caller who is not an administrator.')],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('lms_course_enroll as e')
                        ->join('sub_std_map as s', 's.id', '=', 'e.course_id')
                        ->where('s.sub_institute_id', $tenant)
                        ->where('e.sub_institute_id', $tenant)
                        ->whereNull('e.deleted_at')
                        ->whereNull('s.deleted_at')
                        ->groupBy('e.status')
                        ->select(['e.status'])
                        ->selectRaw('COUNT(*) as enrolments')
                        ->selectRaw('COUNT(DISTINCT e.user_id) as learners')
                        ->selectRaw('COUNT(DISTINCT e.course_id) as courses')
                        ->orderByDesc('enrolments');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;

                    if ($u !== null) {
                        $q->where('e.user_id', $u);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.my_assessments',
                'module' => 'lms',
                'label' => 'Assessments assigned to people',
                'description' => 'Competency assessments given to learners with the cycle, status, review state, score, due date and completion date.',
                'arguments' => [
                    self::arg('user_id', 'integer', 'Only this person. Ignored for a caller who is not an administrator.'),
                    self::arg('status', 'string', 'open, in_progress, completed or overdue.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('s_competency_assessments as a')
                        ->leftJoin('s_competency_assessment_cycles as c', function ($join) {
                            $join->on('c.id', '=', 'a.cycle_id')->whereNull('c.deleted_at');
                        })
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.id', '=', 'a.user_id')->where('u.sub_institute_id', '=', $tenant);
                        })
                        ->where('a.sub_institute_id', $tenant)
                        ->whereNull('a.deleted_at')
                        ->select(['a.id as assessment_id'])
                        ->selectRaw("TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as person")
                        ->addSelect(['a.title as assessment', 'c.name as cycle', 'a.status', 'a.review_status', 'a.score', 'a.due_date', 'a.completed_at'])
                        ->orderByDesc('a.id');

                    $u = $scope->isAdmin ? $this->int($a, 'user_id') : $scope->userId;

                    if ($u !== null) {
                        $q->where('a.user_id', $u);
                    }
                    if (($s = $this->text($a, 'status')) !== null) {
                        $q->where('a.status', $s);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.course_quizzes',
                'module' => 'lms_course_builder',
                'label' => 'Course quizzes',
                'description' => 'Quizzes built for courses with the quiz type, number of questions, total marks, time allowed and open and close dates.',
                'arguments' => [
                    self::arg('course_id', 'integer', 'Only quizzes of this course.'),
                    $limit,
                ],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('question_paper as q')
                        ->leftJoin('sub_std_map as s', function ($join) use ($tenant) {
                            $join->on('s.id', '=', 'q.subject_id')->where('s.sub_institute_id', '=', $tenant);
                        })
                        ->where('q.sub_institute_id', $tenant)
                        ->whereNull('q.deleted_at')
                        ->select(['q.id as quiz_id', 's.display_name as course', 'q.paper_name as quiz', 'q.exam_type', 'q.total_ques as questions', 'q.total_marks', 'q.time_allowed as minutes_allowed', 'q.open_date', 'q.close_date'])
                        ->orderByDesc('q.id');

                    if (($c = $this->int($a, 'course_id')) !== null) {
                        $q->where('q.subject_id', $c);
                    }

                    return $q;
                },
            ],
            [
                'name' => 'lms.access_roles',
                'module' => 'lms',
                'label' => 'Roles and how many people hold them',
                'description' => 'The organisation\'s roles with the number of users and active users in each. Administrators only; empty for everyone else.',
                'arguments' => [$limit],
                'query' => function (AiRequestScope $scope, array $a): Builder {
                    $tenant = $scope->selectedInstituteId;
                    $q = DB::table('tbluserprofilemaster as p')
                        ->leftJoin('tbluser as u', function ($join) use ($tenant) {
                            $join->on('u.user_profile_id', '=', 'p.id')->where('u.sub_institute_id', '=', $tenant)->whereNull('u.deleted_at');
                        })
                        ->where('p.sub_institute_id', $tenant)
                        ->whereNull('p.deleted_at')
                        ->groupBy('p.id', 'p.name', 'p.status')
                        ->select(['p.id as role_id', 'p.name as role', 'p.status'])
                        ->selectRaw('COUNT(u.id) as users')
                        ->selectRaw('COALESCE(SUM(CASE WHEN u.status = 1 THEN 1 ELSE 0 END), 0) as active_users')
                        ->orderBy('p.sort_order')
                        ->orderBy('p.id');

                    if (! $scope->isAdmin) {
                        $q->whereRaw('1 = 0');
                    }

                    return $q;
                },
            ],
        ];
    }

    public function pages(): array
    {
        return [
            '/module/lms/learning' => [
                'sources' => ['lms.my_enrolments', 'lms.catalog'],
                'purpose' => 'The Learning group: the dashboard, the course catalogue, your own learning and your assessments.',
                'action' => null,
            ],
            '/module/lms/training-and-records' => [
                'sources' => ['lms.assignments', 'lms.sessions', 'lms.completion_records'],
                'purpose' => 'The Training & Records group: assignments, sessions and the certificates and records earned.',
                'action' => null,
            ],
            '/module/lms/administration' => [
                'sources' => ['lms.course_builder', 'lms.course_quizzes'],
                'purpose' => 'The Administration group: building courses and governing the learning platform.',
                'action' => null,
            ],
            '/module/lms/assessments' => [
                'sources' => ['lms.assessment_cycles', 'lms.my_assessments'],
                'purpose' => 'Run assessment cycles and see who has completed, is overdue or has not started.',
                'action' => null,
            ],
            '/module/lms/learning/learning-dashboard' => [
                'sources' => ['lms.enrolment_summary', 'lms.my_enrolments', 'lms.catalog'],
                'purpose' => 'A learner\'s overview: enrolments by status, courses in progress and what to learn next.',
                'action' => null,
            ],
            '/module/lms/learning/learning-catalog' => [
                'sources' => ['lms.catalog'],
                'purpose' => 'Browse the active courses by category, job role and proficiency and enrol or request enrolment.',
                'action' => 'request_enrollment',
            ],
            '/module/lms/learning/my-learning' => [
                'sources' => ['lms.my_enrolments', 'lms.completion_records'],
                'purpose' => 'Your enrolled courses with progress, where you open lessons, take quizzes and finish courses.',
                'action' => null,
            ],
            '/module/lms/learning/my-assessment' => [
                'sources' => ['lms.my_assessments'],
                'purpose' => 'The assessments assigned to you, with their status, due date and score.',
                'action' => null,
            ],
            '/module/lms/training-and-records/assignments' => [
                'sources' => ['lms.assignments'],
                'purpose' => 'Assign courses to people, follow their progress and approve or reject enrolment requests.',
                'action' => 'review_enrollment_request',
            ],
            '/module/lms/training-and-records/sessions-and-calendar' => [
                'sources' => ['lms.sessions'],
                'purpose' => 'The calendar of training sessions with seats, trainers and registrations.',
                'action' => null,
            ],
            '/module/lms/training-and-records/certifications-and-records' => [
                'sources' => ['lms.certification_records', 'lms.completion_records'],
                'purpose' => 'Certificates earned with their expiry, and the transcript of completed courses.',
                'action' => null,
            ],
            '/module/lms/administration/course-builder' => [
                'sources' => ['lms.course_builder', 'lms.course_quizzes'],
                'purpose' => 'Create and edit courses, their modules, lessons, quizzes and audience.',
                'action' => 'edit_course',
            ],
            '/module/lms/administration/administration-and-governance' => [
                'sources' => ['lms.access_roles'],
                'purpose' => 'Administer learning users, roles, permissions, trainers, vendors and integrations.',
                'action' => null,
            ],
        ];
    }
}
