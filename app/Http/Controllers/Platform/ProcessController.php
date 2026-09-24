<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\PlatformRegistry;
use App\Services\Platform\ProcedureParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Add Process — a written procedure, turned into tasks somebody can do.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE PART THAT MAKES THIS WORTH HAVING IS `publish`
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Converting a procedure into structure is pleasant and, on its own, produces a
 * document. What changes anything is that the derived tasks become REAL ROWS in
 * task management, assigned to real people, appearing in their queues.
 *
 * LMS K-12 gets that half right — its publish creates genuine tasks with an
 * idempotency key — and the storage half wrong: the converted procedure goes to
 * `requirement_gathering` with `sub_institute_id` hardcoded to 0, so every school
 * overwrites the same rows, and publishing records nothing, so re-opening a
 * process cannot say whether its tasks exist.
 *
 * `g2g_process_task` is the table that fixes the second half. Because it records
 * what a publish created, a second publish raises only what is missing rather
 * than a duplicate set — and the screen can say "published, 4 tasks" instead of
 * offering the button again as though nothing had happened.
 */
class ProcessController extends PlatformController
{
    private const TABLE = 'g2g_process';
    private const TASKS = 'g2g_process_task';

    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly ProcedureParser $parser,
    ) {
    }

    /**
     * Read a procedure into structure WITHOUT storing anything.
     *
     * Separate from `store` on purpose: somebody pasting a procedure wants to see
     * what was understood before committing to it, and a convert that saved would
     * leave a trail of abandoned drafts from people who were only looking.
     */
    public function convert(Request $request): JsonResponse
    {
        try {
            $this->scope($request);

            $text = (string) $request->input('source_text', '');

            if (trim($text) === '') {
                return $this->failure('Paste the procedure first.', 422, [
                    'source_text' => ['Required.'],
                ]);
            }

            return $this->success('Converted.', [
                'spec' => $this->parser->parse($text, (string) $request->input('name', 'Untitled procedure')),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** This organisation's processes, newest first. */
    public function index(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $rows = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->orderByDesc('id')
                ->limit($this->limit($request, 50, 200))
                ->get();

            $published = DB::table(self::TASKS)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->select('process_id', DB::raw('count(*) as total'))
                ->groupBy('process_id')
                ->pluck('total', 'process_id');

            return $this->success('Processes.', [
                'rows' => $rows->map(fn ($row) => $this->present($row, (int) ($published[$row->id] ?? 0)))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $row = $this->own($scope->selectedInstituteId, $id);

            if ($row === null) {
                return $this->failure('That process no longer exists.', 404);
            }

            $tasks = DB::table(self::TASKS)
                ->where('process_id', $id)
                ->orderBy('id')
                ->get(['task_id', 'task_ref', 'title', 'assignee_id', 'created_at']);

            return $this->success('Process.', [
                'process' => $this->present($row, $tasks->count()),
                // What the publish actually created, so the screen can link to the
                // tasks rather than claim they exist.
                'tasks' => $tasks->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $module = (string) $request->input('module', '');

            if (! array_key_exists($module, $this->registry->modules())) {
                return $this->failure('That is not a module this platform declares.', 422, [
                    'module' => ['Unknown module.'],
                ]);
            }

            $text = (string) $request->input('source_text', '');

            if (trim($text) === '') {
                return $this->failure('A process needs its procedure text.', 422, [
                    'source_text' => ['Required.'],
                ]);
            }

            $spec = $this->parser->parse($text, (string) $request->input('name', 'Untitled procedure'));
            $name = trim((string) $request->input('name', '')) ?: $spec['name'];
            $now = now();

            $id = DB::table(self::TABLE)->insertGetId([
                'sub_institute_id' => $scope->selectedInstituteId,
                'name' => mb_substr($name, 0, 191),
                'module' => $module,
                // Kept verbatim so the derivation can be re-run — see the migration.
                'source_text' => $text,
                'spec' => json_encode($spec),
                'status' => 'draft',
                'created_by' => $this->actor($scope),
                'updated_by' => $this->actor($scope),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->success('Process saved.', ['id' => $id, 'spec' => $spec], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $row = $this->own($scope->selectedInstituteId, $id);

            if ($row === null) {
                return $this->failure('That process no longer exists.', 404);
            }

            $values = ['updated_by' => $this->actor($scope), 'updated_at' => now()];

            if ($request->has('name')) {
                $values['name'] = mb_substr(trim((string) $request->input('name')), 0, 191) ?: $row->name;
            }

            /*
             * Re-parsing on an edit is the point of keeping the source text.
             *
             * The spec is derived; editing the procedure and keeping the old
             * derivation would leave the two disagreeing, with the screen showing
             * steps the text no longer contains.
             */
            if ($request->has('source_text')) {
                $text = (string) $request->input('source_text');
                $values['source_text'] = $text;
                $values['spec'] = json_encode($this->parser->parse($text, $row->name));
            }

            DB::table(self::TABLE)->where('id', $id)->update($values);

            return $this->success('Process updated.', ['id' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $deleted = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('id', $id)
                ->delete();

            if ($deleted === 0) {
                return $this->failure('That process no longer exists.', 404);
            }

            /*
             * The record of what was published is deleted with it; the TASKS are
             * not. Those are real work in somebody's queue, and deleting the
             * document they came from must not silently remove them — a task
             * nobody can now explain is better than work that vanishes.
             */
            DB::table(self::TASKS)->where('process_id', $id)->delete();

            return $this->success('Process deleted.', ['deleted' => $id]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Raise the derived tasks as real task-management rows.
     *
     * ── WHY THIS CANNOT DOUBLE-RAISE ────────────────────────────────────────
     *
     * Two independent guards, because this is the one action here that creates
     * work in other people's queues:
     *
     *   `g2g_process_task` has a unique key on (process_id, task_ref), so a
     *   second publish skips anything already recorded;
     *
     *   each task carries an idempotency key derived from the process and the
     *   step, so even a retry that gets past the first guard — a timeout where
     *   the row was written and the response lost — replays rather than
     *   duplicates.
     *
     * The assignee comes from the request rather than from the procedure's actor
     * text: "(HR)" names a role in prose, and turning prose into a person is a
     * guess with somebody's workload attached. The screen asks.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $row = $this->own($scope->selectedInstituteId, $id);

            if ($row === null) {
                return $this->failure('That process no longer exists.', 404);
            }

            $assignments = $request->input('assignments');

            if (! is_array($assignments) || $assignments === []) {
                return $this->failure(
                    'Say who each task is for before publishing.',
                    422,
                    ['assignments' => ['Expected a map of task ref to user id.']]
                );
            }

            $spec = json_decode((string) $row->spec, true) ?: [];
            $derived = collect($spec['tasks'] ?? [])->keyBy('ref');

            $already = DB::table(self::TASKS)->where('process_id', $id)->pluck('task_ref')->all();

            $created = [];
            $skipped = [];
            $problems = [];

            foreach ($assignments as $ref => $assigneeId) {
                $ref = (string) $ref;
                $assigneeId = (int) $assigneeId;

                if (! $derived->has($ref)) {
                    $problems[] = "\"{$ref}\" is not a task this process derives.";
                    continue;
                }

                if (in_array($ref, $already, true)) {
                    $skipped[] = $ref;
                    continue;
                }

                if ($assigneeId <= 0) {
                    $problems[] = "\"{$ref}\" has nobody assigned.";
                    continue;
                }

                // The assignee must be in this organisation. Without this a publish
                // could put work in another tenant's queue.
                $inTenant = DB::table('tbluser')
                    ->where('id', $assigneeId)
                    ->where('sub_institute_id', $scope->selectedInstituteId)
                    ->where('status', 1)
                    ->exists();

                if (! $inTenant) {
                    $problems[] = "\"{$ref}\" names somebody who is not an active member of this organisation.";
                    continue;
                }

                $task = $derived->get($ref);
                $key = sprintf('process-%d-%s', $id, $ref);

                $taskId = $this->raiseTask($task, $assigneeId, $scope, $key, (string) $row->name);

                if ($taskId === null) {
                    $problems[] = "\"{$ref}\" could not be raised.";
                    continue;
                }

                DB::table(self::TASKS)->insert([
                    'process_id' => $id,
                    'sub_institute_id' => $scope->selectedInstituteId,
                    'task_id' => $taskId,
                    'task_ref' => $ref,
                    'title' => mb_substr((string) $task['title'], 0, 255),
                    'assignee_id' => $assigneeId,
                    'idempotency_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created[] = ['ref' => $ref, 'task_id' => $taskId];
            }

            if ($created !== []) {
                DB::table(self::TABLE)->where('id', $id)->update([
                    'status' => 'published',
                    'updated_at' => now(),
                ]);
            }

            return $this->success('Published.', [
                'created' => $created,
                // Named rather than counted: "3 skipped" leaves somebody wondering
                // which, and whether they need to do something about them.
                'already_published' => $skipped,
                'problems' => $problems,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * One derived task as a real task row.
     *
     * Writes through the same tables `LegacyTaskController` uses rather than
     * calling it: that controller resolves its context from request input, and
     * this one already holds a verified scope. Re-entering through it would mean
     * forging a request to satisfy a check that has already passed.
     */
    private function raiseTask(array $task, int $assigneeId, $scope, string $idempotencyKey, string $processName): ?int
    {
        return DB::transaction(function () use ($task, $assigneeId, $scope, $idempotencyKey, $processName) {
            $existing = DB::table('task_management_idempotency_keys')
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('idempotency_key', $idempotencyKey)
                ->value('task_id');

            if ($existing) {
                return (int) $existing;
            }

            $due = now()->addDays(max(1, (int) ($task['due_in_days'] ?? 7)));

            /*
             * Column names and defaults copied from `LegacyTaskController::payload()`
             * and its insert, so a task raised here is indistinguishable from one
             * raised by the task screen — same `status` spelling ('PENDING', which
             * is upper-case there and matters to the status filters), same
             * `task_allocated` meaning the owner, same `SYEAR`.
             *
             * Getting these wrong would produce rows that exist and never appear in
             * anybody's list, which is the worst outcome available here: the publish
             * reports success and the work is invisible.
             */
            $taskId = DB::table('task')->insertGetId([
                'task_title' => mb_substr((string) $task['title'], 0, 255),
                'task_description' => 'Raised from the process "' . mb_substr($processName, 0, 150) . '".',
                'task_date' => $due->toDateString(),
                'task_type' => 'Medium',
                'task_allocated_to' => $assigneeId,
                // The person publishing owns it — they are who to ask about it.
                'task_allocated' => $scope->userId,
                'status' => 'PENDING',
                'sub_institute_id' => $scope->selectedInstituteId,
                'SYEAR' => $scope->syear ?? now()->year,
                'created_by' => $scope->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('task_management_idempotency_keys')->insert([
                'sub_institute_id' => $scope->selectedInstituteId,
                'idempotency_key' => $idempotencyKey,
                'task_id' => $taskId,
                // No `updated_at` on this table — it records that a key was used,
                // which happens once and is never revised.
                'created_at' => now(),
            ]);

            return (int) $taskId;
        });
    }

    private function own(int $tenantId, int $id): ?object
    {
        return DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->where('id', $id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(object $row, int $publishedCount): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'module' => $row->module,
            'status' => $row->status,
            'source_text' => $row->source_text,
            'spec' => json_decode((string) $row->spec, true) ?: [],
            // What it has actually raised. The number K-12 forgets.
            'published_tasks' => $publishedCount,
            'created_at' => $row->created_at,
            'created_by' => $row->created_by,
            'updated_at' => $row->updated_at,
            'updated_by' => $row->updated_by,
        ];
    }

    private function actor(\App\Services\Ai\AiRequestScope $scope): string
    {
        $name = DB::table('tbluser')->where('id', $scope->userId)->value('first_name');

        return trim(((string) $name) . ' (' . $scope->userId . ')');
    }
}
