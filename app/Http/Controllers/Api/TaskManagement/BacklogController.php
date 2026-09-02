<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The backlog: work written down before it has an owner.
 *
 * ── ONE REQUIRED FIELD ──────────────────────────────────────────────────────
 *
 * A backlog item exists to let somebody write "post on social media" without
 * first answering who, when, which job role, or under which project. So `title`
 * is the only thing required. Everything else — notes, type, priority, the
 * project, the workstream — is optional and can arrive later, which is the
 * whole point of a backlog as opposed to a task.
 *
 * ── TENANCY IS ENFORCED HERE, NOT INHERITED ─────────────────────────────────
 *
 * The workstream child tables carry no tenant column because they can always
 * join up to a project. This table cannot: `project_id` is nullable, so an item
 * captured on the task dashboard has no parent at all. It therefore carries
 * `sub_institute_id` + `syear` itself, and `scope()` below is the ONLY thing
 * standing between a guessed id and another organisation's notes.
 *
 * It returns null → 404, never 403. A 403 confirms the row exists, which is
 * itself a disclosure — the same rule ResolvesWorkstreamScope follows.
 */
class BacklogController extends Controller
{
    use ResolvesTaskContext;

    /**
     * Domain-neutral on purpose.
     *
     * Story / Epic / Bug are software words. This module is used by property
     * and clinical teams too, and a vocabulary half the tenants cannot read is
     * worse than no vocabulary. Each of these completes "this is…": something
     * new · something broken · something that could be better · getting
     * something started · something that recurs · something somebody asked
     * for. The customer's own examples land cleanly: "onboarding creation" is
     * SETUP, "post on social media" is ROUTINE, "bug fix in this module" is
     * FIX, "create new feature" is NEW.
     *
     * REQUEST stays in the set because it is the column's default, so the
     * vocabulary can grow without an ALTER on live.
     */
    public const TYPES = ['NEW', 'FIX', 'IMPROVE', 'SETUP', 'ROUTINE', 'REQUEST'];

    /** The existing task vocabulary, reused rather than reinvented. */
    public const PRIORITIES = ['High', 'Medium', 'Low'];

    public const STATUSES = ['OPEN', 'ASSIGNED', 'DONE', 'DROPPED'];

    /** Seeded and re-spaced in steps of this, so a drop writes ONE row. */
    private const RANK_STEP = 1000;

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $query = $this->tenantQuery($context);

        /*
         * An ABSENT project_id means "everything, including unfiled" — the task
         * dashboard's view. `project_id=none` means only the unfiled ones. A
         * numeric id filters to that project. Three different questions, so
         * three different answers rather than one overloaded parameter.
         */
        $project = $request->input('project_id');
        if ($project === 'none') {
            $query->whereNull('b.project_id');
        } elseif ($project !== null && $project !== '') {
            $query->where('b.project_id', (int) $project);
        }

        if ($request->filled('status')) {
            $query->where('b.status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('b.type', $request->input('type'));
        }

        $rows = $query->orderBy('b.rank')->orderBy('b.id')->get();

        return $this->ok('Backlog retrieved successfully.', [
            'items'   => $rows->map(fn ($row) => $this->resource($row))->all(),
            'options' => [
                'types'      => self::TYPES,
                'priorities' => self::PRIORITIES,
                'statuses'   => self::STATUSES,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }

        $validator = $this->validator($request);
        if ($validator->fails()) {
            return $this->fail('Please check the highlighted fields.', 422, $validator->errors()->toArray());
        }

        $error = $this->rejectForeignParents($context, $request);
        if ($error) {
            return $error;
        }

        // New items go to the top: a backlog is read from the top down, and the
        // thing somebody just thought of is the thing they are thinking about.
        $lowest = $this->tenantQuery($context)->min('b.rank');
        $rank   = ($lowest === null ? 0 : (int) $lowest) - self::RANK_STEP;

        $id = DB::table('task_management_backlog_items')->insertGetId(
            $this->payload($request) + [
                'sub_institute_id' => $context['sub_institute_id'],
                'syear'            => $context['syear'],
                'rank'             => $rank,
                'status'           => 'OPEN',
                'created_by'       => $context['user_id'],
                'created_at'       => now(),
                'updated_at'       => now(),
            ]
        );

        return $this->ok('Added to the backlog.', ['id' => (string) $id], 201);
    }

    public function update(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }
        if (! $this->scope($context, $id)) {
            return $this->fail('Backlog item not found.', 404);
        }

        $validator = $this->validator($request);
        if ($validator->fails()) {
            return $this->fail('Please check the highlighted fields.', 422, $validator->errors()->toArray());
        }

        $error = $this->rejectForeignParents($context, $request);
        if ($error) {
            return $error;
        }

        $payload = $this->payload($request);
        if ($request->filled('status')) {
            $payload['status'] = $request->input('status');
        }

        DB::table('task_management_backlog_items')->where('id', $id)->update(
            $payload + ['updated_by' => $context['user_id'], 'updated_at' => now()]
        );

        return $this->ok('Backlog item updated.');
    }

    public function destroy(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }
        if (! $this->scope($context, $id)) {
            return $this->fail('Backlog item not found.', 404);
        }

        // Soft delete: a dropped idea someone spent thought on should be
        // recoverable, and nothing here is expensive to keep.
        DB::table('task_management_backlog_items')->where('id', $id)->update([
            'deleted_at' => now(), 'updated_by' => $context['user_id'], 'updated_at' => now(),
        ]);

        return $this->ok('Backlog item removed.');
    }

    /**
     * Move one item, writing ONE row.
     *
     * The client sends the ids either side of the drop. The new rank is the
     * midpoint between them, which is why `rank` is seeded in steps of 1000 —
     * a dense 1..n order would have to renumber every row between the source
     * and the target on every drag.
     *
     * When the gap between two neighbours closes to nothing (about ten drops
     * into the same slot), the list is re-spaced once and the move retried.
     */
    public function rank(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }
        if (! $this->scope($context, $id)) {
            return $this->fail('Backlog item not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'before_id' => 'nullable|integer|min:1',
            'after_id'  => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->fail('Please check the highlighted fields.', 422, $validator->errors()->toArray());
        }

        $rank = $this->rankBetween($context, $request->input('before_id'), $request->input('after_id'));

        if ($rank === null) {
            $this->respace($context);
            $rank = $this->rankBetween($context, $request->input('before_id'), $request->input('after_id'));
        }

        DB::table('task_management_backlog_items')->where('id', $id)->update([
            'rank' => $rank ?? 0, 'updated_by' => $context['user_id'], 'updated_at' => now(),
        ]);

        return $this->ok('Backlog reordered.');
    }

    /**
     * Record which task this item became.
     *
     * Called after the assign drawer creates the task. The legacy create
     * endpoint already returns `task_id` — it exists specifically because the
     * caller otherwise "has no handle on what it just created" — so the client
     * has a real id to send rather than a guess.
     */
    public function assign(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (! is_array($context)) {
            return $context;
        }
        if (! $this->scope($context, $id)) {
            return $this->fail('Backlog item not found.', 404);
        }

        $validator = Validator::make($request->all(), ['task_id' => 'required|integer|min:1']);
        if ($validator->fails()) {
            return $this->fail('Please check the highlighted fields.', 422, $validator->errors()->toArray());
        }

        // The task must be this tenant's. Without this an item could be made to
        // point at another organisation's task and display its id.
        $taskId = (int) $request->input('task_id');
        $exists = DB::table('task')->where('id', $taskId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')->exists();

        if (! $exists) {
            return $this->fail('That task could not be found.', 422);
        }

        DB::table('task_management_backlog_items')->where('id', $id)->update([
            'task_id' => $taskId, 'status' => 'ASSIGNED',
            'updated_by' => $context['user_id'], 'updated_at' => now(),
        ]);

        return $this->ok('Backlog item assigned.');
    }

    /* ------------------------------------------------------------------ */

    private function tenantQuery(array $context)
    {
        return DB::table('task_management_backlog_items as b')
            ->leftJoin('task_management_projects as p', 'p.id', '=', 'b.project_id')
            ->leftJoin('task_management_workstreams as w', 'w.id', '=', 'b.workstream_id')
            ->leftJoin('task as t', 't.id', '=', 'b.task_id')
            ->where('b.sub_institute_id', $context['sub_institute_id'])
            ->where('b.syear', $context['syear'])
            ->whereNull('b.deleted_at')
            ->select(
                'b.*',
                'p.name as project_name',
                'p.code as project_code',
                'w.name as workstream_name',
                't.task_title as task_title',
                't.status as task_status'
            );
    }

    /** null → 404. Never 403, which would confirm the row exists. */
    private function scope(array $context, int $id): ?object
    {
        return DB::table('task_management_backlog_items')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->where('syear', $context['syear'])
            ->whereNull('deleted_at')
            ->first();
    }

    private function validator(Request $request)
    {
        return Validator::make($request->all(), [
            'title'         => 'required|string|max:255',
            'notes'         => 'nullable|string|max:10000',
            'type'          => ['nullable', Rule::in(self::TYPES)],
            'priority'      => ['nullable', Rule::in(self::PRIORITIES)],
            'status'        => ['nullable', Rule::in(self::STATUSES)],
            'project_id'    => 'nullable|integer|min:1',
            'workstream_id' => 'nullable|integer|min:1',
        ]);
    }

    /**
     * A project or workstream from another tenant is refused, not silently
     * accepted — filing a note under somebody else's project would render it
     * under their name.
     */
    private function rejectForeignParents(array $context, Request $request)
    {
        $projectId = $request->input('project_id');
        if ($projectId) {
            $ok = DB::table('task_management_projects')->where('id', (int) $projectId)
                ->where('sub_institute_id', $context['sub_institute_id'])
                ->where('syear', $context['syear'])->exists();
            if (! $ok) {
                return $this->fail('That project could not be found.', 422);
            }
        }

        $workstreamId = $request->input('workstream_id');
        if ($workstreamId) {
            $ok = DB::table('task_management_workstreams as w')
                ->join('task_management_projects as p', 'p.id', '=', 'w.project_id')
                ->where('w.id', (int) $workstreamId)
                ->where('p.sub_institute_id', $context['sub_institute_id'])
                ->where('p.syear', $context['syear'])->exists();
            if (! $ok) {
                return $this->fail('That workstream could not be found.', 422);
            }
        }

        return null;
    }

    private function payload(Request $request): array
    {
        $trim = function ($value) {
            if (! is_string($value)) {
                return $value;
            }
            // `=== ''`, not `?:` — in PHP the string "0" is falsy, and a note
            // titled "0" is a note somebody meant to write.
            return trim($value) === '' ? null : trim($value);
        };

        return [
            'title'         => $trim($request->input('title')),
            'notes'         => $trim($request->input('notes')),
            'type'          => $request->input('type') ?: 'REQUEST',
            'priority'      => $request->input('priority') ?: 'Medium',
            'project_id'    => $request->input('project_id') ?: null,
            'workstream_id' => $request->input('workstream_id') ?: null,
        ];
    }

    private function rankBetween(array $context, $beforeId, $afterId): ?int
    {
        $rankOf = function ($id) use ($context) {
            if (! $id) {
                return null;
            }
            $row = $this->scope($context, (int) $id);
            return $row ? (int) $row->rank : null;
        };

        $before = $rankOf($beforeId);   // the item it now sits ABOVE
        $after  = $rankOf($afterId);    // the item it now sits BELOW

        if ($before === null && $after === null) {
            return 0;
        }
        if ($after === null) {
            return $before - self::RANK_STEP;   // to the very top
        }
        if ($before === null) {
            return $after + self::RANK_STEP;    // to the very bottom
        }

        // No room left between the neighbours — the caller re-spaces and retries.
        if (abs($before - $after) < 2) {
            return null;
        }

        return (int) floor(($before + $after) / 2);
    }

    /** Re-space the whole list back to 1000-steps. Rare, and cheap when it happens. */
    private function respace(array $context): void
    {
        $ids = $this->tenantQuery($context)->orderBy('b.rank')->orderBy('b.id')->pluck('b.id');

        foreach ($ids as $index => $id) {
            DB::table('task_management_backlog_items')
                ->where('id', $id)
                ->update(['rank' => ($index + 1) * self::RANK_STEP]);
        }
    }

    private function resource(object $row): array
    {
        return [
            'id'              => (string) $row->id,
            'title'           => $row->title,
            'notes'           => $row->notes,
            'type'            => $row->type,
            'priority'        => $row->priority,
            'status'          => $row->status,
            'rank'            => (int) $row->rank,
            'project_id'      => $row->project_id ? (string) $row->project_id : null,
            'project_name'    => $row->project_name,
            'project_code'    => $row->project_code,
            'workstream_id'   => $row->workstream_id ? (string) $row->workstream_id : null,
            'workstream_name' => $row->workstream_name,
            'task_id'         => $row->task_id ? (string) $row->task_id : null,
            'task_title'      => $row->task_title,
            'task_status'     => $row->task_status,
            'created_at'      => $row->created_at,
        ];
    }
}
