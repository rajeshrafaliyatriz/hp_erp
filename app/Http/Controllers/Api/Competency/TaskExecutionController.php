<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use App\Services\Competency\TaskExecutionClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * ESO — how a job role's work is actually executed.
 *
 * Four things: classify a role's tasks, read them back, approve or override a
 * proposal, and aggregate a role into a work composition.
 *
 * ── THE ONE RULE RUNNING THROUGH ALL OF IT ──────────────────────────────────
 *
 * An AI proposal is not a fact. Classification writes `AI-proposed`; only a
 * person writes `Approved`; and the composition map counts approved and
 * proposed SEPARATELY so nobody presents a model's opinion to a customer as a
 * finding. `Unclassified` is its own bucket and is never folded into "human" -
 * not-yet-looked-at and needs-a-person are different facts.
 */
class TaskExecutionController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * The two statuses that mean A PERSON DECIDED THIS.
     *
     * `Approved` accepted the model's answer; `Human-reviewed` rejected and
     * replaced it. Both are human decisions and both count as such everywhere -
     * in the composition map, in the role rollup, and in what a customer is
     * shown. Only `AI-proposed` is the machine talking.
     */
    private const HUMAN_DECIDED = ['Approved', 'Human-reviewed'];

    public function __construct(private readonly TaskExecutionClassifier $classifier)
    {
    }

    /**
     * POST /competency/task-execution/classify
     *
     * One job role at a time. See TaskExecutionClassifier::classifyRole for why
     * this is synchronous rather than queued.
     */
    public function classify(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'jobrole'     => 'required|string|max:191',
            'reclassify'  => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $result = $this->classifier->classifyRole(
            (int) $context['sub_institute_id'],
            (string) $request->input('jobrole'),
            (int) $context['user_id'],
            $request->boolean('reclassify'),
        );

        // Every refusal names itself, and none of them is a silent empty result.
        $refusals = [
            'not_configured' => ['AI classification is not configured on this server, so nothing was classified.', 503],
            'ai_error'       => ['The model could not be reached, so nothing was classified. No task was guessed at.', 502],
            'no_tasks'       => ['This job role has no tasks to classify. Add them in the Job Role Task library first.', 422],
            'already_classified' => ['Every task on this role is already classified. Re-run with reclassify to redo them.', 200],
            // A re-run cannot touch these. Undoing a person's decision is done
            // deliberately through review, not as a side effect of classify.
            'all_reviewed'   => ['Every task on this role has already been decided by a person, so nothing was re-classified. Reset a classification from the review screen if you want the model to look again.', 200],
            // Refused BEFORE sending — nothing was charged, and the fix is a
            // top-up rather than a retry. Distinct from a failure for that reason.
            'insufficient_balance' => ['The AI account balance is too low to run this safely, so nothing was sent and nothing was charged.', 402],
            // Sent, billed, and unusable. The one failure that costs money, so it
            // says so plainly rather than hiding inside a generic error.
            'truncated'      => ['The model ran out of room before finishing, so the answer could not be used. This role may be too large for a single pass.', 502],
        ];

        if ($result['reason'] !== null) {
            [$message, $code] = $refusals[$result['reason']];
            return response()->json([
                'status'  => $code === 200 ? 1 : 0,
                'reason'  => $result['reason'],
                // The provider's own words, when there are any — a balance floor
                // or a token limit is worth quoting exactly.
                'detail'  => $result['detail'] ?? null,
                'message' => $message,
                'data'    => $result,
            ], $code);
        }

        return response()->json([
            'status'  => 1,
            'data'    => $result,
            'message' => sprintf(
                '%d row(s) classified.%s%s%s%s Nothing is approved yet — review them before they count.',
                $result['rows_written'],
                $result['classified']
                    ? " {$result['classified']} description(s) went to the model." : '',
                $result['reused_rows']
                    ? " {$result['reused_rows']} row(s) reused a classification this organisation already had, at no cost." : '',
                $result['clamped'] ? " {$result['clamped']} capped by risk class." : '',
                $result['dropped'] ? " {$result['dropped']} answer(s) named a task that was not sent and were dropped." : '',
            ),
        ]);
    }

    /**
     * GET /competency/task-execution?jobrole=...
     *
     * A role's tasks with their classification. Unclassified tasks are RETURNED,
     * not filtered out - the gap is the point.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('s_user_jobrole_task as t')
            ->leftJoin('jobrole_task_execution as e', function ($j) use ($sid) {
                $j->on('e.user_jobrole_task_id', '=', 't.id')->where('e.sub_institute_id', '=', $sid);
            })
            ->where('t.sub_institute_id', $sid)
            ->whereNull('t.deleted_at')
            ->when($request->filled('jobrole'), fn ($q) => $q->whereRaw(
                'TRIM(LOWER(t.jobrole)) = ?', [mb_strtolower(trim((string) $request->input('jobrole')))]))
            ->when($request->filled('status'), fn ($q) => $q->where('e.classification_status', $request->input('status')))
            ->orderBy('t.critical_work_function')->orderBy('t.id')
            ->limit(500)
            ->get([
                't.id', 't.task', 't.critical_work_function', 't.jobrole',
                'e.id as execution_id', 'e.execution_mode_current', 'e.execution_mode_target',
                'e.digital_input', 'e.rule_clarity', 'e.judgment_required', 'e.error_consequence',
                'e.ai_executability_score', 'e.risk_class', 'e.automation_rationale',
                'e.human_effort_current_min', 'e.human_effort_target_min',
                'e.classification_status', 'e.model', 'e.reviewed_at',
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows,
            // The vocabulary and the policy, so a screen never hardcodes either.
            'modes'  => TaskExecutionClassifier::MODES,
            'risk_ceiling' => TaskExecutionClassifier::RISK_CEILING,
            'weights' => TaskExecutionClassifier::WEIGHTS,
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'This job role has no tasks yet. Add them in the Job Role Task library.',
        ]);
    }

    /**
     * POST /competency/task-execution/review
     *
     * Approve proposals, or override one with a reason.
     *
     * Overriding REQUIRES a reason. A classification changed without one is
     * indistinguishable later from a mistake, and this record is meant to
     * survive the person who made it.
     */
    public function review(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'execution_ids'   => 'required|array|min:1',
            'execution_ids.*' => 'integer',
            // `reset` returns a row to the model's proposal, and is the ONLY
            // supported un-approve. Before it existed the only way back was
            // reclassify, which destroyed the decision instead of reverting it.
            'decision'        => 'required|string|in:approve,override,reset',
            'execution_mode_target' => 'required_if:decision,override|string',
            'risk_class'      => 'nullable|string|in:' . implode(',', TaskExecutionClassifier::RISK_CLASSES),
            'note'            => 'required_if:decision,override|required_if:decision,reset|nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid   = (int) $context['sub_institute_id'];
        $actor = (int) $context['user_id'];
        $ids   = $request->input('execution_ids');

        // Read BEFORE the write, so the audit trail can say what actually changed
        // rather than only what it was changed to.
        $before = DB::table('jobrole_task_execution')
            ->where('sub_institute_id', $sid)->whereIn('id', $ids)
            ->get(['id', 'user_jobrole_task_id', 'execution_mode_target', 'risk_class',
                   'classification_status', 'review_note'])
            ->keyBy('id');

        if ($before->isEmpty()) {
            return response()->json([
                'status' => 0,
                'message' => 'None of those classifications belong to your organisation.',
            ], 404);
        }

        if ($request->input('decision') === 'reset') {
            /*
             * Clear the decision AND the attribution together.
             *
             * Leaving reviewed_by/reviewed_at behind on a row marked AI-proposed
             * is what made the old reclassify bug so damaging: the row named a
             * person who had not seen the value it now held.
             */
            $n = DB::table('jobrole_task_execution')
                ->where('sub_institute_id', $sid)->whereIn('id', $ids)
                ->update([
                    'classification_status' => 'AI-proposed',
                    'reviewed_by' => null, 'reviewed_at' => null,
                    'review_note' => $request->input('note'),
                    'updated_at' => now(),
                ]);

            $this->auditReview($context, 'reset', $before, [
                'note' => $request->input('note'),
            ]);

            return response()->json([
                'status' => 1, 'data' => ['reset' => $n],
                'message' => "$n classification(s) returned to AI-proposed. They no longer count "
                    . 'in the work composition until somebody reviews them again.',
            ]);
        }

        if ($request->input('decision') === 'approve') {
            $update = [
                'classification_status' => 'Approved',
                'reviewed_by' => $actor, 'reviewed_at' => now(),
                'updated_at' => now(),
            ];

            /*
             * Only overwrite the note when one was actually given.
             *
             * Approving without a note used to null the column - erasing the
             * reason a previous override was REQUIRED to supply. A blank field
             * should never delete somebody's stated reasoning.
             */
            if ($request->filled('note')) {
                $update['review_note'] = $request->input('note');
            }

            $n = DB::table('jobrole_task_execution')
                ->where('sub_institute_id', $sid)->whereIn('id', $ids)
                ->update($update);

            $this->auditReview($context, 'approved', $before, [
                'note' => $request->input('note'),
            ]);

            return response()->json([
                'status' => 1, 'data' => ['approved' => $n],
                'message' => "$n classification(s) approved. They now count in the work composition.",
            ]);
        }

        $mode = (string) $request->input('execution_mode_target');
        if (!array_key_exists($mode, TaskExecutionClassifier::MODES)) {
            return response()->json(['status' => 0, 'message' => 'That is not a known execution mode.'], 422);
        }

        /*
         * THE RISK CEILING APPLIES TO PEOPLE TOO.
         *
         * A reviewer overriding a Regulated task to ai_autonomous is doing the
         * thing the ceiling exists to prevent, and "a human chose it" does not
         * make a regulated task safe to run unattended. Refused, in words,
         * naming the cap - rather than silently clamped, because unlike a model
         * a person can be told why.
         */
        $rows = DB::table('jobrole_task_execution')
            ->where('sub_institute_id', $sid)->whereIn('id', $ids)
            ->get(['id', 'risk_class', 'automation_rationale']);

        $risk = $request->input('risk_class');
        $blocked = [];

        foreach ($rows as $row) {
            $effective = $risk ?: ($row->risk_class ?: 'High');
            if ($this->classifier->clamp($mode, $effective) !== $mode) {
                $blocked[] = $effective;
            }
        }

        if ($blocked !== []) {
            $worst = $blocked[0];
            return response()->json([
                'status'  => 0,
                'reason'  => 'risk_ceiling',
                'message' => sprintf(
                    'A %s task cannot be set to %s. The most autonomous mode allowed for %s work is %s. '
                    . 'Change the risk class first if that classification is wrong.',
                    $worst, $mode, $worst, TaskExecutionClassifier::RISK_CEILING[$worst] ?? 'ai_human_review'
                ),
            ], 422);
        }

        $n = DB::table('jobrole_task_execution')
            ->where('sub_institute_id', $sid)->whereIn('id', $ids)
            ->update([
                'execution_mode_target' => $mode,
                'classification_status' => 'Human-reviewed',
                'reviewed_by' => $actor, 'reviewed_at' => now(),
                'review_note' => $request->input('note'),
                'updated_at' => now(),
            ] + ($risk ? ['risk_class' => $risk] : []));

        $this->auditReview($context, 'overridden', $before, [
            'execution_mode_target' => $mode,
            'risk_class' => $risk,
            'note' => $request->input('note'),
        ]);

        return response()->json([
            'status' => 1, 'data' => ['overridden' => $n],
            'message' => "$n classification(s) overridden to $mode and marked human-reviewed. "
                . 'They count as a human decision in the work composition.',
        ]);
    }

    /**
     * Record a review decision where it cannot be quietly overwritten.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     *
     * Approving "this task can be executed by AI" is a statement about a real
     * person's job. Before this, it wrote nothing but mutable columns on the row
     * itself - and a re-run overwrote those, so the decision left no trace at all.
     *
     * Two sinks, following JobRoleMergeController:
     *   s_competency_activity_log  the table the Audit & Activity Center reads,
     *                              so this shows up on a screen that already exists
     *   g2g_event                  append-only, so it survives any later write
     *
     * Neither is allowed to fail the operation it describes. An audit write that
     * takes down an approval would be a worse bug than the one it is fixing.
     */
    private function auditReview(array $context, string $action, $before, array $applied): void
    {
        $sid   = (int) $context['sub_institute_id'];
        $actor = $context['user_id'] !== null ? (int) $context['user_id'] : null;
        $ids   = $before->keys()->all();

        // A per-row before/after, so the log answers "what did this change"
        // rather than only "somebody pressed approve".
        $rows = $before->map(fn ($r) => [
            'execution_id' => (int) $r->id,
            'task_id'      => (int) $r->user_jobrole_task_id,
            'from_status'  => $r->classification_status,
            'from_mode'    => $r->execution_mode_target,
            'from_risk'    => $r->risk_class,
        ])->values()->all();

        try {
            $this->logCompetencyActivity(
                $sid,
                $actor,
                $action,
                sprintf('%s %d task execution classification(s).', ucfirst($action), count($ids)),
                'task_execution',
                (int) ($ids[0] ?? 0),
                count($ids) === 1
                    ? 'Task execution classification'
                    : count($ids) . ' task execution classifications',
                array_values(array_filter([
                    $applied['execution_mode_target'] ?? null ? [
                        'field' => 'execution_mode_target', 'label' => 'Execution mode',
                        'old' => $rows[0]['from_mode'] ?? null, 'new' => $applied['execution_mode_target'],
                    ] : null,
                    $applied['risk_class'] ?? null ? [
                        'field' => 'risk_class', 'label' => 'Risk class',
                        'old' => $rows[0]['from_risk'] ?? null, 'new' => $applied['risk_class'],
                    ] : null,
                    [
                        'field' => 'classification_status', 'label' => 'Status',
                        'old' => $rows[0]['from_status'] ?? null,
                        'new' => $action === 'approved' ? 'Approved'
                            : ($action === 'reset' ? 'AI-proposed' : 'Human-reviewed'),
                    ],
                ]))
            );
        } catch (\Throwable $e) {
            Log::warning('Task execution review applied but not written to the activity log', [
                'action' => $action, 'ids' => $ids, 'error' => $e->getMessage(),
            ]);
        }

        try {
            app(\App\Services\Events\EventRecorder::class)->record(
                'task_execution.' . $action,
                $sid,
                'task_execution',
                (int) ($ids[0] ?? 0),
                $actor,
                ['decision' => $action, 'applied' => $applied, 'rows' => $rows]
            );
        } catch (\Throwable $e) {
            Log::warning('Task execution review applied but not recorded in the event store', [
                'action' => $action, 'ids' => $ids, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /competency/task-execution/composition?jobrole=...
     *
     * THE WORK COMPOSITION MAP — what share of a role is human, hybrid or
     * automatable. This is the artifact worth showing a customer, and it works
     * with zero ESO specs authored.
     *
     * Approved and proposed are counted SEPARATELY. A demo that presents an
     * unreviewed model opinion as a finding is the one way this feature loses
     * trust permanently.
     */
    public function composition(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('s_user_jobrole_task as t')
            ->leftJoin('jobrole_task_execution as e', function ($j) use ($sid) {
                $j->on('e.user_jobrole_task_id', '=', 't.id')->where('e.sub_institute_id', '=', $sid);
            })
            ->where('t.sub_institute_id', $sid)
            ->whereNull('t.deleted_at')
            ->when($request->filled('jobrole'), fn ($q) => $q->whereRaw(
                'TRIM(LOWER(t.jobrole)) = ?', [mb_strtolower(trim((string) $request->input('jobrole')))]))
            ->get(['t.id', 'e.execution_mode_target', 'e.classification_status',
                   'e.ai_executability_score', 'e.human_effort_current_min', 'e.human_effort_target_min']);

        $blank = array_fill_keys(array_keys(TaskExecutionClassifier::MODES), 0);

        /*
         * TWO SEPARATE MODE TALLIES, and the reason is the whole point of this
         * endpoint.
         *
         * `modes` counts every classified task. `modes_approved` counts only
         * the ones a person confirmed. A headline share computed from the first
         * but LABELLED as approved-only is the failure this class exists to
         * prevent - it reports a model's unreviewed opinion as a finding, in a
         * number somebody will quote to a customer.
         */
        $modes = $blank;
        $modesApproved = $blank;

        $approved = 0; $proposed = 0; $unclassified = 0;
        $effortNow = 0; $effortTarget = 0; $effortKnown = 0; $scoreSum = 0; $scored = 0;

        foreach ($rows as $row) {
            if ($row->execution_mode_target === null) {
                // NOT folded into human_only. Nobody has looked at it yet, and
                // that is a different statement from "a person must do it".
                $unclassified++;
                continue;
            }

            $modes[$row->execution_mode_target] = ($modes[$row->execution_mode_target] ?? 0) + 1;

            /*
             * BOTH HUMAN STATES COUNT AS HUMAN.
             *
             * This used to test `=== 'Approved'` alone, so a row a person had
             * explicitly OVERRIDDEN - the strongest signal in the system, because
             * somebody looked at the model's answer and rejected it - fell into
             * the else and was reported to the customer as an unreviewed AI
             * proposal. It was excluded from modes_approved, from automatable,
             * from the percentage and from the effort roll-up.
             *
             * A human decision must never be reported as a machine's.
             */
            if (in_array($row->classification_status, self::HUMAN_DECIDED, true)) {
                $approved++;
                $modesApproved[$row->execution_mode_target] =
                    ($modesApproved[$row->execution_mode_target] ?? 0) + 1;

                // The effort figures follow approval too. An hours-saved number
                // built from unreviewed guesses is the most quotable and the
                // least defensible thing this screen could produce.
                if ($row->human_effort_current_min !== null && $row->human_effort_target_min !== null) {
                    $effortNow += (int) $row->human_effort_current_min;
                    $effortTarget += (int) $row->human_effort_target_min;
                    $effortKnown++;
                }
            } else {
                $proposed++;
            }

            if ($row->ai_executability_score !== null) { $scoreSum += (int) $row->ai_executability_score; $scored++; }
        }

        $classified = $approved + $proposed;

        // "Automatable" means the machine does the work - a human still reviews
        // in ai_human_review, so it counts. Measured on APPROVED rows only.
        $automatable = ($modesApproved['ai_human_review'] ?? 0) + ($modesApproved['ai_supervised'] ?? 0)
                     + ($modesApproved['ai_autonomous'] ?? 0) + ($modesApproved['system_automated'] ?? 0);

        return response()->json([
            'status' => 1,
            'data' => [
                'total_tasks'  => $rows->count(),
                'classified'   => $classified,
                'approved'     => $approved,
                'proposed'     => $proposed,
                'unclassified' => $unclassified,
                'modes'          => $modes,
                'modes_approved' => $modesApproved,
                'automatable'  => $automatable,
                // NULL, not 0, when nothing is approved. Zero-approved and
                // zero-automatable are different facts and a screen must be
                // able to tell them apart.
                'automatable_percent' => $approved > 0 ? round($automatable / $approved * 100, 1) : null,
                'automatable_basis' => 'approved',
                'average_executability' => $scored > 0 ? (int) round($scoreSum / $scored) : null,
                'effort' => [
                    // Only from APPROVED tasks where BOTH numbers are known, and
                    // it says how many that was - a total over an unknown
                    // denominator is a number nobody can check.
                    'tasks_with_estimates' => $effortKnown,
                    'current_minutes' => $effortKnown ? $effortNow : null,
                    'target_minutes'  => $effortKnown ? $effortTarget : null,
                    'released_minutes' => $effortKnown ? max(0, $effortNow - $effortTarget) : null,
                ],
            ],
            'modes_meta' => TaskExecutionClassifier::MODES,
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'This job role has no tasks yet.',
        ]);
    }

    /**
     * GET /competency/task-execution/roles
     *
     * Every role with how far its classification has got. The entry point for
     * the review screen.
     */
    public function roles(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('s_user_jobrole_task as t')
            ->leftJoin('jobrole_task_execution as e', function ($j) use ($sid) {
                $j->on('e.user_jobrole_task_id', '=', 't.id')->where('e.sub_institute_id', '=', $sid);
            })
            ->where('t.sub_institute_id', $sid)->whereNull('t.deleted_at')
            ->whereNotNull('t.jobrole')->where('t.jobrole', '<>', '')
            ->groupBy('t.jobrole')
            ->orderBy('t.jobrole')
            ->get([
                't.jobrole',
                DB::raw('COUNT(*) as tasks'),
                DB::raw('SUM(e.id IS NOT NULL) as classified'),
                // Both human states, matching composition(). Counting only
                // 'Approved' here reported an overridden row as unreviewed.
                DB::raw("SUM(e.classification_status IN ('Approved','Human-reviewed')) as approved"),
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows,
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'No job role has any tasks yet.',
        ]);
    }
}
