<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use App\Services\Competency\EsoExporter;
use App\Services\Competency\TaskExecutionClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "How do I do this?" — the procedure behind a task somebody has been assigned.
 *
 * ── WHY THIS IS A SEPARATE CONTROLLER ───────────────────────────────────────
 *
 * Every other ESO endpoint is `profile:admin,hr`, and rightly so: the work
 * composition map is a workforce-planning statement about people's jobs.
 *
 * This one is different. The person who most needs the procedure is the
 * employee doing the work, and they are not an administrator. So this endpoint
 * is token-authenticated and scoped by OWNERSHIP: you get the instructions for
 * a task if it is assigned to you, or you assigned it, or you are admin/hr.
 *
 * That ownership check is the whole security story of this file. Without it,
 * any authenticated employee could enumerate task ids and read their
 * organisation's procedures.
 *
 * ── WHAT IT DELIBERATELY DOES NOT RETURN ────────────────────────────────────
 *
 * No executability score, no risk class, no "this task could be automated"
 * figure. An employee opening their own work should not be told, in passing,
 * that a machine could do it — that is a conversation for a manager to have
 * with them, not a badge on a task drawer. The scores stay on the admin screens
 * where the audience is the person making the decision.
 */
class TaskInstructionController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * GET /competency/task-instructions/{taskId}
     *
     * `taskId` is a `task.id` — the assigned work item, not the job role task.
     */
    public function show(Request $request, int $taskId)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid  = (int) $context['sub_institute_id'];
        $user = $context['user_id'] !== null ? (int) $context['user_id'] : 0;

        $task = DB::table('task')
            ->where('id', $taskId)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first(['id', 'task_title', 'task_description', 'jobrole_task_id',
                     'task_allocated_to', 'task_allocated', 'acceptance_criteria',
                     'observation_point', 'kra', 'kpa']);

        if (!$task) {
            return response()->json([
                'status' => 0,
                'message' => 'That task does not exist in your organisation.',
            ], 404);
        }

        // OWNERSHIP. Assignee, assigner, or an elevated role — nobody else.
        $isMine = (int) $task->task_allocated_to === $user || (int) $task->task_allocated === $user;
        if (!$isMine && !$this->isElevated($user)) {
            return response()->json([
                'status'  => 0,
                'message' => 'This task is not assigned to you, so its instructions are not yours to read.',
            ], 403);
        }

        /*
         * NO LINKED DUTY IS ITS OWN ANSWER, NOT AN EMPTY ONE.
         *
         * Roughly half of existing work items could not be matched to a job role
         * task without guessing, and a one-off task legitimately has no duty
         * behind it at all. Either way the screen should say so rather than
         * render a blank procedure, which reads as "there are no instructions".
         */
        if (!$task->jobrole_task_id) {
            return response()->json([
                'status' => 1,
                'data' => [
                    'has_duty' => false, 'has_eso' => false,
                    'eso' => null, 'execution' => null,
                    'task' => ['id' => (int) $task->id, 'title' => $task->task_title],
                    'acceptance_criteria' => $task->acceptance_criteria,
                    'observation_point' => $task->observation_point,
                ],
                'reason' => 'no_duty',
                'message' => 'This task is not linked to a job role duty, so there is no standard '
                    . 'procedure behind it. Follow the description and acceptance criteria.',
            ]);
        }

        $duty = DB::table('s_user_jobrole_task')
            ->where('id', $task->jobrole_task_id)
            ->first(['id', 'task', 'jobrole', 'critical_work_function']);

        // The mode only — how this work is meant to be performed. Not the score.
        $execution = DB::table('jobrole_task_execution')
            ->where('sub_institute_id', $sid)
            ->where('user_jobrole_task_id', $task->jobrole_task_id)
            ->first(['execution_mode_target', 'classification_status']);

        $eso = DB::table('eso')
            ->where('sub_institute_id', $sid)
            ->where('user_jobrole_task_id', $task->jobrole_task_id)
            ->whereNull('deleted_at')
            ->orderByRaw("FIELD(status,'Published','Reviewed','Draft','Retired')")
            ->first();

        $mode = $execution->execution_mode_target ?? null;

        return response()->json([
            'status' => 1,
            'data' => [
                'has_duty' => true,
                'has_eso'  => $eso !== null,
                'task' => ['id' => (int) $task->id, 'title' => $task->task_title],
                'duty' => $duty ? [
                    'task' => $duty->task,
                    'jobrole' => $duty->jobrole,
                    'critical_work_function' => $duty->critical_work_function,
                ] : null,
                'execution' => $mode ? [
                    'mode' => $mode,
                    'mode_meaning' => TaskExecutionClassifier::MODES[$mode] ?? null,
                    // Whether a person has agreed this is how the work is done.
                    'confirmed' => in_array($execution->classification_status, ['Approved', 'Human-reviewed'], true),
                ] : null,
                'eso' => $eso ? $this->instructions($eso) : null,
                'acceptance_criteria' => $task->acceptance_criteria,
                'observation_point' => $task->observation_point,
                'kra' => $task->kra,
                'kpa' => $task->kpa,
            ],
            'reason' => $eso ? null : 'no_eso',
            'message' => $eso
                ? null
                : 'This duty has no written procedure yet. Ask your manager, or follow the '
                    . 'description and acceptance criteria.',
        ]);
    }

    /**
     * Roles that may read a procedure for work assigned to somebody else.
     *
     * Deliberately narrower than the trait's own elevated list: this is about
     * reading one person's work instructions, and `auditor` / `executive` have
     * no operational reason to. Matched on `role_key`, the stable machine name,
     * never on a substring of the display name.
     */
    private function isElevated(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        $roleKey = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', $userId)
            ->value('p.role_key');

        return in_array((string) $roleKey,
            ['administrator', 'hr_manager', 'hr_executive'], true);
    }

    /**
     * The parts of an ESO an employee needs to DO the work.
     *
     * Steps, controls, prohibitions, escalation, inputs and outputs — the
     * operating half. The allocation and evidence fields are for whoever
     * designs the work, not for whoever performs it, so they are left out to
     * keep the drawer readable.
     */
    private function instructions(object $eso): array
    {
        $lists = [];
        foreach (EsoController::LIST_FIELDS as $field) {
            $raw = $eso->$field ?? null;
            $decoded = $raw !== null && $raw !== '' ? json_decode((string) $raw, true) : null;
            $lists[$field] = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) $eso->id,
            'title' => $eso->title,
            'version' => (int) $eso->version,
            'status' => $eso->status,
            'source' => $eso->source,
            // Only a Published procedure is the agreed way of working. Anything
            // else is shown with that said plainly, never silently.
            'is_agreed' => $eso->status === 'Published',
            'objective' => $eso->objective,
            'expected_outcome' => $eso->expected_outcome,
            'human_responsibility' => $eso->human_responsibility,
            'steps' => $lists['steps'],
            'required_controls' => $lists['required_controls'],
            'prohibited_actions' => $lists['prohibited_actions'],
            'escalation_triggers' => $lists['escalation_triggers'],
            'inputs' => $lists['inputs'],
            'outputs' => $lists['outputs'],
        ];
    }

    /**
     * GET /competency/task-instructions/{taskId}/download
     *
     * The same procedure as a file, so somebody can print it or carry it to a
     * machine that is not this app. Same ownership rule as show().
     */
    public function download(Request $request, int $taskId)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid  = (int) $context['sub_institute_id'];
        $user = $context['user_id'] !== null ? (int) $context['user_id'] : 0;

        $task = DB::table('task')->where('id', $taskId)->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first(['id', 'jobrole_task_id', 'task_allocated_to', 'task_allocated']);

        if (!$task) {
            return response()->json(['status' => 0, 'message' => 'That task does not exist in your organisation.'], 404);
        }

        $isMine = (int) $task->task_allocated_to === $user || (int) $task->task_allocated === $user;
        if (!$isMine && !$this->isElevated($user)) {
            return response()->json(['status' => 0, 'message' => 'This task is not assigned to you.'], 403);
        }

        $eso = $task->jobrole_task_id
            ? DB::table('eso')->where('sub_institute_id', $sid)
                ->where('user_jobrole_task_id', $task->jobrole_task_id)
                ->whereNull('deleted_at')
                ->orderByRaw("FIELD(status,'Published','Reviewed','Draft','Retired')")
                ->first()
            : null;

        if (!$eso) {
            return response()->json([
                'status' => 0, 'reason' => 'no_eso',
                'message' => 'There is no written procedure for this task to download.',
            ], 404);
        }

        $exporter = app(EsoExporter::class);
        $format = strtolower((string) $request->input('format', 'pdf'));

        if ($format === 'md') {
            return response($exporter->toMarkdown($eso, $exporter->taskFor($eso)), 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $exporter->filename($eso, 'md') . '"',
            ]);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'competency.eso-pdf',
            $exporter->viewData($eso, $exporter->taskFor($eso))
        )->setPaper('a4', 'portrait');

        $output = $pdf->output();
        $headerAt = strpos($output, '%PDF');
        if ($headerAt !== false && $headerAt > 0) {
            $output = substr($output, $headerAt);
        }

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $exporter->filename($eso, 'pdf') . '"',
            'Content-Length' => (string) strlen($output),
        ]);
    }
}
