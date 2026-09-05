<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Http\Controllers\Controller;
use App\Services\Talent\CandidateAssessmentService;
use App\Services\Talent\RecruitmentAssessmentGenerator;
use App\Support\CandidateLink;
use App\Support\MailGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * The HR side of candidate assessment: blueprints, invitations, results.
 *
 * ── WHERE THE TENANT COMES FROM ─────────────────────────────────────────────
 *
 * From the token's owner, via talentContext(). Never from a request parameter.
 * Every read and every write in this class is scoped to it, and a row belonging
 * to another organisation returns 404 rather than 403 - a 403 confirms the row
 * exists, which is itself a leak.
 *
 * ── WHAT A BLUEPRINT IS ─────────────────────────────────────────────────────
 *
 * The standing decision for a job role: which kinds of test, how many questions,
 * the total marks, and the mark a candidate must reach. It is NOT a test - the
 * test is generated per invitation, so two candidates for the same role do not
 * sit an identical paper they could pass between them.
 */
class TalentAssessmentController extends Controller
{
    use ResolvesTalentContext;

    public function __construct(
        private CandidateAssessmentService $assessments,
        private RecruitmentAssessmentGenerator $generator,
    ) {
    }

    /** GET /api/talent/assessment/blueprints */
    public function blueprints(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('talent_assessment_blueprints as b')
            ->leftJoin('s_jobrole as r', 'r.id', '=', 'b.jobrole_id')
            ->leftJoin('hrms_departments as d', function ($join) use ($sid) {
                $join->on('d.id', '=', 'b.department_id')->where('d.sub_institute_id', '=', $sid);
            })
            ->where('b.sub_institute_id', $sid)
            ->whereNull('b.deleted_at')
            ->orderBy('b.id', 'desc')
            ->get([
                'b.id', 'b.department_id', 'b.jobrole_id', 'b.title', 'b.test_types',
                'b.question_count', 'b.total_marks', 'b.qualification_marks',
                'b.time_limit_minutes', 'b.is_active', 'b.updated_at',
                'r.jobrole', 'r.sector', 'd.department as department_name',
            ]);

        return response()->json([
            'status' => 1,
            'data' => $rows->map(fn ($b) => [
                'id'                  => (int) $b->id,
                'department_id'       => $b->department_id ? (int) $b->department_id : null,
                'department_name'     => $b->department_name,
                'jobrole_id'          => (int) $b->jobrole_id,
                'jobrole'             => $b->jobrole,
                'sector'              => $b->sector,
                'title'               => $b->title,
                // Sent as an array so the client never parses the comma list itself.
                'test_types'          => array_values(array_filter(explode(',', (string) $b->test_types))),
                'question_count'      => (int) $b->question_count,
                'total_marks'         => (int) $b->total_marks,
                'qualification_marks' => (int) $b->qualification_marks,
                'time_limit_minutes'  => $b->time_limit_minutes ? (int) $b->time_limit_minutes : null,
                'is_active'           => (bool) $b->is_active,
                'updated_at'          => $b->updated_at,
            ]),
            'test_types' => RecruitmentAssessmentGenerator::TEST_TYPES,
        ], 200);
    }

    /** POST /api/talent/assessment/blueprints — create or update one. */
    public function storeBlueprint(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $actor = $context['user_id'];

        $validator = Validator::make($request->all(), [
            'id'                  => 'nullable|integer',
            'department_id'       => 'nullable|integer',
            'jobrole_id'          => 'required|integer',
            'title'               => 'nullable|string|max:191',
            'test_types'          => 'required|array|min:1',
            'test_types.*'        => 'string|in:' . implode(',', array_keys(RecruitmentAssessmentGenerator::TEST_TYPES)),
            'question_count'      => 'required|integer|min:1|max:50',
            'total_marks'         => 'required|integer|min:1|max:1000',
            /*
             * The pass mark cannot exceed the total. Without this, HR can set a
             * threshold nobody can reach and every candidate is held for manual
             * review with no explanation on screen.
             */
            'qualification_marks' => 'required|integer|min:0|lte:total_marks',
            'time_limit_minutes'  => 'nullable|integer|min:5|max:480',
            'is_active'           => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (!DB::table('s_jobrole')->where('id', $request->integer('jobrole_id'))->exists()) {
            return response()->json(['status' => 0, 'message' => 'That job role is not in the catalogue.'], 422);
        }

        $values = [
            'department_id'       => $request->filled('department_id') ? $request->integer('department_id') : null,
            'jobrole_id'          => $request->integer('jobrole_id'),
            'title'               => $request->input('title'),
            'test_types'          => implode(',', $request->input('test_types')),
            'question_count'      => $request->integer('question_count'),
            'total_marks'         => $request->integer('total_marks'),
            'qualification_marks' => $request->integer('qualification_marks'),
            'time_limit_minutes'  => $request->filled('time_limit_minutes') ? $request->integer('time_limit_minutes') : null,
            'is_active'           => $request->boolean('is_active', true) ? 1 : 0,
            'updated_by'          => $actor,
            'updated_at'          => now(),
        ];

        if ($request->filled('id')) {
            // Tenant-scoped, so an id from another organisation updates nothing
            // and reports not-found rather than silently succeeding (F-67).
            $existing = DB::table('talent_assessment_blueprints')
                ->where('id', $request->integer('id'))->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')->first(['id']);

            if (!$existing) {
                return response()->json(['status' => 0, 'message' => 'Blueprint not found.'], 404);
            }

            DB::table('talent_assessment_blueprints')->where('id', $existing->id)->update($values);
            $id = (int) $existing->id;
        } else {
            /*
             * ONE BLUEPRINT PER ROLE PER ORGANISATION - `tab_tenant_jobrole_unique`.
             *
             * Checked here rather than left to the constraint, because the
             * constraint's answer is a 500 and an SQLSTATE. HR needs to be told
             * that a blueprint already exists and which one, so they can edit it
             * instead of guessing why saving failed.
             */
            $clash = DB::table('talent_assessment_blueprints')
                ->where('sub_institute_id', $sid)
                ->where('jobrole_id', $request->integer('jobrole_id'))
                ->whereNull('deleted_at')
                ->first(['id', 'title']);

            if ($clash) {
                return response()->json([
                    'status' => 0,
                    'message' => 'This job role already has a blueprint'
                        . ($clash->title ? ' ("' . $clash->title . '")' : '')
                        . '. Edit that one rather than adding a second.',
                    'reason' => 'duplicate_jobrole',
                    'data' => ['id' => (int) $clash->id],
                ], 422);
            }

            $id = (int) DB::table('talent_assessment_blueprints')->insertGetId($values + [
                'sub_institute_id' => $sid,
                'created_by'       => $actor,
                'created_at'       => now(),
            ]);
        }

        return response()->json(['status' => 1, 'message' => 'Blueprint saved.', 'data' => ['id' => $id]], 200);
    }

    /** DELETE /api/talent/assessment/blueprints/{id} — soft delete. */
    public function destroyBlueprint(Request $request, int $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $deleted = DB::table('talent_assessment_blueprints')
            ->where('id', $id)->where('sub_institute_id', (int) $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_by' => $context['user_id'], 'updated_at' => now()]);

        return $deleted
            ? response()->json(['status' => 1, 'message' => 'Blueprint removed.'], 200)
            : response()->json(['status' => 0, 'message' => 'Blueprint not found.'], 404);
    }

    /**
     * GET /api/talent/assessment/jobroles?sector=&q=
     *
     * The catalogue picker. `s_jobrole` is a GLOBAL reference table with no
     * sub_institute_id - every organisation sees the same 3,347 roles, which is
     * correct and is not a tenancy leak: it carries no customer data.
     */
    public function jobroles(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sector = trim((string) $request->query('sector', ''));
        $q = trim((string) $request->query('q', ''));

        $roles = DB::table('s_jobrole')
            ->where('status', 'Active')
            ->when($sector !== '' && $sector !== 'all', fn ($b) => $b->where('sector', $sector))
            ->when($q !== '', fn ($b) => $b->where('jobrole', 'like', '%' . $q . '%'))
            ->orderBy('jobrole')
            ->limit(200)
            ->get(['id', 'jobrole', 'sector', 'track']);

        return response()->json([
            'status' => 1,
            'data' => $roles,
            'sectors' => DB::table('s_jobrole')->where('status', 'Active')
                ->distinct()->orderBy('sector')->pluck('sector'),
        ], 200);
    }

    /**
     * POST /api/talent/applications/{id}/assessment/invite
     *
     * Generates a paper for this candidate, mints their link and emails it.
     *
     * GENERATION HAPPENS BEFORE THE INVITE IS RECORDED. If the model fails, the
     * candidate has no half-made invitation and HR sees why - the alternative is
     * a link that opens onto an empty test.
     */
    public function invite(Request $request, int $applicationId)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $actor = $context['user_id'];

        $application = DB::table('talent_job_applications')
            ->where('id', $applicationId)->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first(['id', 'first_name', 'email', 'candidate_id', 'job_id', 'status']);

        if (!$application) {
            return response()->json(['status' => 0, 'message' => 'Application not found.'], 404);
        }

        if (!$application->email) {
            return response()->json([
                'status' => 0,
                'message' => 'This application has no email address, so an assessment link cannot be sent.',
            ], 422);
        }

        $blueprint = $this->blueprintFor($application, $sid, $request->input('blueprint_id'));

        if (!$blueprint) {
            return response()->json([
                'status' => 0,
                'message' => 'No assessment blueprint matches this role. Create one first, then invite.',
                'reason' => 'no_blueprint',
            ], 422);
        }

        try {
            $testId = $this->generator->generate($blueprint, $sid, $actor);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 0,
                'message' => 'The assessment could not be generated: ' . $e->getMessage(),
                'reason' => 'generation_failed',
            ], 502);
        }

        $minted = $this->assessments->mint(
            $applicationId,
            $testId,
            (int) $blueprint->id,
            $sid,
            $actor,
            $application->candidate_id ? (int) $application->candidate_id : $applicationId
        );

        // Built from the FRONT END origin, not this API's - /assessment/{token}
        // is a Next.js page and Laravel has no route for it (F-89).
        $url = CandidateLink::to('assessment', $minted['token']);

        $mail = ['sent' => false, 'error' => MailGate::reasonForTenant($sid)];

        /*
         * A link that points at this API cannot open, so sending it would tell
         * HR the candidate has been contacted when they have been handed a 404.
         * The assessment itself is still created and the link still returned -
         * HR can copy it - but the email is held back and the reason is said.
         */
        if (CandidateLink::pointsAtApi()) {
            $mail = [
                'sent' => false,
                'error' => 'FRONTEND_URL is not set, so the assessment link would point at the API '
                    . 'and not open. Set it, or copy the link to the candidate yourself.',
            ];
        } elseif (MailGate::allowedForTenant($sid)) {
            try {
                Mail::raw(
                    'Hello ' . ($application->first_name ?: 'there') . ",\n\n"
                    . "The next step in your application is a short assessment. You can start it here:\n\n"
                    . $url . "\n\n"
                    . 'This link is personal to you and expires on ' . $minted['expires_at']->format('j M Y') . ".\n"
                    . "You can save your progress and come back, but it can only be submitted once.\n",
                    function ($m) use ($application) {
                        $m->to($application->email)->subject('Your assessment');
                    }
                );
                $mail = ['sent' => true, 'error' => null];
            } catch (\Throwable $e) {
                // A failed send must never lose the link - HR can still copy it.
                $mail = ['sent' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'status' => 1,
            'message' => $mail['sent']
                ? 'Assessment created and emailed to the candidate.'
                : 'Assessment created. Copy the link to the candidate - email was not sent.',
            'data' => [
                'application_id' => $applicationId,
                'test_id'        => $testId,
                'url'            => $url,
                'expires_at'     => $minted['expires_at']->toDateTimeString(),
                'email_sent'     => $mail['sent'],
                'email_error'    => $mail['error'],
            ],
        ], 200);
    }

    /**
     * GET /api/talent/assessment/candidates
     *
     * Every assessment this organisation has sent, with who sent it and how it
     * ended. The counts are computed over the FILTERED set, so narrowing by
     * status narrows the tiles too - a tile that disagrees with the table
     * underneath it is worse than no tile at all.
     */
    public function candidates(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $perPage = min(max((int) $request->input('per_page', 25), 5), 100);
        $page = max((int) $request->input('page', 1), 1);

        $base = DB::table('talent_candidate_assessments as a')
            ->join('talent_job_applications as ap', function ($join) use ($sid) {
                $join->on('ap.id', '=', 'a.application_id')->where('ap.sub_institute_id', '=', $sid);
            })
            ->leftJoin('talent_job_postings as jp', function ($join) use ($sid) {
                $join->on('jp.id', '=', 'ap.job_id')->where('jp.sub_institute_id', '=', $sid);
            })
            ->leftJoin('talent_assessment_blueprints as b', 'b.id', '=', 'a.blueprint_id')
            ->leftJoin('competency_assessment_test as t', 't.id', '=', 'a.test_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.created_by')
            ->where('a.sub_institute_id', $sid)
            ->whereNull('a.deleted_at')
            ->whereNull('ap.deleted_at');

        if ($request->filled('status')) {
            $base->where('a.status', $request->input('status'));
        }

        if ($request->filled('job_id')) {
            $base->where('ap.job_id', $request->integer('job_id'));
        }

        /*
         * Tri-state, matching the column: pass, fail, and pending for a paper
         * still being marked. Folding pending into fail would report a candidate
         * as rejected while the model is still reading their answers.
         */
        if ($request->filled('outcome')) {
            $outcome = (string) $request->input('outcome');
            if ($outcome === 'pass') {
                $base->where('a.qualified', 1);
            } elseif ($outcome === 'fail') {
                $base->where('a.qualified', 0);
            } elseif ($outcome === 'pending') {
                $base->whereNull('a.qualified');
            }
        }

        if ($request->filled('search')) {
            $term = '%' . addcslashes(trim((string) $request->input('search')), '%_\\') . '%';
            $base->where(function ($q) use ($term) {
                $q->where('ap.first_name', 'like', $term)
                  ->orWhere('ap.last_name', 'like', $term)
                  ->orWhere('ap.email', 'like', $term)
                  ->orWhere('jp.title', 'like', $term);
            });
        }

        $total = (clone $base)->count();

        $tally = (clone $base)->selectRaw(
            "SUM(a.status = 'invited') invited,"
            . " SUM(a.status = 'started') started,"
            . " SUM(a.status = 'submitted') submitted,"
            . " SUM(a.status = 'graded') graded,"
            . ' SUM(a.qualified = 1) passed,'
            . ' SUM(a.qualified = 0) failed,'
            . ' AVG(a.percent) avg_percent'
        )->first();

        $rows = (clone $base)
            ->orderByDesc('a.invited_at')->orderByDesc('a.id')
            ->forPage($page, $perPage)
            ->get([
                'a.id', 'a.application_id', 'a.status', 'a.score', 'a.max_score', 'a.percent',
                'a.qualified', 'a.invited_at', 'a.submitted_at', 'a.graded_at',
                'a.token_expires_at', 'a.token_used_at',
                'ap.first_name', 'ap.last_name', 'ap.email', 'ap.status as application_status',
                'jp.id as job_id', 'jp.title as job_title',
                'b.title as blueprint_title', 'b.test_types', 'b.qualification_marks',
                't.title as paper_title',
                'u.first_name as invited_by_first', 'u.last_name as invited_by_last',
            ]);

        return response()->json([
            'status' => 1,
            'data' => $rows->map(fn ($r) => [
                'id'             => (int) $r->id,
                'application_id' => (int) $r->application_id,
                'candidate'      => trim(($r->first_name ?: '') . ' ' . ($r->last_name ?: '')) ?: 'Unnamed',
                'email'          => $r->email,
                'job_id'         => $r->job_id ? (int) $r->job_id : null,
                'job_title'      => $r->job_title,
                'blueprint'      => $r->blueprint_title,
                'paper'          => $r->paper_title,
                'test_types'     => array_values(array_filter(explode(',', (string) $r->test_types))),
                'status'         => $r->status,
                'score'          => $r->score === null ? null : (float) $r->score,
                'max_score'      => $r->max_score === null ? null : (float) $r->max_score,
                'percent'        => $r->percent === null ? null : (float) $r->percent,
                'pass_mark'      => $r->qualification_marks === null ? null : (int) $r->qualification_marks,
                'qualified'      => $r->qualified === null ? null : (bool) $r->qualified,
                // Who pressed the button. An audit answer, not decoration.
                'invited_by'     => trim(($r->invited_by_first ?: '') . ' ' . ($r->invited_by_last ?: '')) ?: null,
                'invited_at'     => $r->invited_at,
                'submitted_at'   => $r->submitted_at,
                'graded_at'      => $r->graded_at,
                'expires_at'     => $r->token_expires_at,
                'link_used'      => $r->token_used_at !== null,
                'application_status' => $r->application_status,
            ]),
            'summary' => [
                'total'       => $total,
                'invited'     => (int) ($tally->invited ?? 0),
                'started'     => (int) ($tally->started ?? 0),
                'submitted'   => (int) ($tally->submitted ?? 0),
                'graded'      => (int) ($tally->graded ?? 0),
                'passed'      => (int) ($tally->passed ?? 0),
                'failed'      => (int) ($tally->failed ?? 0),
                'avg_percent' => $tally->avg_percent === null ? null : round((float) $tally->avg_percent, 1),
            ],
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
            'statuses' => [
                CandidateAssessmentService::STATUS_INVITED,
                CandidateAssessmentService::STATUS_STARTED,
                CandidateAssessmentService::STATUS_SUBMITTED,
                CandidateAssessmentService::STATUS_GRADED,
            ],
            'jobs' => DB::table('talent_job_postings')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn ($j) => ['id' => (int) $j->id, 'title' => $j->title]),
        ], 200);
    }

    /**
     * GET /api/talent/assessment/applications
     *
     * Who an assessment can be sent to, for the picker.
     *
     * Reports whether each candidate already HAS an assessment and whether a
     * blueprint covers their role, so the screen can say why one cannot be
     * invited BEFORE the button is pressed - rather than surfacing a 422
     * afterwards, which is all the candidate panel does today.
     */
    public function applications(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $query = DB::table('talent_job_applications as ap')
            ->leftJoin('talent_job_postings as jp', function ($join) use ($sid) {
                $join->on('jp.id', '=', 'ap.job_id')->where('jp.sub_institute_id', '=', $sid);
            })
            ->leftJoin('talent_candidate_assessments as a', function ($join) use ($sid) {
                $join->on('a.application_id', '=', 'ap.id')
                     ->where('a.sub_institute_id', '=', $sid)
                     ->whereNull('a.deleted_at');
            })
            ->leftJoin('talent_assessment_blueprints as b', function ($join) use ($sid) {
                $join->on('b.jobrole_id', '=', 'jp.jobrole_id')
                     ->where('b.sub_institute_id', '=', $sid)
                     ->where('b.is_active', '=', 1)
                     ->whereNull('b.deleted_at');
            })
            ->where('ap.sub_institute_id', $sid)
            ->whereNull('ap.deleted_at');

        if ($request->filled('search')) {
            $term = '%' . addcslashes(trim((string) $request->input('search')), '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('ap.first_name', 'like', $term)
                  ->orWhere('ap.last_name', 'like', $term)
                  ->orWhere('ap.email', 'like', $term);
            });
        }

        if ($request->boolean('without_assessment')) {
            $query->whereNull('a.id');
        }

        $rows = $query->orderByDesc('ap.id')->limit(200)->get([
            'ap.id', 'ap.first_name', 'ap.last_name', 'ap.email', 'ap.status', 'ap.applied_date',
            'jp.id as job_id', 'jp.title as job_title', 'jp.jobrole_id',
            'a.id as assessment_id', 'a.status as assessment_status',
            'b.id as blueprint_id', 'b.title as blueprint_title',
        ]);

        return response()->json([
            'status' => 1,
            'data' => $rows->map(fn ($r) => [
                'id'                => (int) $r->id,
                'name'              => trim(($r->first_name ?: '') . ' ' . ($r->last_name ?: '')) ?: 'Unnamed',
                'email'             => $r->email,
                'status'            => $r->status,
                'applied_date'      => $r->applied_date,
                'job_id'            => $r->job_id ? (int) $r->job_id : null,
                'job_title'         => $r->job_title,
                'has_assessment'    => $r->assessment_id !== null,
                'assessment_status' => $r->assessment_status,
                'blueprint_id'      => $r->blueprint_id ? (int) $r->blueprint_id : null,
                'blueprint_title'   => $r->blueprint_title,
                'jobrole_id'        => $r->jobrole_id ? (int) $r->jobrole_id : null,
                /*
                 * WHAT STOPS AN INVITE, NAMED BEFORE IT IS TRIED - and in the
                 * order it actually fails.
                 *
                 * no_jobrole is separated from no_blueprint because they need
                 * different fixes and were indistinguishable while every posting
                 * in the installation had a NULL jobrole_id: HR would have been
                 * told to create a template they had already created. Neither is
                 * fatal - the screen can still send using an explicitly chosen
                 * template, which is what makes existing postings usable without
                 * a backfill.
                 */
                'blocked_reason'    => !$r->email ? 'no_email'
                    : (!$r->job_id ? 'no_posting'
                    : (!$r->jobrole_id ? 'no_jobrole'
                    : (!$r->blueprint_id ? 'no_blueprint' : null))),
            ]),
        ], 200);
    }

    /**
     * GET /api/talent/applications/{id}/assessment
     *
     * The result, for the Screening tab. Includes the AI's per-answer reasoning,
     * because a recruiter overriding a mark needs to see what produced it.
     */
    public function result(Request $request, int $applicationId)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $row = DB::table('talent_candidate_assessments as a')
            ->leftJoin('talent_assessment_blueprints as b', function ($join) use ($sid) {
                $join->on('b.id', '=', 'a.blueprint_id')->where('b.sub_institute_id', '=', $sid);
            })
            ->leftJoin('competency_assessment_test as t', 't.id', '=', 'a.test_id')
            ->where('a.application_id', $applicationId)
            ->where('a.sub_institute_id', $sid)
            ->whereNull('a.deleted_at')
            ->first([
                'a.id', 'a.status', 'a.score', 'a.max_score', 'a.percent', 'a.qualified',
                'a.invited_at', 'a.submitted_at', 'a.graded_at', 'a.token_expires_at',
                'a.token_used_at', 'a.attempt_id', 'a.test_id',
                'b.qualification_marks', 'b.total_marks', 't.title',
            ]);

        if (!$row) {
            return response()->json(['status' => 1, 'data' => null], 200);
        }

        $answers = $row->attempt_id
            ? DB::table('competency_assessment_response as r')
                ->join('competency_assessment_question as q', 'q.id', '=', 'r.question_id')
                ->where('r.sub_institute_id', $sid)
                ->where('r.test_id', $row->test_id)
                ->where('r.subject_type', 'candidate')
                ->orderBy('q.sort_order')
                ->get([
                    'q.id', 'q.format', 'q.question_text', 'q.max_score',
                    'r.answer_text', 'r.selected_option', 'r.score', 'r.scored_by', 'r.ai_feedback',
                ])
            : collect();

        return response()->json([
            'status' => 1,
            'data' => [
                'id'         => (int) $row->id,
                'status'     => $row->status,
                'title'      => $row->title,
                'score'      => $row->score === null ? null : (float) $row->score,
                'max_score'  => $row->max_score === null ? null : (float) $row->max_score,
                'percent'    => $row->percent === null ? null : (float) $row->percent,
                'qualification_marks' => $row->qualification_marks === null ? null : (int) $row->qualification_marks,
                // Tri-state on purpose: true, false, and NULL for "not judged
                // yet". Collapsing null to false would show a candidate as
                // failed while their paper is still being marked.
                'qualified'  => $row->qualified === null ? null : (bool) $row->qualified,
                'invited_at' => $row->invited_at,
                'submitted_at' => $row->submitted_at,
                'graded_at'  => $row->graded_at,
                'expires_at' => $row->token_expires_at,
                'link_used'  => $row->token_used_at !== null,
                'answers'    => $answers->map(fn ($a) => [
                    'question_id' => (int) $a->id,
                    'format'      => $a->format,
                    'question'    => $a->question_text,
                    'answer'      => $a->format === 'mcq' ? $a->selected_option : $a->answer_text,
                    'score'       => $a->score === null ? null : (float) $a->score,
                    'max_score'   => (float) $a->max_score,
                    'scored_by'   => $a->scored_by,
                    'ai_feedback' => $a->ai_feedback,
                ]),
            ],
        ], 200);
    }

    /**
     * The blueprint that governs this application.
     *
     * Explicit choice wins; otherwise the posting's job role decides. Falls back
     * to null rather than to "any active blueprint" - assessing a candidate
     * against a paper for a different role is worse than not assessing them.
     */
    private function blueprintFor(object $application, int $sid, $explicitId): ?object
    {
        $query = DB::table('talent_assessment_blueprints')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at');

        if ($explicitId) {
            return $query->where('id', (int) $explicitId)->first();
        }

        $jobroleId = DB::table('talent_job_postings')
            ->where('id', $application->job_id)->where('sub_institute_id', $sid)
            ->value('jobrole_id');

        if (!$jobroleId) {
            return null;
        }

        return $query->where('jobrole_id', $jobroleId)->where('is_active', 1)->first();
    }
}
