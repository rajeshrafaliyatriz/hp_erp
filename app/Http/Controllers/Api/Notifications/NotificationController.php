<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * X-06 — the in-app inbox.
 *
 * NOT THE SAME AS Api\TaskManagement\NotificationController. That one reads
 * `task_management_notifications` and only ever holds task events; this one reads
 * `g2g_notification`, which holds every event-sourced notification in the product.
 * Both are left in place - the task table has 6 rows and a working API, and the
 * no-deletion rule applies to it as much as to anything else.
 *
 * THE RECIPIENT IS ALWAYS THE TOKEN OWNER. There is no `user_id` parameter, not
 * even for an admin: an inbox belongs to exactly one person, and a route that
 * would let you read somebody else's is a read leak with a friendly name. If an
 * admin view of all notifications is ever wanted, that is a different endpoint
 * with its own permission.
 */
class NotificationController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 200);

        $rows = DB::table('g2g_notification')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('user_id', $identity['user_id'])
            ->where('channel', 'inapp')
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id', 'event_type', 'subject', 'body', 'action_url',
                'recipient_reason', 'read_at', 'created_at',
            ]);

        return response()->json([
            'status'       => 1,
            'notifications' => $rows,
            'unread'       => $this->unreadCountFor($identity),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'unread' => $this->unreadCountFor($identity)]);
    }

    public function markRead(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        // Tenant AND user in the WHERE, not just the id. An id is unique to the
        // table, not to a person - route middleware answers who may call this, it
        // does not answer which row comes back.
        $updated = DB::table('g2g_notification')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('user_id', $identity['user_id'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status'  => 1,
            'updated' => $updated,
            'unread'  => $this->unreadCountFor($identity),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $updated = DB::table('g2g_notification')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('user_id', $identity['user_id'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 1, 'updated' => $updated, 'unread' => 0]);
    }

    private function unreadCountFor(array $identity): int
    {
        return DB::table('g2g_notification')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('user_id', $identity['user_id'])
            ->where('channel', 'inapp')
            ->whereNull('read_at')
            ->count();
    }
}
