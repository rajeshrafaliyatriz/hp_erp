<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Performance & Rewards Center for a single tenant so every KPI, tab,
 * view and panel on the screen renders real numbers.
 *
 * Everything is derived from the tenant's EXISTING data: employees from tbluser,
 * departments from hrms_departments, and each employee's job role + department
 * from their most recent competency assessment (s_competency_assessments) - the
 * same derivation the Competency module's Career Path Explorer uses, because this
 * ERP has no user -> jobrole table (s_user_jobrole is the role master with no
 * user_id, hrms_job_titles is empty and tbluser.jobtitle_id is 0 for everyone).
 *
 * Idempotent: it hard-deletes only this tenant's s_performance_* rows first, so
 * re-running never duplicates and never touches another tenant or another module.
 *
 * Target tenant is sub_institute_id = 1; override with PERFORMANCE_SEED_TENANT.
 */
class PerformanceSeeder extends Seeder
{
    /** Rating band labels, matching ResolvesPerformanceContext::ratingLabel(). */
    private const BANDS = [
        5 => 'Outstanding',
        4 => 'Exceeds',
        3 => 'Meets',
        2 => 'Needs Improvement',
        1 => 'Below Expectations',
    ];

    public function run(): void
    {
        $sid = (int) (env('PERFORMANCE_SEED_TENANT', 1));
        $now = Carbon::now();

        // --- Idempotency: clear this tenant's performance rows ---------------
        // Child tables first so a re-run never leaves an orphan pointing at a
        // deleted parent (calibration_session_id on reviews, review_id on notes).
        foreach ([
            's_performance_activity_log',
            's_performance_attachments',
            's_performance_notes',
            's_performance_saved_views',
            's_performance_bonus_awards',
            's_performance_compensation_revisions',
            's_performance_appraisals',
            's_performance_goals',
            's_performance_reviews',
            's_performance_calibration_sessions',
            's_performance_cycles',
        ] as $table) {
            DB::table($table)->where('sub_institute_id', $sid)->delete();
        }

        // --- Real source data ------------------------------------------------
        $employees = DB::table('tbluser')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'user_name', 'employee_no', 'department_id', 'joined_date']);

        if ($employees->isEmpty()) {
            $this->command?->warn("PerformanceSeeder: no employees for sub_institute_id={$sid}; nothing seeded.");
            return;
        }

        $validDepartments = DB::table('hrms_departments')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->pluck('department', 'id');

        // Job role + department per employee, from their latest assessment.
        $placements = $this->resolvePlacements($sid, $employees->pluck('id')->all());

        // The department each review will actually carry, resolved ONCE here.
        // tbluser.department_id wins when it resolves in the department master
        // (some rows in this data point at a department that no longer exists),
        // otherwise the assessment's department is used. Calibration sessions,
        // goals and reward rows all read this same map, so a session can never
        // be created for a department that has no reviews in it.
        $reviewDepartments = [];

        foreach ($employees as $employee) {
            $placement = $placements[$employee->id] ?? ['department_id' => null];

            $reviewDepartments[(int) $employee->id] =
                ($employee->department_id && isset($validDepartments[$employee->department_id]))
                    ? (int) $employee->department_id
                    : $placement['department_id'];
        }

        // --- Cycles ----------------------------------------------------------
        // Two live cycles (the KPI reads "Active Review Cycles") plus one closed
        // one so the Cycle Timeline and the sidebar's review history have depth.
        $cycles = [];

        $cycles['annual'] = $this->createCycle($sid, $now, [
            'name'               => 'FY 2024-25 Annual',
            'code'               => 'FY24-ANN',
            'cycle_type'         => 'annual',
            'description'        => 'Organisation-wide annual performance and rewards cycle.',
            'period_start'       => $now->copy()->subMonths(4)->startOfMonth()->toDateString(),
            'period_end'         => $now->copy()->addMonths(2)->endOfMonth()->toDateString(),
            // Inside the 7-day window the "Self Reviews Pending" subtitle counts.
            'self_review_due'    => $now->copy()->addDays(6)->toDateString(),
            'manager_review_due' => $now->copy()->addDays(20)->toDateString(),
            'calibration_due'    => $now->copy()->addDays(34)->toDateString(),
            'final_review_due'   => $now->copy()->addDays(48)->toDateString(),
            'status'             => 'active',
            'launched_at'        => $now->copy()->subMonths(4)->startOfMonth()->addDays(2),
        ]);

        $cycles['half'] = $this->createCycle($sid, $now, [
            'name'               => 'H1 2025 Half-Yearly Check-in',
            'code'               => 'H1-25',
            'cycle_type'         => 'half_yearly',
            'description'        => 'Mid-year check-in on goals and development.',
            'period_start'       => $now->copy()->subMonths(1)->startOfMonth()->toDateString(),
            'period_end'         => $now->copy()->endOfMonth()->toDateString(),
            'self_review_due'    => $now->copy()->subDays(4)->toDateString(),
            'manager_review_due' => $now->copy()->addDays(4)->toDateString(),
            'calibration_due'    => $now->copy()->addDays(11)->toDateString(),
            'final_review_due'   => $now->copy()->addDays(18)->toDateString(),
            // 'calibration' also makes this the cycle "closing this month" that
            // the Active Review Cycles subtitle counts.
            'status'             => 'calibration',
            'launched_at'        => $now->copy()->subMonths(1)->startOfMonth()->addDay(),
        ]);

        $cycles['closed'] = $this->createCycle($sid, $now, [
            'name'               => 'FY 2023-24 Annual',
            'code'               => 'FY23-ANN',
            'cycle_type'         => 'annual',
            'description'        => 'Closed annual cycle - retained for rating history.',
            'period_start'       => $now->copy()->subMonths(16)->startOfMonth()->toDateString(),
            'period_end'         => $now->copy()->subMonths(4)->endOfMonth()->toDateString(),
            'self_review_due'    => $now->copy()->subMonths(9)->toDateString(),
            'manager_review_due' => $now->copy()->subMonths(8)->toDateString(),
            'calibration_due'    => $now->copy()->subMonths(7)->toDateString(),
            'final_review_due'   => $now->copy()->subMonths(6)->toDateString(),
            'status'             => 'closed',
            'launched_at'        => $now->copy()->subMonths(16)->startOfMonth()->addDays(3),
            'closed_at'          => $now->copy()->subMonths(4)->endOfMonth(),
        ]);

        // --- Calibration sessions -------------------------------------------
        // One per department the reviews actually land in, so every session has
        // real participants and the Calibration Pending KPI counts real work.
        $departmentsInPlay = collect($reviewDepartments)
            ->filter()
            ->unique()
            ->values();

        $sessions = [];
        $facilitatorId = (int) $employees->first()->id;

        foreach ($departmentsInPlay as $index => $departmentId) {
            $departmentName = $validDepartments[$departmentId] ?? ('Department ' . $departmentId);

            $sessions[$departmentId] = DB::table('s_performance_calibration_sessions')->insertGetId([
                'sub_institute_id'    => $sid,
                'cycle_id'            => $cycles['annual'],
                'department_id'       => $departmentId,
                'name'                => $departmentName . ' Calibration - FY 2024-25',
                'facilitator_id'      => $facilitatorId,
                'scheduled_at'        => $now->copy()->addDays(3 + ($index % 5))->setTime(10, 30),
                'status'              => $index === 0 ? 'in_progress' : 'scheduled',
                'participant_count'   => 0,
                // A standard forced-distribution guardrail.
                'distribution_target' => json_encode(['5' => 10, '4' => 20, '3' => 50, '2' => 15, '1' => 5]),
                'notes'               => 'Review rating spread against the target distribution before locking.',
                'created_by'          => $facilitatorId,
                'updated_by'          => $facilitatorId,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }

        // --- Reviews ---------------------------------------------------------
        // Every employee gets a review on all three cycles. Stages are spread
        // deterministically across the ladder so each KPI and each kanban column
        // has data, and the closed cycle is fully completed with real ratings.
        $stageLadder = ['self_review', 'manager_review', 'calibration', 'final_review', 'completed'];
        $reviews = [];   // [cycleKey][userId] => review id
        $counter = 0;

        foreach (['annual', 'half', 'closed'] as $cycleKey) {
            $cycleId = $cycles[$cycleKey];
            $cycleRow = DB::table('s_performance_cycles')->where('id', $cycleId)->first();

            foreach ($employees as $employee) {
                $placement = $placements[$employee->id] ?? ['jobrole' => null, 'department_id' => null];
                $departmentId = $reviewDepartments[(int) $employee->id] ?? null;

                // Manager = the previous employee in the list, so the Manager
                // filter and the sidebar's Manager row have real names. Employees
                // are not their own manager.
                $managerId = $this->managerFor($employees, $employee->id);

                if ($cycleKey === 'closed') {
                    $stage = 'completed';
                } else {
                    $stage = $stageLadder[$counter % count($stageLadder)];
                }

                $isCompleted = $stage === 'completed';
                $stageIndex = array_search($stage, $stageLadder, true);

                // Ratings only exist once the stage that produces them has passed.
                $selfRating = $stageIndex >= 1 ? $this->ratingFor($counter, 0) : null;
                $managerRating = $stageIndex >= 2 ? $this->ratingFor($counter, 1) : null;
                $calibratedRating = $stageIndex >= 3 ? $this->ratingFor($counter, 2) : null;

                $effective = $calibratedRating ?? $managerRating ?? $selfRating;
                $potential = $isCompleted ? $this->ratingFor($counter, 3) : null;

                $dueDate = match ($stage) {
                    'self_review'    => $cycleRow->self_review_due,
                    'manager_review' => $cycleRow->manager_review_due,
                    'calibration'    => $cycleRow->calibration_due,
                    'final_review'   => $cycleRow->final_review_due,
                    default          => null,
                };

                // One employee per cycle is deliberately overdue so the "Overdue"
                // KPI subtitle and the overdue_only filter both have a hit.
                if ($stage === 'manager_review' && $counter % 4 === 1) {
                    $dueDate = $now->copy()->subDays(6)->toDateString();
                }

                $reviewId = DB::table('s_performance_reviews')->insertGetId([
                    'sub_institute_id'       => $sid,
                    'cycle_id'               => $cycleId,
                    'user_id'                => (int) $employee->id,
                    'manager_id'             => $managerId,
                    'department_id'          => $departmentId,
                    'jobrole'                => $placement['jobrole'],
                    'stage'                  => $stage,
                    'status'                 => $isCompleted ? 'completed' : ($stageIndex > 0 ? 'in_progress' : 'pending'),
                    'self_rating'            => $selfRating,
                    'manager_rating'         => $managerRating,
                    'calibrated_rating'      => $calibratedRating,
                    'overall_rating'         => $effective,
                    'overall_rating_label'   => $this->label($effective),
                    'potential_rating'       => $potential,
                    'potential_rating_label' => $this->label($potential),
                    'is_draft'               => !$isCompleted,
                    'self_comments'          => $stageIndex >= 1
                        ? 'Delivered against my committed goals and supported two cross-team initiatives this cycle.'
                        : null,
                    'manager_comments'       => $stageIndex >= 2
                        ? 'Consistent delivery and strong ownership. Next focus: depth in stakeholder communication.'
                        : null,
                    'self_submitted_at'      => $stageIndex >= 1 ? $now->copy()->subDays(20 - ($counter % 7)) : null,
                    'manager_submitted_at'   => $stageIndex >= 2 ? $now->copy()->subDays(12 - ($counter % 5)) : null,
                    'calibrated_by'          => $stageIndex >= 3 ? $facilitatorId : null,
                    'calibrated_at'          => $stageIndex >= 3 ? $now->copy()->subDays(6) : null,
                    'finalized_at'           => $isCompleted ? $now->copy()->subDays(3) : null,
                    'due_date'               => $dueDate,
                    'last_reminder_at'       => $stage === 'self_review' ? $now->copy()->subDays(2) : null,
                    // Only the live annual cycle's calibration-stage reviews are
                    // attached to a session; the closed cycle is already done.
                    'calibration_session_id' => ($cycleKey === 'annual' && $departmentId && isset($sessions[$departmentId]))
                        ? $sessions[$departmentId]
                        : null,
                    'created_by'             => $facilitatorId,
                    'updated_by'             => $managerId ?? $facilitatorId,
                    'created_at'             => $cycleRow->launched_at ?? $now,
                    'updated_at'             => $now->copy()->subDays(1 + ($counter % 9)),
                ]);

                $reviews[$cycleKey][$employee->id] = $reviewId;
                $counter++;
            }
        }

        // Keep each session's participant_count honest against the rows attached.
        foreach ($sessions as $departmentId => $sessionId) {
            DB::table('s_performance_calibration_sessions')
                ->where('id', $sessionId)
                ->update([
                    'participant_count' => DB::table('s_performance_reviews')
                        ->where('calibration_session_id', $sessionId)
                        ->whereNull('deleted_at')
                        ->count(),
                    'updated_at' => $now,
                ]);
        }

        // --- Goals -----------------------------------------------------------
        // 4 goals per employee on the live annual cycle, weightages summing to
        // 100 so the tab's "total weight" reads correctly.
        $goalTemplates = [
            ['title' => 'Deliver the committed roadmap for the year', 'category' => 'kra', 'weightage' => 40, 'metric' => 'Roadmap items delivered', 'target' => '12', 'unit' => 'items'],
            ['title' => 'Improve process cycle time',                'category' => 'kpi', 'weightage' => 25, 'metric' => 'Average cycle time',      'target' => '5',  'unit' => 'days'],
            ['title' => 'Raise stakeholder satisfaction',            'category' => 'okr', 'weightage' => 20, 'metric' => 'CSAT score',              'target' => '4.5','unit' => 'score'],
            ['title' => 'Close the priority competency gap',         'category' => 'competency', 'weightage' => 15, 'metric' => 'Proficiency level', 'target' => '4', 'unit' => 'level'],
        ];

        $goalStatuses = ['active', 'active', 'achieved', 'partially_achieved', 'missed'];
        $goalCounter = 0;

        foreach ($employees as $employee) {
            $reviewId = $reviews['annual'][$employee->id] ?? null;
            $departmentId = $reviewDepartments[(int) $employee->id] ?? null;

            foreach ($goalTemplates as $offset => $template) {
                $status = $goalStatuses[($goalCounter + $offset) % count($goalStatuses)];

                $progress = match ($status) {
                    'achieved'           => 100,
                    'partially_achieved' => 65,
                    'missed'             => 30,
                    default              => 45 + (($goalCounter + $offset) % 4) * 10,
                };

                DB::table('s_performance_goals')->insert([
                    'sub_institute_id' => $sid,
                    'cycle_id'         => $cycles['annual'],
                    'review_id'        => $reviewId,
                    'user_id'          => (int) $employee->id,
                    'department_id'    => $departmentId,
                    'title'            => $template['title'],
                    'description'      => 'Agreed at the start of the cycle and reviewed at the mid-year check-in.',
                    'category'         => $template['category'],
                    'weightage'        => $template['weightage'],
                    'metric'           => $template['metric'],
                    'target_value'     => $template['target'],
                    // Achieved value tracks progress, so the two can never disagree.
                    'achieved_value'   => (string) round(((float) $template['target']) * $progress / 100, 2),
                    'unit'             => $template['unit'],
                    'start_date'       => $now->copy()->subMonths(4)->startOfMonth()->toDateString(),
                    'due_date'         => $now->copy()->addMonths(2)->endOfMonth()->toDateString(),
                    'progress'         => $progress,
                    'status'           => $status,
                    'self_rating'      => $status === 'achieved' ? 4 : ($status === 'missed' ? 2 : 3),
                    'manager_rating'   => $status === 'achieved' ? 4 : ($status === 'missed' ? 2 : 3),
                    'manager_comments' => $status === 'missed'
                        ? 'Blocked by an external dependency; carry the remainder into the next cycle.'
                        : null,
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subMonths(4),
                    'updated_at'       => $now->copy()->subDays(5),
                ]);
            }

            $goalCounter++;
        }

        // --- Appraisals + reward decisions -----------------------------------
        // Driven off the CLOSED cycle's completed reviews, which is where an
        // appraisal legitimately exists (a final rating has been set).
        $recommendations = ['promote', 'retain', 'retain', 'pip', 'lateral_move'];
        $decisionStatuses = ['approved', 'pending_approval', 'draft', 'approved', 'rejected'];
        $bonusTypes = ['performance', 'retention', 'spot', 'festival'];
        $revisionTypes = ['merit', 'promotion', 'market_correction', 'retention'];

        $index = 0;

        foreach ($employees as $employee) {
            $reviewId = $reviews['closed'][$employee->id] ?? null;

            if (!$reviewId) {
                continue;
            }

            $review = DB::table('s_performance_reviews')->where('id', $reviewId)->first();
            $recommendation = $recommendations[$index % count($recommendations)];
            $status = $decisionStatuses[$index % count($decisionStatuses)];
            $isPromotion = $recommendation === 'promote';

            $appraisalId = DB::table('s_performance_appraisals')->insertGetId([
                'sub_institute_id'     => $sid,
                'cycle_id'             => $cycles['closed'],
                'review_id'            => $reviewId,
                'user_id'              => (int) $employee->id,
                'department_id'        => $review->department_id,
                'jobrole'              => $review->jobrole,
                'final_rating'         => $review->overall_rating,
                'final_rating_label'   => $review->overall_rating_label,
                'recommendation'       => $recommendation,
                'current_designation'  => $review->jobrole,
                'proposed_designation' => $isPromotion ? $this->promotedTitle($review->jobrole) : null,
                'current_grade'        => 'G' . (3 + ($index % 3)),
                'proposed_grade'       => $isPromotion ? 'G' . (4 + ($index % 3)) : null,
                'effective_date'       => $now->copy()->subMonths(3)->startOfMonth()->toDateString(),
                'status'               => $status,
                'approver_id'          => in_array($status, ['approved', 'rejected'], true) ? $facilitatorId : null,
                'approved_at'          => in_array($status, ['approved', 'rejected'], true) ? $now->copy()->subMonths(3) : null,
                'remarks'              => $recommendation === 'pip'
                    ? 'Placed on a structured improvement plan with a 90-day review.'
                    : 'Consistent contributor through the cycle.',
                'created_by'           => $facilitatorId,
                'updated_by'           => $facilitatorId,
                'created_at'           => $now->copy()->subMonths(4),
                'updated_at'           => $now->copy()->subMonths(3),
            ]);

            // Compensation revision. A PIP or exit recommendation gets no raise,
            // which is the behaviour the Compensation tab should reflect.
            if (!in_array($recommendation, ['pip', 'exit'], true)) {
                $currentCtc = 850000 + ($index % 5) * 150000;
                $incrementPct = $isPromotion ? 18 + ($index % 3) : 7 + ($index % 4);
                $incrementAmount = round($currentCtc * $incrementPct / 100, 2);

                DB::table('s_performance_compensation_revisions')->insert([
                    'sub_institute_id' => $sid,
                    'cycle_id'         => $cycles['closed'],
                    'review_id'        => $reviewId,
                    'appraisal_id'     => $appraisalId,
                    'user_id'          => (int) $employee->id,
                    'department_id'    => $review->department_id,
                    'currency'         => 'INR',
                    'current_ctc'      => $currentCtc,
                    'proposed_ctc'     => $currentCtc + $incrementAmount,
                    'increment_amount' => $incrementAmount,
                    'increment_pct'    => $incrementPct,
                    'revision_type'    => $isPromotion ? 'promotion' : $revisionTypes[$index % count($revisionTypes)],
                    'effective_date'   => $now->copy()->subMonths(3)->startOfMonth()->toDateString(),
                    'status'           => $status,
                    'approver_id'      => in_array($status, ['approved', 'rejected'], true) ? $facilitatorId : null,
                    'approved_at'      => in_array($status, ['approved', 'rejected'], true) ? $now->copy()->subMonths(3) : null,
                    'remarks'          => $isPromotion ? 'Promotion-linked revision.' : 'Annual merit revision.',
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subMonths(4),
                    'updated_at'       => $now->copy()->subMonths(3),
                ]);

                // Bonus award. Half are already paid so the tab's Paid state and
                // the "mark paid" transition both have real examples.
                $bonusStatus = $index % 2 === 0 ? 'paid' : $status;
                $bonusAmount = round($currentCtc * (8 + ($index % 5)) / 100, 2);

                DB::table('s_performance_bonus_awards')->insert([
                    'sub_institute_id' => $sid,
                    'cycle_id'         => $cycles['closed'],
                    'review_id'        => $reviewId,
                    'appraisal_id'     => $appraisalId,
                    'user_id'          => (int) $employee->id,
                    'department_id'    => $review->department_id,
                    'bonus_type'       => $bonusTypes[$index % count($bonusTypes)],
                    'currency'         => 'INR',
                    'amount'           => $bonusAmount,
                    'pct_of_ctc'       => 8 + ($index % 5),
                    'payout_month'     => $now->copy()->subMonths(2)->format('Y-m'),
                    'payout_date'      => $bonusStatus === 'paid' ? $now->copy()->subMonths(2)->day(28)->toDateString() : null,
                    'status'           => $bonusStatus,
                    'approver_id'      => in_array($bonusStatus, ['approved', 'rejected', 'paid'], true) ? $facilitatorId : null,
                    'approved_at'      => in_array($bonusStatus, ['approved', 'rejected', 'paid'], true) ? $now->copy()->subMonths(3) : null,
                    'remarks'          => 'Performance-linked payout for the closed cycle.',
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subMonths(4),
                    'updated_at'       => $now->copy()->subMonths(2),
                ]);
            }

            // Pending reward decisions on the LIVE cycle, so the "Reward
            // Decisions Pending" KPI is non-zero for the cycle on screen.
            $liveReviewId = $reviews['annual'][$employee->id] ?? null;

            if ($liveReviewId) {
                $liveCurrentCtc = 900000 + ($index % 4) * 175000;

                DB::table('s_performance_compensation_revisions')->insert([
                    'sub_institute_id' => $sid,
                    'cycle_id'         => $cycles['annual'],
                    'review_id'        => $liveReviewId,
                    'user_id'          => (int) $employee->id,
                    'department_id'    => $review->department_id,
                    'currency'         => 'INR',
                    'current_ctc'      => $liveCurrentCtc,
                    'proposed_ctc'     => $liveCurrentCtc + round($liveCurrentCtc * 0.09, 2),
                    'increment_amount' => round($liveCurrentCtc * 0.09, 2),
                    'increment_pct'    => 9,
                    'revision_type'    => 'merit',
                    'effective_date'   => $now->copy()->addMonths(3)->startOfMonth()->toDateString(),
                    'status'           => $index % 2 === 0 ? 'draft' : 'pending_approval',
                    'remarks'          => 'Proposed for the current cycle, awaiting review.',
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subDays(9),
                    'updated_at'       => $now->copy()->subDays(4),
                ]);

                DB::table('s_performance_bonus_awards')->insert([
                    'sub_institute_id' => $sid,
                    'cycle_id'         => $cycles['annual'],
                    'review_id'        => $liveReviewId,
                    'user_id'          => (int) $employee->id,
                    'department_id'    => $review->department_id,
                    'bonus_type'       => 'performance',
                    'currency'         => 'INR',
                    'amount'           => round($liveCurrentCtc * 0.1, 2),
                    'pct_of_ctc'       => 10,
                    'payout_month'     => $now->copy()->addMonths(3)->format('Y-m'),
                    'status'           => $index % 2 === 0 ? 'pending_approval' : 'draft',
                    'remarks'          => 'Proposed performance bonus for the current cycle.',
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subDays(8),
                    'updated_at'       => $now->copy()->subDays(3),
                ]);
            }

            $index++;
        }

        // --- Notes, comments and attachments ---------------------------------
        $this->seedNotesAndAttachments($sid, $reviews['annual'] ?? [], $facilitatorId, $now);

        // --- Saved views -----------------------------------------------------
        $this->seedSavedViews($sid, $facilitatorId, $now, $departmentsInPlay->first());

        // --- Activity log ----------------------------------------------------
        // Runs LAST so every entry can reference a row that actually exists.
        $this->backfillActivityLog($sid, $now);

        $this->command?->info(sprintf(
            'PerformanceSeeder: tenant %d seeded - %d cycles, %d reviews, %d goals, %d appraisals, %d comp, %d bonus, %d sessions, %d activity entries.',
            $sid,
            DB::table('s_performance_cycles')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_reviews')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_goals')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_appraisals')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_compensation_revisions')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_bonus_awards')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_calibration_sessions')->where('sub_institute_id', $sid)->count(),
            DB::table('s_performance_activity_log')->where('sub_institute_id', $sid)->count()
        ));
    }

    /* ------------------------------------------------------------------ */

    private function createCycle(int $sid, Carbon $now, array $attributes): int
    {
        return DB::table('s_performance_cycles')->insertGetId(array_merge([
            'sub_institute_id' => $sid,
            'rating_scale_max' => 5,
            'created_by'       => 1,
            'updated_by'       => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ], $attributes));
    }

    /**
     * Job role + department per employee, from their most recent competency
     * assessment. Returns [userId => ['jobrole' => ?string, 'department_id' => ?int]].
     *
     * @param  array<int, int> $userIds
     * @return array<int, array{jobrole:?string, department_id:?int}>
     */
    private function resolvePlacements(int $sid, array $userIds): array
    {
        $rows = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole')
            ->orderBy('user_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['user_id', 'jobrole', 'department_id']);

        $placements = [];

        foreach ($rows as $row) {
            // First row per user wins - the ordering above puts the newest first.
            if (isset($placements[$row->user_id])) {
                continue;
            }

            $placements[(int) $row->user_id] = [
                'jobrole'       => $row->jobrole,
                'department_id' => $row->department_id ? (int) $row->department_id : null,
            ];
        }

        // Anyone with no assessment falls back to their designation, then null.
        foreach ($userIds as $userId) {
            if (!isset($placements[$userId])) {
                $placements[$userId] = [
                    'jobrole' => DB::table('org_designation')
                        ->where('sub_institute_id', $sid)
                        ->where('user_id', $userId)
                        ->value('designation'),
                    'department_id' => null,
                ];
            }
        }

        return $placements;
    }

    /** The employee before this one in the list, so nobody manages themselves. */
    private function managerFor($employees, $employeeId): ?int
    {
        $ids = $employees->pluck('id')->all();
        $position = array_search($employeeId, $ids, true);

        if ($position === false || count($ids) < 2) {
            return null;
        }

        return (int) $ids[($position - 1 + count($ids)) % count($ids)];
    }

    /** Deterministic ratings in the 2.5 - 4.8 band, varied per stage. */
    private function ratingFor(int $seed, int $offset): float
    {
        $steps = [4.2, 3.5, 4.8, 2.8, 3.9, 4.5, 3.2, 2.5];

        return $steps[($seed * 3 + $offset * 5) % count($steps)];
    }

    /** "4 - Exceeds", matching ResolvesPerformanceContext::ratingLabel(). */
    private function label(?float $rating): ?string
    {
        if ($rating === null) {
            return null;
        }

        $band = max(1, min(5, (int) round($rating)));
        $display = floor($rating) == $rating ? (string) (int) $rating : number_format($rating, 1);

        return $display . ' - ' . self::BANDS[$band];
    }

    /** A plausible next title for a promotion recommendation. */
    private function promotedTitle(?string $jobrole): ?string
    {
        if (!$jobrole) {
            return null;
        }

        // The tenant's roles are formatted "X / Y"; promote the first variant.
        $primary = trim(explode('/', $jobrole)[0]);

        if (stripos($primary, 'senior') === 0) {
            return str_ireplace('Senior', 'Lead', $primary);
        }

        return 'Senior ' . $primary;
    }

    /**
     * @param array<int, int> $annualReviews userId => review id
     */
    private function seedNotesAndAttachments(int $sid, array $annualReviews, int $facilitatorId, Carbon $now): void
    {
        $comments = [
            'Discussed the mid-year check-in; goals remain relevant for the rest of the cycle.',
            'Self review submitted on time - the evidence attached covers the roadmap goal.',
            'Agreed to revisit the stakeholder-satisfaction target after the next survey.',
        ];

        $notes = [
            'HR note: flagged as a retention watch - discuss the compensation proposal before calibration.',
            'HR note: manager requested calibration support for the rating spread in this team.',
        ];

        $documents = [
            ['title' => 'Self review summary',       'file' => 'self-review-summary.pdf', 'type' => 'review_document'],
            ['title' => 'Goal evidence pack',        'file' => 'goal-evidence.xlsx',      'type' => 'goal_evidence'],
            ['title' => 'Manager feedback notes',    'file' => 'manager-feedback.docx',   'type' => 'review_document'],
            ['title' => 'Previous appraisal letter', 'file' => 'appraisal-letter.pdf',    'type' => 'appraisal_letter'],
        ];

        $position = 0;

        foreach ($annualReviews as $userId => $reviewId) {
            $review = DB::table('s_performance_reviews')->where('id', $reviewId)->first();

            foreach ($comments as $offset => $body) {
                DB::table('s_performance_notes')->insert([
                    'sub_institute_id' => $sid,
                    'review_id'        => $reviewId,
                    'cycle_id'         => $review->cycle_id,
                    'user_id'          => (int) $userId,
                    'note_type'        => 'comment',
                    'visibility'       => 'all',
                    'body'             => $body,
                    'author_id'        => $review->manager_id ?? $facilitatorId,
                    'author_name'      => $this->nameOf($review->manager_id ?? $facilitatorId),
                    'created_by'       => $review->manager_id ?? $facilitatorId,
                    'updated_by'       => $review->manager_id ?? $facilitatorId,
                    'created_at'       => $now->copy()->subDays(14 - $offset * 3),
                    'updated_at'       => $now->copy()->subDays(14 - $offset * 3),
                ]);
            }

            foreach ($notes as $offset => $body) {
                DB::table('s_performance_notes')->insert([
                    'sub_institute_id' => $sid,
                    'review_id'        => $reviewId,
                    'cycle_id'         => $review->cycle_id,
                    'user_id'          => (int) $userId,
                    'note_type'        => 'note',
                    'visibility'       => 'hr',
                    'body'             => $body,
                    'author_id'        => $facilitatorId,
                    'author_name'      => $this->nameOf($facilitatorId),
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subDays(10 - $offset * 2),
                    'updated_at'       => $now->copy()->subDays(10 - $offset * 2),
                ]);
            }

            foreach ($documents as $document) {
                DB::table('s_performance_attachments')->insert([
                    'sub_institute_id' => $sid,
                    'review_id'        => $reviewId,
                    'cycle_id'         => $review->cycle_id,
                    'user_id'          => (int) $userId,
                    'title'            => $document['title'],
                    'file_name'        => $document['file'],
                    // Seeded metadata only - no file is written to storage, so the
                    // path is left null rather than pointing at something absent.
                    'file_path'        => null,
                    'mime_type'        => null,
                    'file_size'        => 184320 + $position * 2048,
                    'document_type'    => $document['type'],
                    'uploaded_by'      => $review->manager_id ?? $facilitatorId,
                    'uploaded_by_name' => $this->nameOf($review->manager_id ?? $facilitatorId),
                    'created_by'       => $facilitatorId,
                    'updated_by'       => $facilitatorId,
                    'created_at'       => $now->copy()->subDays(16 - ($position % 5)),
                    'updated_at'       => $now->copy()->subDays(16 - ($position % 5)),
                ]);

                $position++;
            }
        }
    }

    private function seedSavedViews(int $sid, int $ownerId, Carbon $now, $departmentId): void
    {
        $views = [
            ['name' => 'Overdue manager reviews', 'tab' => 'reviews',      'filters' => ['stage' => 'manager_review', 'overdue_only' => '1'], 'is_shared' => 1, 'is_default' => 0],
            ['name' => 'Ready for calibration',   'tab' => 'reviews',      'filters' => ['stage' => 'calibration'],                            'is_shared' => 1, 'is_default' => 0],
            ['name' => 'My team this cycle',      'tab' => 'reviews',      'filters' => $departmentId ? ['department_id' => (string) $departmentId] : [], 'is_shared' => 0, 'is_default' => 1],
            ['name' => 'Awaiting approval',       'tab' => 'compensation', 'filters' => ['status' => 'pending_approval'],                      'is_shared' => 1, 'is_default' => 0],
            ['name' => 'Promotions proposed',     'tab' => 'appraisals',   'filters' => ['recommendation' => 'promote'],                       'is_shared' => 1, 'is_default' => 0],
            ['name' => 'Missed goals',            'tab' => 'goals',        'filters' => ['goal_status' => 'missed'],                           'is_shared' => 0, 'is_default' => 0],
        ];

        foreach ($views as $view) {
            DB::table('s_performance_saved_views')->insert([
                'sub_institute_id' => $sid,
                'user_id'          => $ownerId,
                'name'             => $view['name'],
                'tab'              => $view['tab'],
                'filters'          => json_encode($view['filters']),
                'is_shared'        => $view['is_shared'],
                'is_default'       => $view['is_default'],
                'created_by'       => $ownerId,
                'updated_by'       => $ownerId,
                'created_at'       => $now->copy()->subDays(20),
                'updated_at'       => $now->copy()->subDays(20),
            ]);
        }
    }

    /**
     * Builds the activity feed from the rows that were just seeded, so every
     * entry references a real id, name and timestamp instead of being invented.
     * Entries that represent an edit carry a real `changes` diff, which is what
     * the Audit Trail tab filters on.
     */
    private function backfillActivityLog(int $sid, Carbon $now): void
    {
        $entries = [];

        // Cycle launches.
        foreach (DB::table('s_performance_cycles')->where('sub_institute_id', $sid)->get() as $cycle) {
            $participants = DB::table('s_performance_reviews')
                ->where('cycle_id', $cycle->id)
                ->whereNull('deleted_at')
                ->count();

            $entries[] = [
                'action'       => 'created_cycle',
                'description'  => 'created review cycle ' . $cycle->name,
                'subject_type' => 'cycle',
                'subject_id'   => (int) $cycle->id,
                'subject_name' => $cycle->name,
                'changes'      => null,
                'review_id'    => null,
                'cycle_id'     => (int) $cycle->id,
                'created_at'   => $cycle->created_at,
            ];

            if ($cycle->launched_at) {
                $entries[] = [
                    'action'       => 'launched_cycle',
                    'description'  => 'launched review cycle ' . $cycle->name . ' for ' . $participants . ' employee(s)',
                    'subject_type' => 'cycle',
                    'subject_id'   => (int) $cycle->id,
                    'subject_name' => $cycle->name,
                    'changes'      => [['field' => 'status', 'label' => 'Status', 'old' => 'draft', 'new' => 'active']],
                    'review_id'    => null,
                    'cycle_id'     => (int) $cycle->id,
                    'created_at'   => $cycle->launched_at,
                ];
            }

            if ($cycle->closed_at) {
                $entries[] = [
                    'action'       => 'closed_cycle',
                    'description'  => 'closed review cycle ' . $cycle->name,
                    'subject_type' => 'cycle',
                    'subject_id'   => (int) $cycle->id,
                    'subject_name' => $cycle->name,
                    'changes'      => [['field' => 'status', 'label' => 'Status', 'old' => 'active', 'new' => 'closed']],
                    'review_id'    => null,
                    'cycle_id'     => (int) $cycle->id,
                    'created_at'   => $cycle->closed_at,
                ];
            }
        }

        // Review stage movements + reminders.
        $stageOrder = ['self_review', 'manager_review', 'calibration', 'final_review', 'completed'];
        $stageNames = [
            'self_review'    => 'Self Review',
            'manager_review' => 'Manager Review',
            'calibration'    => 'Calibration',
            'final_review'   => 'Final Review',
            'completed'      => 'Completed',
        ];

        foreach (DB::table('s_performance_reviews')->where('sub_institute_id', $sid)->get() as $review) {
            $employeeName = $this->nameOf($review->user_id) ?? ('employee #' . $review->user_id);
            $currentIndex = array_search($review->stage, $stageOrder, true);
            $currentIndex = $currentIndex === false ? 0 : $currentIndex;

            // One entry per stage the review has actually passed through.
            for ($step = 0; $step < $currentIndex; $step++) {
                $entries[] = [
                    'action'       => 'advanced_stage',
                    'description'  => 'moved stage from ' . $stageNames[$stageOrder[$step]]
                        . ' to ' . $stageNames[$stageOrder[$step + 1]] . ' for ' . $employeeName,
                    'subject_type' => 'review',
                    'subject_id'   => (int) $review->id,
                    'subject_name' => $employeeName,
                    'changes'      => [[
                        'field' => 'stage',
                        'label' => 'Stage',
                        'old'   => $stageNames[$stageOrder[$step]],
                        'new'   => $stageNames[$stageOrder[$step + 1]],
                    ]],
                    'review_id'    => (int) $review->id,
                    'cycle_id'     => (int) $review->cycle_id,
                    'created_at'   => Carbon::parse($review->updated_at)->subDays(($currentIndex - $step) * 4),
                ];
            }

            if ($review->last_reminder_at) {
                $entries[] = [
                    'action'       => 'sent_reminder',
                    'description'  => $stageNames[$review->stage] . ' reminder logged for ' . $employeeName,
                    'subject_type' => 'review',
                    'subject_id'   => (int) $review->id,
                    'subject_name' => $employeeName,
                    'changes'      => null,
                    'review_id'    => (int) $review->id,
                    'cycle_id'     => (int) $review->cycle_id,
                    'created_at'   => $review->last_reminder_at,
                ];
            }

            if ($review->calibrated_rating !== null) {
                $entries[] = [
                    'action'       => 'calibrated_rating',
                    'description'  => 'calibrated the rating for ' . $employeeName,
                    'subject_type' => 'calibration',
                    'subject_id'   => $review->calibration_session_id ? (int) $review->calibration_session_id : null,
                    'subject_name' => $employeeName,
                    'changes'      => [[
                        'field' => 'calibrated_rating',
                        'label' => 'Calibrated Rating',
                        'old'   => $review->manager_rating,
                        'new'   => $review->calibrated_rating,
                    ]],
                    'review_id'    => (int) $review->id,
                    'cycle_id'     => (int) $review->cycle_id,
                    'created_at'   => $review->calibrated_at ?? $review->updated_at,
                ];
            }
        }

        // Goals.
        foreach (DB::table('s_performance_goals')->where('sub_institute_id', $sid)->get() as $goal) {
            $employeeName = $this->nameOf($goal->user_id) ?? ('employee #' . $goal->user_id);

            $entries[] = [
                'action'       => 'created_goal',
                'description'  => 'added goal "' . $goal->title . '" for ' . $employeeName,
                'subject_type' => 'goal',
                'subject_id'   => (int) $goal->id,
                'subject_name' => $goal->title,
                'changes'      => null,
                'review_id'    => $goal->review_id ? (int) $goal->review_id : null,
                'cycle_id'     => $goal->cycle_id ? (int) $goal->cycle_id : null,
                'created_at'   => $goal->created_at,
            ];

            if ((int) $goal->progress > 0) {
                $entries[] = [
                    'action'       => 'updated_goal',
                    'description'  => 'updated progress on goal "' . $goal->title . '"',
                    'subject_type' => 'goal',
                    'subject_id'   => (int) $goal->id,
                    'subject_name' => $goal->title,
                    'changes'      => [[
                        'field' => 'progress',
                        'label' => 'Progress',
                        'old'   => 0,
                        'new'   => (int) $goal->progress,
                    ]],
                    'review_id'    => $goal->review_id ? (int) $goal->review_id : null,
                    'cycle_id'     => $goal->cycle_id ? (int) $goal->cycle_id : null,
                    'created_at'   => $goal->updated_at,
                ];
            }
        }

        // Appraisals and reward decisions.
        foreach (DB::table('s_performance_appraisals')->where('sub_institute_id', $sid)->get() as $appraisal) {
            $employeeName = $this->nameOf($appraisal->user_id) ?? ('employee #' . $appraisal->user_id);

            $entries[] = [
                'action'       => 'created_appraisal',
                'description'  => 'created an appraisal for ' . $employeeName,
                'subject_type' => 'appraisal',
                'subject_id'   => (int) $appraisal->id,
                'subject_name' => $employeeName,
                'changes'      => null,
                'review_id'    => $appraisal->review_id ? (int) $appraisal->review_id : null,
                'cycle_id'     => $appraisal->cycle_id ? (int) $appraisal->cycle_id : null,
                'created_at'   => $appraisal->created_at,
            ];

            if ($appraisal->approved_at) {
                $entries[] = [
                    'action'       => $appraisal->status === 'rejected' ? 'rejected_appraisal' : 'approved_appraisal',
                    'description'  => ucfirst((string) $appraisal->status) . ' the appraisal for ' . $employeeName,
                    'subject_type' => 'appraisal',
                    'subject_id'   => (int) $appraisal->id,
                    'subject_name' => $employeeName,
                    'changes'      => [[
                        'field' => 'status',
                        'label' => 'Status',
                        'old'   => 'Pending Approval',
                        'new'   => ucfirst((string) $appraisal->status),
                    ]],
                    'review_id'    => $appraisal->review_id ? (int) $appraisal->review_id : null,
                    'cycle_id'     => $appraisal->cycle_id ? (int) $appraisal->cycle_id : null,
                    'created_at'   => $appraisal->approved_at,
                ];
            }
        }

        foreach (DB::table('s_performance_compensation_revisions')->where('sub_institute_id', $sid)->get() as $revision) {
            $employeeName = $this->nameOf($revision->user_id) ?? ('employee #' . $revision->user_id);

            $entries[] = [
                'action'       => 'created_compensation',
                'description'  => 'proposed a compensation revision for ' . $employeeName,
                'subject_type' => 'compensation',
                'subject_id'   => (int) $revision->id,
                'subject_name' => $employeeName,
                'changes'      => null,
                'review_id'    => $revision->review_id ? (int) $revision->review_id : null,
                'cycle_id'     => $revision->cycle_id ? (int) $revision->cycle_id : null,
                'created_at'   => $revision->created_at,
            ];

            if ($revision->approved_at) {
                $entries[] = [
                    'action'       => $revision->status === 'rejected' ? 'rejected_compensation' : 'approved_compensation',
                    'description'  => ucfirst((string) $revision->status) . ' the compensation revision for ' . $employeeName,
                    'subject_type' => 'compensation',
                    'subject_id'   => (int) $revision->id,
                    'subject_name' => $employeeName,
                    'changes'      => [[
                        'field' => 'status',
                        'label' => 'Status',
                        'old'   => 'Pending Approval',
                        'new'   => ucfirst((string) $revision->status),
                    ]],
                    'review_id'    => $revision->review_id ? (int) $revision->review_id : null,
                    'cycle_id'     => $revision->cycle_id ? (int) $revision->cycle_id : null,
                    'created_at'   => $revision->approved_at,
                ];
            }
        }

        foreach (DB::table('s_performance_bonus_awards')->where('sub_institute_id', $sid)->get() as $award) {
            $employeeName = $this->nameOf($award->user_id) ?? ('employee #' . $award->user_id);

            $entries[] = [
                'action'       => 'created_bonus',
                'description'  => 'proposed a ' . str_replace('_', ' ', (string) $award->bonus_type)
                    . ' bonus for ' . $employeeName,
                'subject_type' => 'bonus',
                'subject_id'   => (int) $award->id,
                'subject_name' => $employeeName,
                'changes'      => null,
                'review_id'    => $award->review_id ? (int) $award->review_id : null,
                'cycle_id'     => $award->cycle_id ? (int) $award->cycle_id : null,
                'created_at'   => $award->created_at,
            ];

            if ($award->status === 'paid') {
                $entries[] = [
                    'action'       => 'mark_paid_bonus',
                    'description'  => 'Paid the bonus award for ' . $employeeName,
                    'subject_type' => 'bonus',
                    'subject_id'   => (int) $award->id,
                    'subject_name' => $employeeName,
                    'changes'      => [[
                        'field' => 'status',
                        'label' => 'Status',
                        'old'   => 'Approved',
                        'new'   => 'Paid',
                    ]],
                    'review_id'    => $award->review_id ? (int) $award->review_id : null,
                    'cycle_id'     => $award->cycle_id ? (int) $award->cycle_id : null,
                    'created_at'   => $award->updated_at,
                ];
            }
        }

        // Calibration sessions.
        foreach (DB::table('s_performance_calibration_sessions')->where('sub_institute_id', $sid)->get() as $session) {
            $entries[] = [
                'action'       => 'created_calibration_session',
                'description'  => 'created calibration session ' . $session->name
                    . ' with ' . $session->participant_count . ' participant(s)',
                'subject_type' => 'calibration',
                'subject_id'   => (int) $session->id,
                'subject_name' => $session->name,
                'changes'      => null,
                'review_id'    => null,
                'cycle_id'     => (int) $session->cycle_id,
                'created_at'   => $session->created_at,
            ];
        }

        // Notes, comments and attachments.
        foreach (DB::table('s_performance_notes')->where('sub_institute_id', $sid)->get() as $note) {
            $employeeName = $this->nameOf($note->user_id) ?? ('employee #' . $note->user_id);

            $entries[] = [
                'action'       => $note->note_type === 'note' ? 'added_note' : 'commented',
                'description'  => ($note->note_type === 'note' ? 'added a note on ' : 'commented on ')
                    . $employeeName . "'s review",
                'subject_type' => 'note',
                'subject_id'   => (int) $note->id,
                'subject_name' => $employeeName,
                'changes'      => null,
                'review_id'    => (int) $note->review_id,
                'cycle_id'     => $note->cycle_id ? (int) $note->cycle_id : null,
                'created_at'   => $note->created_at,
                'actor_id'     => $note->author_id ? (int) $note->author_id : null,
            ];
        }

        foreach (DB::table('s_performance_attachments')->where('sub_institute_id', $sid)->get() as $attachment) {
            $employeeName = $this->nameOf($attachment->user_id) ?? ('employee #' . $attachment->user_id);

            $entries[] = [
                'action'       => 'uploaded_attachment',
                'description'  => 'attached "' . $attachment->file_name . '" to ' . $employeeName . "'s review",
                'subject_type' => 'attachment',
                'subject_id'   => (int) $attachment->id,
                'subject_name' => $attachment->file_name,
                'changes'      => null,
                'review_id'    => (int) $attachment->review_id,
                'cycle_id'     => $attachment->cycle_id ? (int) $attachment->cycle_id : null,
                'created_at'   => $attachment->created_at,
                'actor_id'     => $attachment->uploaded_by ? (int) $attachment->uploaded_by : null,
            ];
        }

        // Saved views.
        foreach (DB::table('s_performance_saved_views')->where('sub_institute_id', $sid)->get() as $view) {
            $entries[] = [
                'action'       => 'created_saved_view',
                'description'  => 'saved the view "' . $view->name . '" on the ' . $view->tab . ' tab',
                'subject_type' => 'saved_view',
                'subject_id'   => (int) $view->id,
                'subject_name' => $view->name,
                'changes'      => null,
                'review_id'    => null,
                'cycle_id'     => null,
                'created_at'   => $view->created_at,
                'actor_id'     => (int) $view->user_id,
            ];
        }

        // The default actor is the tenant's first user - the same id the seeded
        // rows carry as created_by.
        $defaultActor = (int) DB::table('tbluser')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        $rows = [];

        foreach ($entries as $entry) {
            $actorId = $entry['actor_id'] ?? $defaultActor;

            $rows[] = [
                'sub_institute_id' => $sid,
                'user_id'          => $actorId,
                'actor_name'       => $this->nameOf($actorId),
                'action'           => $entry['action'],
                'description'      => $entry['description'],
                'subject_type'     => $entry['subject_type'],
                'subject_id'       => $entry['subject_id'],
                'subject_name'     => $entry['subject_name'] !== null ? mb_substr($entry['subject_name'], 0, 191) : null,
                'changes'          => $entry['changes'] ? json_encode($entry['changes']) : null,
                'review_id'        => $entry['review_id'],
                'cycle_id'         => $entry['cycle_id'],
                'created_at'       => $entry['created_at'],
                'updated_at'       => $entry['created_at'],
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('s_performance_activity_log')->insert($chunk);
        }
    }

    /** @var array<int, ?string> */
    private array $nameCache = [];

    private function nameOf($userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $userId = (int) $userId;

        if (array_key_exists($userId, $this->nameCache)) {
            return $this->nameCache[$userId];
        }

        $user = DB::table('tbluser')->where('id', $userId)->first(['first_name', 'last_name', 'user_name']);

        if (!$user) {
            return $this->nameCache[$userId] = null;
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $this->nameCache[$userId] = ($name !== '' ? $name : ($user->user_name ?? null));
    }
}
