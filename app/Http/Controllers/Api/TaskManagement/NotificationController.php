<?php

namespace App\Http\Controllers\Api\TaskManagement;

use App\Http\Controllers\Api\TaskManagement\Concerns\ResolvesTaskContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The user's task notifications. Rows are written by the task write paths
 * (assignment, approval decisions); this controller only reads and marks.
 */
class NotificationController extends Controller
{
    use ResolvesTaskContext;

    public function index(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('task_management_notifications')
            ->where('user_id', $context['user_id'])
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'title' => (string) $row->title,
                'body' => $row->body,
                'task_id' => $row->task_id ? (string) $row->task_id : null,
                'read' => $row->read_at !== null,
                'created_at' => $row->created_at,
            ]);

        return $this->ok('Notifications retrieved successfully.', [
            'notifications' => $rows->all(),
            'unread' => $rows->where('read', false)->count(),
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $updated = DB::table('task_management_notifications')
            ->where('id', $id)
            ->where('user_id', $context['user_id'])
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $updated
            ? $this->ok('Notification marked as read.')
            : $this->fail('Notification not found or already read.', 404);
    }

    public function markAllRead(Request $request)
    {
        $context = $this->taskContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $count = DB::table('task_management_notifications')
            ->where('user_id', $context['user_id'])
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->ok("Marked {$count} notifications as read.");
    }

    /**
     * Insert helper the write paths call. Static so LegacyTaskController and
     * WorkspaceController can notify without holding a controller instance.
     */
    public static function notify(int $sid, int $userId, string $type, string $title, ?string $body = null, ?int $taskId = null): void
    {
        if ($userId <= 0) {
            return;
        }

        DB::table('task_management_notifications')->insert([
            'sub_institute_id' => $sid,
            'user_id' => $userId,
            'task_id' => $taskId,
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'created_at' => now(),
        ]);
    }
}
