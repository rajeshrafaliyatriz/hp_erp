<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One task's history: its audit events and comments merged into a single
 * chronological feed for the task drawer's activity panel.
 */
class ActivityController extends Controller
{
    use ResolvesTaskContext;

    public function index(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        $exists = DB::table('task')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->exists();

        if (!$exists) {
            return $this->fail('Task not found.', 404);
        }

        /*
         * THE EVENT STORE, NOT THE TABLE THAT STOPPED BEING WRITTEN.
         *
         * This read `task_management_audit_logs`. Amendment A1 made
         * TaskAuditService EMIT an event instead of inserting there, and nothing
         * has written that table since, so the History half of this feed was
         * always empty. `g2g_event` is the store those events land in. It is read
         * here rather than the g2g_audit_log projection built from it, because
         * the projection is filled by a scheduled job and lags until that has run.
         *
         * Event types are stored prefixed ('task.status_changed'); the prefix is
         * dropped below so the client sees the same slugs it always did.
         */
        $events = DB::table('g2g_event as a')
            ->leftJoin('tbluser as actor', 'actor.id', '=', 'a.actor_id')
            ->where('a.entity_type', 'task')
            ->where('a.entity_id', $id)
            ->where('a.sub_institute_id', $sid)
            ->selectRaw("a.id, a.type, NULL as content, a.occurred_at as created_at,
                TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) as actor_name")
            ->get()
            ->map(function ($row) {
                $row->type = str_starts_with((string) $row->type, 'task.') ? substr((string) $row->type, 5) : $row->type;

                return $row;
            });

        $comments = DB::table('task_management_comments as c')
            ->leftJoin('tbluser as author', 'author.id', '=', 'c.author_id')
            ->where('c.task_id', $id)
            ->where('c.sub_institute_id', $sid)
            ->selectRaw("c.id, 'comment' as type, c.content, c.created_at,
                TRIM(CONCAT_WS(' ', author.first_name, author.middle_name, author.last_name)) as actor_name")
            ->get();

        $feed = $events->concat($comments)
            ->sortByDesc('created_at')
            ->values()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'actor' => $row->actor_name ?: null,
                'content' => $row->content,
                'created_at' => $row->created_at,
            ]);

        return $this->ok('Task activity retrieved successfully.', ['activity' => $feed->all()]);
    }
}
