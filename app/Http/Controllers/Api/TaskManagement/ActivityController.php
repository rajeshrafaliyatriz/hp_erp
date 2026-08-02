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

        $events = DB::table('task_management_audit_logs as a')
            ->leftJoin('tbluser as actor', 'actor.id', '=', 'a.actor_id')
            ->where('a.task_id', $id)
            ->where('a.sub_institute_id', $sid)
            ->selectRaw("a.id, a.event as type, NULL as content, a.created_at,
                TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) as actor_name")
            ->get();

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
