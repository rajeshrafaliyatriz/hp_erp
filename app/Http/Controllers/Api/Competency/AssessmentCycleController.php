<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ManagesCompetencySettings;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssessmentCycleController extends Controller
{
    use ResolvesCompetencyContext;
    use ManagesCompetencySettings;

    /**
     * The "View Configuration" panel's editable settings, with the defaults a
     * tenant that has never saved them gets. Keys are the allowed set.
     */
    private const ASSESSMENT_DEFAULTS = [
        'self_assessment_required' => true,
        'manager_review_required'  => true,
        'calibration_required'     => true,
        // Who signs a completed assessment off: manager | hr | both
        'approval_chain'           => 'manager',
        // Default window for a new campaign, in days
        'default_duration_days'    => 30,
        // Reminder cadence in days before the due date (0 = off)
        'reminder_days_before'     => 7,
        // Allow a reviewer to change a rating during calibration
        'allow_rating_override'    => true,
        // Show the employee their manager's rating once reviewed
        'publish_results'          => false,
    ];

    private const APPROVAL_CHAINS = ['manager', 'hr', 'both'];

    /** What a campaign with no explicit type reads as. */
    private const DEFAULT_CYCLE_TYPE = 'Self + Manager';

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $query = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($status = $this->activeFilter($request->input('status'))) {
            if ($status === 'progress') {
                $query->whereIn('status', ['active', 'scheduled']);
            } else {
                $query->where('status', $status);
            }
        }

        $cycles = $query->orderByDesc('id')->get();

        // Resolve the framework name once for the whole page rather than per row.
        $frameworkNames = DB::table('s_competency_frameworks')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereIn('id', $cycles->pluck('framework_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $cycleIds = $cycles->pluck('id')->toArray();
        $assessments = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereIn('cycle_id', $cycleIds)
            ->whereNull('deleted_at')
            ->select('cycle_id', 'status')
            ->get();

        $stats = [];
        foreach ($assessments as $a) {
            if (!isset($stats[$a->cycle_id])) {
                $stats[$a->cycle_id] = ['total' => 0, 'completed' => 0];
            }
            $stats[$a->cycle_id]['total']++;
            if ($a->status === 'completed') {
                $stats[$a->cycle_id]['completed']++;
            }
        }

        $data = $cycles->map(function ($c) use ($stats, $frameworkNames) {
            $total = $stats[$c->id]['total'] ?? 0;
            $completed = $stats[$c->id]['completed'] ?? 0;
            $completion = $total > 0 ? round(($completed / $total) * 100) : 0;

            $frontendStatus = 'In Progress';
            if ($c->status === 'closed') {
                $frontendStatus = 'Completed';
            }

            return [
                'id' => (string)$c->id,
                'name' => $c->name,
                'framework_id' => $c->framework_id ? (int) $c->framework_id : null,
                'framework_name' => $c->framework_id ? ($frameworkNames[$c->framework_id] ?? null) : null,
                // Real column now; falls back to what this used to hardcode so
                // campaigns created before the column read exactly as before.
                'type' => $c->type ?: self::DEFAULT_CYCLE_TYPE,
                'participants' => $total,
                'completion' => $completion,
                'status' => $frontendStatus,
                'date' => $c->end_date ? date('d M Y', strtotime($c->end_date)) : 'N/A'
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Campaigns fetched successfully',
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'type' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            // Which framework the campaign assesses against. Nullable so an
            // ad-hoc campaign is still possible, but the UI offers it up front.
            'framework_id' => 'nullable|integer|exists:s_competency_frameworks,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $id = DB::table('s_competency_assessment_cycles')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'framework_id' => $request->input('framework_id'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'status' => 'active',
            'created_by' => $context['user_id'],
            'updated_by' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'created_assessment_cycle',
            'Created assessment campaign "' . $request->input('name') . '"',
            'assessment_cycle',
            $id,
            $request->input('name')
        );

        return response()->json([
            'status' => 1,
            'message' => 'Campaign created successfully',
            'data' => ['id' => $id]
        ], 201);
    }

    public function metrics(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        $activeCampaigns = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'scheduled'])
            ->count();

        $assessments = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->select('status', 'review_status')
            ->get();

        $totalAssessments = $assessments->count();
        $completedAssessments = $assessments->where('status', 'completed')->count();

        $overallCompletionPercent = $totalAssessments > 0 ? round(($completedAssessments / $totalAssessments) * 100) : 0;

        // Manager ratings pending = completed but no manager/calibration review yet.
        $pendingManagerRatings = $assessments
            ->where('status', 'completed')
            ->whereNull('review_status')
            ->count();

        // Calibration queue = completed and flagged for review.
        $pendingCalibration = $assessments
            ->where('status', 'completed')
            ->where('review_status', 'pending_review')
            ->count();

        return response()->json([
            'status' => 1,
            'message' => 'Metrics fetched successfully',
            'data' => [
                'active_campaigns' => $activeCampaigns,
                'overall_completion_percent' => $overallCompletionPercent,
                'completed_assessments' => $completedAssessments,
                'total_assessments' => $totalAssessments,
                'pending_manager_ratings' => $pendingManagerRatings,
                'pending_calibration' => $pendingCalibration
            ]
        ]);
    }

    /* ----------------------------------------------------------------- *
     * Workspace top-tab lists (Participant Ratings / Calibration /
     * Approvals / Closed Campaigns) — all over s_competency_assessments.
     * ----------------------------------------------------------------- */

    /** All participant assessments across every campaign. */
    public function participantRatings(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = $this->assessmentBase($context['sub_institute_id'])
            ->orderByDesc('a.id')->limit(300)->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Participant ratings fetched successfully',
            'data'    => $rows->map(fn ($a) => $this->mapAssessment($a))->all(),
        ]);
    }

    /** Completed assessments flagged for calibration review. */
    public function calibration(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = $this->assessmentBase($context['sub_institute_id'])
            ->where('a.status', 'completed')
            ->where('a.review_status', 'pending_review')
            ->orderByDesc('a.id')->limit(300)->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Calibration queue fetched successfully',
            'data'    => $rows->map(fn ($a) => $this->mapAssessment($a))->all(),
        ]);
    }

    /** Completed assessments awaiting manager approval / sign-off. */
    public function approvals(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = $this->assessmentBase($context['sub_institute_id'])
            ->where('a.status', 'completed')
            ->whereNull('a.review_status')
            ->orderByDesc('a.id')->limit(300)->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Approvals queue fetched successfully',
            'data'    => $rows->map(fn ($a) => $this->mapAssessment($a))->all(),
        ]);
    }

    /** Closed campaigns (assessment cycles) with completion stats. */
    public function closed(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $cycles = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('status', 'closed')
            ->orderByDesc('id')->get();

        $cycleIds = $cycles->pluck('id')->toArray();
        $assessments = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)
            ->whereIn('cycle_id', $cycleIds ?: [0])
            ->whereNull('deleted_at')
            ->select('cycle_id', 'status')->get();

        $stats = [];
        foreach ($assessments as $a) {
            $stats[$a->cycle_id] ??= ['total' => 0, 'completed' => 0];
            $stats[$a->cycle_id]['total']++;
            if ($a->status === 'completed') {
                $stats[$a->cycle_id]['completed']++;
            }
        }

        $data = $cycles->map(function ($c) use ($stats) {
            $total = $stats[$c->id]['total'] ?? 0;
            $completed = $stats[$c->id]['completed'] ?? 0;
            return [
                'id'           => (string) $c->id,
                'name'         => $c->name,
                'type'         => 'Self + Manager',
                'participants' => $total,
                'completion'   => $total > 0 ? round(($completed / $total) * 100) : 0,
                'status'       => 'Completed',
                'start_date'   => $c->start_date ? date('d M Y', strtotime($c->start_date)) : null,
                'date'         => $c->end_date ? date('d M Y', strtotime($c->end_date)) : 'N/A',
            ];
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Closed campaigns fetched successfully',
            'data'    => $data,
        ]);
    }

    /** Approve / calibrate one assessment (sets review_status = reviewed). */
    public function reviewAssessment(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,calibrate,reject',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $assessment = DB::table('s_competency_assessments')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
        if (!$assessment) {
            return response()->json(['status' => 0, 'message' => 'Assessment not found'], 404);
        }

        $action = $request->input('action');
        $reviewStatus = $action === 'reject' ? 'pending_review' : 'reviewed';

        DB::table('s_competency_assessments')->where('id', $id)->update([
            'review_status' => $reviewStatus,
            'updated_by'    => $context['user_id'],
            'updated_at'    => now(),
        ]);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            $action . '_assessment',
            ucfirst($action) . ' assessment "' . $assessment->title . '"',
            'assessment',
            (int) $id,
            $assessment->title,
            $this->diffChanges($assessment, ['review_status' => $reviewStatus], ['review_status' => 'Review Status'])
        );

        return response()->json(['status' => 1, 'message' => 'Assessment ' . $action . 'd successfully']);
    }

    /* ----------------------------------------------------------------- */

    private function assessmentBase(int $sid)
    {
        return DB::table('s_competency_assessments as a')
            ->leftJoin('tbluser as u', 'a.user_id', '=', 'u.id')
            ->leftJoin('s_competency_assessment_cycles as c', 'a.cycle_id', '=', 'c.id')
            ->where('a.sub_institute_id', $sid)
            ->whereNull('a.deleted_at')
            ->select(
                'a.id as assessment_id',
                'a.user_id',
                'a.jobrole',
                'a.status',
                'a.review_status',
                'a.score',
                'a.completed_at',
                'a.due_date',
                'u.first_name',
                'u.last_name',
                'u.employee_no',
                'c.name as cycle_name'
            );
    }

    private function mapAssessment($a): array
    {
        $fname = $a->first_name ?: 'Unknown';
        $lname = $a->last_name ?: '';
        $self = in_array($a->status, ['completed', 'overdue'], true);
        $manager = $a->review_status === 'reviewed';

        return [
            'assessment_id' => (string) $a->assessment_id,
            'name'          => trim($fname . ' ' . $lname),
            'initials'      => strtoupper(substr($fname, 0, 1) . substr($lname, 0, 1)),
            'emp_id'        => $a->employee_no ?: '',
            'role'          => $a->jobrole ?: 'N/A',
            'campaign'      => $a->cycle_name ?: '—',
            'self'          => $self,
            'manager'       => $manager,
            'score'         => $a->score !== null ? (float) $a->score : null,
            'status'        => $a->status,
            'review_status' => $a->review_status,
            'date'          => $a->completed_at
                ? date('d M Y', strtotime($a->completed_at))
                : ($a->due_date ? date('d M Y', strtotime($a->due_date)) : null),
        ];
    }

    public function participants(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        $assessments = DB::table('s_competency_assessments as a')
            ->leftJoin('tbluser as u', 'a.user_id', '=', 'u.id')
            ->where('a.sub_institute_id', $sid)
            ->where('a.cycle_id', $id)
            ->whereNull('a.deleted_at')
            ->select(
                'a.id as assessment_id',
                'a.user_id',
                'a.jobrole',
                'a.status',
                'a.review_status',
                'a.completed_at',
                'u.first_name',
                'u.last_name',
                'u.employee_no'
            )
            ->get();

        $data = $assessments->map(function ($a) {
            $fname = $a->first_name ?: 'Unknown';
            $lname = $a->last_name ?: '';
            $initials = strtoupper(substr($fname, 0, 1) . substr($lname, 0, 1));
            
            $selfCompleted = ($a->status === 'completed' || $a->status === 'overdue');
            $managerCompleted = ($a->review_status === 'reviewed');
            
            $statusLabel = 'Not Started';
            if ($managerCompleted) {
                $statusLabel = 'Completed';
            } elseif ($a->review_status === 'pending_review' || ($selfCompleted && !$managerCompleted)) {
                $statusLabel = 'Pending Manager';
            } elseif ($a->status === 'in_progress') {
                $statusLabel = 'In Progress';
            } elseif ($a->status === 'overdue') {
                $statusLabel = 'Overdue';
            }

            return [
                'id' => (string)$a->user_id,
                'assessment_id' => (string)$a->assessment_id,
                'name' => trim($fname . ' ' . $lname),
                'initials' => $initials,
                'emp_id' => $a->employee_no ?: '',
                'role' => $a->jobrole ?: 'N/A',
                'self' => $selfCompleted,
                'manager' => $managerCompleted,
                'status' => $statusLabel,
                'self_date' => $a->completed_at ? date('d M', strtotime($a->completed_at)) : null,
                'manager_date' => null
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Participants fetched successfully',
            'data' => $data
        ]);
    }

    /* ================================================================== *
     * View Configuration (editable assessment settings)
     * ================================================================== */

    /**
     * GET /competency/assessment-cycles/configuration
     *
     * The workspace's "View Configuration" panel: the editable settings above,
     * plus the read-only context they operate in (the tenant rating scale, the
     * workflow stages this module actually implements, and current counts).
     */
    public function configuration(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // Rating scale: the tenant's global proficiency scale (skill_id NULL),
        // the same scale the rating screens use.
        $scale = DB::table('s_proficiency_levels')
            ->where('sub_institute_id', $sid)
            ->whereNull('skill_id')
            ->whereNull('deleted_at')
            ->orderByRaw('CAST(proficiency_type AS UNSIGNED)')
            ->get()
            ->map(fn ($level) => [
                'level' => (int) $level->proficiency_type,
                'label' => $level->proficiency_level,
                'name'  => $level->type_description,
            ])
            ->values()
            ->all();

        $cycleCounts = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'status'  => 1,
            'message' => 'Assessment configuration fetched successfully',
            'data'    => [
                'settings' => $this->competencySettings($sid, 'assessment', self::ASSESSMENT_DEFAULTS),
                'options'  => [
                    'approval_chain' => [
                        ['value' => 'manager', 'label' => 'Reporting manager only'],
                        ['value' => 'hr',      'label' => 'HR only'],
                        ['value' => 'both',    'label' => 'Manager, then HR'],
                    ],
                ],
                'context'  => [
                    'rating_scale'      => $scale,
                    'rating_scale_size' => count($scale),
                    // The states reviewAssessment() and the tabs actually use.
                    'workflow'          => ['Open', 'In Progress', 'Completed', 'Pending Review', 'Reviewed'],
                    'frameworks'        => DB::table('s_competency_frameworks')
                        ->where('sub_institute_id', $sid)->whereNull('deleted_at')->count(),
                    'active_campaigns'  => (int) ($cycleCounts['active'] ?? 0),
                    'closed_campaigns'  => (int) ($cycleCounts['closed'] ?? 0),
                    'total_assessments' => DB::table('s_competency_assessments')
                        ->where('sub_institute_id', $sid)->whereNull('deleted_at')->count(),
                ],
            ],
        ]);
    }

    /** PUT /competency/assessment-cycles/configuration */
    public function saveConfiguration(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'self_assessment_required' => 'sometimes|boolean',
            'manager_review_required'  => 'sometimes|boolean',
            'calibration_required'     => 'sometimes|boolean',
            'approval_chain'           => 'sometimes|required|in:' . implode(',', self::APPROVAL_CHAINS),
            'default_duration_days'    => 'sometimes|required|integer|min:1|max:365',
            'reminder_days_before'     => 'sometimes|required|integer|min:0|max:90',
            'allow_rating_override'    => 'sometimes|boolean',
            'publish_results'          => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $before = $this->competencySettings($sid, 'assessment', self::ASSESSMENT_DEFAULTS);

        $settings = $this->saveCompetencySettings(
            $sid,
            'assessment',
            $request->all(),
            self::ASSESSMENT_DEFAULTS,
            $context['user_id']
        );

        $labels = [
            'self_assessment_required' => 'Self-assessment Required',
            'manager_review_required'  => 'Manager Review Required',
            'calibration_required'     => 'Calibration Required',
            'approval_chain'           => 'Approval Chain',
            'default_duration_days'    => 'Default Campaign Duration',
            'reminder_days_before'     => 'Reminder Lead Time',
            'allow_rating_override'    => 'Allow Rating Override',
            'publish_results'          => 'Publish Results To Employee',
        ];

        $changes = [];
        foreach ($labels as $key => $label) {
            $old = is_bool($before[$key]) ? ($before[$key] ? 'Yes' : 'No') : $before[$key];
            $new = is_bool($settings[$key]) ? ($settings[$key] ? 'Yes' : 'No') : $settings[$key];
            if ((string) $old !== (string) $new) {
                $changes[] = ['field' => $key, 'label' => $label, 'old' => $old, 'new' => $new];
            }
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_assessment_config',
            'Updated the assessment workspace configuration',
            'assessment_cycle',
            null,
            'Assessment Configuration',
            $changes
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Assessment configuration saved successfully',
            'data'    => ['settings' => $settings],
        ]);
    }

    /* ================================================================== *
     * One campaign: Overview / Edit / Ratings / Calibration / Audit Trail
     * ================================================================== */

    /**
     * GET /competency/assessment-cycles/{id}
     *
     * The campaign detail panel's Overview tab: the cycle itself plus the
     * progress, completion and score roll-ups computed from its assessments.
     */
    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $cycle = $this->findCycle($id, $sid);
        if (!$cycle) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found'], 404);
        }

        $assessments = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)
            ->where('cycle_id', $id)
            ->whereNull('deleted_at')
            ->get(['id', 'user_id', 'status', 'review_status', 'score', 'department_id', 'jobrole', 'completed_at']);

        $total = $assessments->count();
        $completed = $assessments->where('status', 'completed')->count();
        $inProgress = $assessments->where('status', 'in_progress')->count();
        $overdue = $assessments->where('status', 'overdue')->count();
        $notStarted = $assessments->where('status', 'open')->count();
        $reviewed = $assessments->where('review_status', 'reviewed')->count();
        $pendingCalibration = $assessments->where('review_status', 'pending_review')->count();

        $scores = $assessments->pluck('score')->filter(fn ($s) => $s !== null && $s !== '')->map(fn ($s) => (float) $s);

        // Score spread, so Overview shows the distribution not just an average.
        // NOTE s_competency_assessments.score is decimal(5,2) holding a
        // PERCENTAGE (0-100), not a point on the 1-6 proficiency scale - so the
        // bands are percentage bands.
        $buckets = ['<60' => 0, '60-70' => 0, '70-80' => 0, '80-90' => 0, '90-100' => 0];
        foreach ($scores as $score) {
            if ($score < 60) $buckets['<60']++;
            elseif ($score < 70) $buckets['60-70']++;
            elseif ($score < 80) $buckets['70-80']++;
            elseif ($score < 90) $buckets['80-90']++;
            else $buckets['90-100']++;
        }

        // Department split - the cycle's reach across the organisation.
        $departmentNames = DB::table('hrms_departments')
            ->whereIn('id', $assessments->pluck('department_id')->filter()->unique()->all())
            ->pluck('department', 'id');

        $departments = $assessments
            ->groupBy('department_id')
            ->map(fn ($rows, $deptId) => [
                'department' => $departmentNames[$deptId] ?? 'Unassigned',
                'total'      => $rows->count(),
                'completed'  => $rows->where('status', 'completed')->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->take(8)
            ->all();

        return response()->json([
            'status'  => 1,
            'message' => 'Campaign fetched successfully',
            'data'    => [
                'id'          => (string) $cycle->id,
                'name'        => $cycle->name,
                'type'        => $cycle->type ?: self::DEFAULT_CYCLE_TYPE,
                'description' => $cycle->description,
                'status'      => $cycle->status,
                'start_date'  => $cycle->start_date,
                'end_date'    => $cycle->end_date,
                'start_label' => $cycle->start_date ? date('d M Y', strtotime($cycle->start_date)) : null,
                'end_label'   => $cycle->end_date ? date('d M Y', strtotime($cycle->end_date)) : null,
                'days_left'   => $cycle->end_date
                    ? (int) floor((strtotime($cycle->end_date) - time()) / 86400)
                    : null,
                'progress'    => [
                    'total'               => $total,
                    'completed'           => $completed,
                    'in_progress'         => $inProgress,
                    'not_started'         => $notStarted,
                    'overdue'             => $overdue,
                    'reviewed'            => $reviewed,
                    'pending_calibration' => $pendingCalibration,
                    'completion_pct'      => $total > 0 ? (int) round($completed / $total * 100) : 0,
                    'review_pct'          => $total > 0 ? (int) round($reviewed / $total * 100) : 0,
                ],
                'scores'      => [
                    'rated'   => $scores->count(),
                    'average' => $scores->count() ? round($scores->avg(), 2) : null,
                    'min'     => $scores->count() ? round($scores->min(), 2) : null,
                    'max'     => $scores->count() ? round($scores->max(), 2) : null,
                    'buckets' => collect($buckets)->map(fn ($count, $range) => [
                        'range' => $range,
                        'count' => $count,
                    ])->values()->all(),
                ],
                'departments' => $departments,
            ],
        ]);
    }

    /**
     * PUT /competency/assessment-cycles/{id}
     *
     * "Edit Campaign". Only the cycle's own fields are writable - participants
     * are assessments and are managed from the Participants tab.
     */
    public function update(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $cycle = $this->findCycle($id, $sid);
        if (!$cycle) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:191',
            'type'        => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string',
            'status'      => 'sometimes|required|in:scheduled,active,closed',
            'start_date'  => 'sometimes|nullable|date',
            'end_date'    => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $update = [];
        foreach (['name', 'type', 'description', 'status', 'start_date', 'end_date'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }

        if ($update) {
            DB::table('s_competency_assessment_cycles')->where('id', $id)->update($update + [
                'updated_by' => $context['user_id'],
                'updated_at' => now(),
            ]);
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_assessment_cycle',
            'Updated assessment campaign "' . ($update['name'] ?? $cycle->name) . '"',
            'assessment_cycle',
            (int) $id,
            $update['name'] ?? $cycle->name,
            $this->diffChanges($cycle, $update, [
                'name'        => 'Campaign Name',
                'type'        => 'Assessment Type',
                'description' => 'Description',
                'status'      => 'Status',
                'start_date'  => 'Start Date',
                'end_date'    => 'End Date',
            ])
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Campaign updated successfully',
            'data'    => ['id' => (int) $id],
        ]);
    }

    /**
     * GET /competency/assessment-cycles/{id}/ratings
     *
     * The Ratings tab: every rated participant in this campaign with their
     * score, where it sits against the target, and its review state.
     */
    public function ratings(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        if (!$this->findCycle($id, $sid)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found'], 404);
        }

        $target = (int) $this->competencySettings($sid, 'weighting', ['target_threshold' => 80])['target_threshold'];

        $rows = DB::table('s_competency_assessments as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('s_competency_frameworks as f', 'f.id', '=', 'a.framework_id')
            ->where('a.sub_institute_id', $sid)
            ->where('a.cycle_id', $id)
            ->whereNull('a.deleted_at')
            ->orderByDesc('a.score')
            ->get([
                'a.id', 'a.user_id', 'a.jobrole', 'a.status', 'a.review_status', 'a.score',
                'a.completed_at', 'u.first_name', 'u.last_name', 'u.employee_no', 'f.name as framework',
            ]);

        // s_competency_assessments.score is already a percentage (decimal(5,2),
        // 0-100) - it is NOT a point on the 1-6 proficiency scale, so it is
        // compared to the target directly rather than rescaled.
        $data = $rows->map(function ($row) use ($target) {
            $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: ('User ' . $row->user_id);
            $score = $row->score !== null && $row->score !== '' ? (float) $row->score : null;
            $pct = $score !== null ? (int) round($score) : null;

            return [
                'assessment_id' => (string) $row->id,
                'user_id'       => (string) $row->user_id,
                'name'          => $name,
                'initials'      => strtoupper(substr($row->first_name ?: $name, 0, 1) . substr((string) $row->last_name, 0, 1)),
                'emp_id'        => $row->employee_no ?: '',
                'role'          => $row->jobrole ?: 'N/A',
                'framework'     => $row->framework,
                'score'         => $score,
                'score_pct'     => $pct,
                'target_pct'    => $target,
                'meets_target'  => $pct !== null ? $pct >= $target : null,
                'status'        => $row->status,
                'review_status' => $row->review_status,
                'rated_on'      => $row->completed_at ? date('d M Y', strtotime($row->completed_at)) : null,
            ];
        })->all();

        $rated = array_values(array_filter($data, fn ($r) => $r['score'] !== null));

        return response()->json([
            'status'  => 1,
            'message' => 'Campaign ratings fetched successfully',
            'data'    => $data,
            'meta'    => [
                'rated'         => count($rated),
                'unrated'       => count($data) - count($rated),
                'target_pct'    => $target,
                'meeting'       => count(array_filter($rated, fn ($r) => $r['meets_target'] === true)),
                'below_target'  => count(array_filter($rated, fn ($r) => $r['meets_target'] === false)),
                'average_score' => $rated ? round(array_sum(array_column($rated, 'score')) / count($rated), 2) : null,
            ],
        ]);
    }

    /**
     * GET /competency/assessment-cycles/{id}/calibration-queue
     *
     * The Calibration tab, scoped to this campaign. Same definition the
     * workspace-wide Calibration tab uses: completed and awaiting review.
     */
    public function calibrationQueue(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        if (!$this->findCycle($id, $sid)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found'], 404);
        }

        $rows = DB::table('s_competency_assessments as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'a.department_id')
            ->where('a.sub_institute_id', $sid)
            ->where('a.cycle_id', $id)
            ->whereNull('a.deleted_at')
            ->where('a.status', 'completed')
            ->where('a.review_status', 'pending_review')
            ->orderByDesc('a.score')
            ->get([
                'a.id', 'a.user_id', 'a.jobrole', 'a.score', 'a.completed_at',
                'u.first_name', 'u.last_name', 'u.employee_no', 'd.department',
            ]);

        $data = $rows->map(function ($row) {
            $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: ('User ' . $row->user_id);

            return [
                'assessment_id' => (string) $row->id,
                'user_id'       => (string) $row->user_id,
                'name'          => $name,
                'initials'      => strtoupper(substr($row->first_name ?: $name, 0, 1) . substr((string) $row->last_name, 0, 1)),
                'emp_id'        => $row->employee_no ?: '',
                'role'          => $row->jobrole ?: 'N/A',
                'department'    => $row->department,
                'score'         => $row->score !== null && $row->score !== '' ? (float) $row->score : null,
                'completed_on'  => $row->completed_at ? date('d M Y', strtotime($row->completed_at)) : null,
            ];
        })->all();

        return response()->json([
            'status'  => 1,
            'message' => 'Calibration queue fetched successfully',
            'data'    => $data,
        ]);
    }

    /**
     * GET /competency/assessment-cycles/{id}/audit-trail
     *
     * The Audit Trail tab: this campaign's slice of the shared competency
     * activity feed - entries against the cycle itself and against every
     * assessment inside it.
     */
    public function auditTrail(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $cycle = $this->findCycle($id, $sid);
        if (!$cycle) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found'], 404);
        }

        $assessmentIds = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)
            ->where('cycle_id', $id)
            ->pluck('id')
            ->all();

        $entries = DB::table('s_competency_activity_log')
            ->where('sub_institute_id', $sid)
            ->where(function ($q) use ($id, $assessmentIds) {
                $q->where(function ($q2) use ($id) {
                    $q2->where('subject_type', 'assessment_cycle')->where('subject_id', $id);
                });
                if ($assessmentIds) {
                    $q->orWhere(function ($q2) use ($assessmentIds) {
                        $q2->where('subject_type', 'assessment')->whereIn('subject_id', $assessmentIds);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $data = $entries->map(fn ($e) => [
            'id'          => (int) $e->id,
            'action'      => $e->action,
            'description' => $e->description,
            'record'      => $e->subject_name,
            'by'          => $e->actor_name ?: 'System',
            'at'          => $e->created_at,
            'date'        => $e->created_at ? date('d M Y, h:i A', strtotime($e->created_at)) : null,
            'changes'     => $e->changes ? (json_decode($e->changes, true) ?: []) : [],
        ])->all();

        // The cycle's own audit stamps, so a campaign that predates the feed
        // still shows something meaningful.
        $stamps = [];
        if ($cycle->created_at) {
            $stamps[] = ['label' => 'Campaign created', 'date' => date('d M Y', strtotime($cycle->created_at))];
        }
        if ($cycle->updated_at && $cycle->updated_at !== $cycle->created_at) {
            $stamps[] = ['label' => 'Last updated', 'date' => date('d M Y', strtotime($cycle->updated_at))];
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Campaign audit trail fetched successfully',
            'data'    => $data,
            'meta'    => ['stamps' => $stamps, 'assessments' => count($assessmentIds)],
        ]);
    }

    /** Tenant-scoped cycle lookup shared by the campaign endpoints. */
    private function findCycle($id, int $sid)
    {
        return DB::table('s_competency_assessment_cycles')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();
    }
}
