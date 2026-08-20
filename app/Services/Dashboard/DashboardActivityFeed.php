<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/**
 * THE RECENT ACTIVITY FEED.
 *
 * Merges the three module activity logs plus leave decisions. They happen to
 * share an identical column shape (actor_name, action, description,
 * subject_type, subject_id, subject_name, created_at), so the merge is cheap —
 * but it is still done in PHP rather than as a UNION, because leave decisions
 * come from a differently-shaped table and forcing all four into one SELECT
 * would mean padding columns that do not exist.
 *
 * DELIBERATELY NOT USED: g2g_audit_log. It is a projection rebuilt from
 * g2g_event by AuditLogProjector, so if the projector has not run it is empty —
 * and an empty feed that LOOKS like a working feed is worse than no feed. The
 * three activity logs are written synchronously by the modules themselves.
 *
 * $scopeUserId: null = the whole organisation, an id = that person's own
 * activity (the employee dashboard, later).
 */
class DashboardActivityFeed
{
    /** The three logs that share one shape. */
    private const LOGS = [
        's_competency_activity_log'      => 'Competency',
        's_performance_activity_log'     => 'Performance',
        'talent_onboarding_activity_log' => 'Onboarding',
    ];

    public function feed(int $sid, ?int $scopeUserId = null, int $limit = 8): array
    {
        $rows = [];

        foreach (self::LOGS as $table => $context) {
            $q = DB::table($table)
                ->where('sub_institute_id', $sid)
                ->orderByDesc('created_at')
                ->limit($limit);

            if ($scopeUserId !== null) {
                $q->where('user_id', $scopeUserId);
            }

            foreach ($q->get() as $r) {
                $rows[] = [
                    'id'      => $context . '-' . $r->id,
                    'type'    => strtolower($context),
                    'text'    => $this->describe($r),
                    'context' => $context,
                    'actor'   => $r->actor_name ?: null,
                    'at'      => $r->created_at,
                ];
            }
        }

        foreach ($this->leaveDecisions($sid, $scopeUserId, $limit) as $row) {
            $rows[] = $row;
        }

        // One ordering across all sources, then trim. Sorting before the trim
        // matters: taking N from each source first would let a chatty module
        // crowd out a more recent event from a quiet one.
        usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Prefer the module's own sentence. `description` is written for a human;
     * the action/subject fallback is assembled only when it is absent, so the
     * feed never shows a bare verb like "updated" with no object.
     */
    private function describe(object $r): string
    {
        if (!empty($r->description)) {
            return (string) $r->description;
        }

        $action  = trim((string) ($r->action ?? ''));
        $subject = trim((string) ($r->subject_name ?? $r->subject_type ?? ''));

        return trim($action . ' ' . $subject) ?: 'Activity recorded';
    }

    /** Leave requests that have been decided — approved or rejected. */
    private function leaveDecisions(int $sid, ?int $scopeUserId, int $limit): array
    {
        $q = DB::table('hrms_emp_leaves as l')
            ->leftJoin('tbluser as u', 'u.id', '=', 'l.user_id')
            ->where('l.sub_institute_id', $sid)
            ->whereNull('l.deleted_at')
            ->whereIn(DB::raw('LOWER(l.status)'), ['approved', 'rejected'])
            ->orderByDesc('l.updated_at')
            ->limit($limit)
            ->select('l.id', 'l.status', 'l.updated_at', 'l.from_date', 'l.to_date', 'u.first_name', 'u.last_name');

        if ($scopeUserId !== null) {
            $q->where('l.user_id', $scopeUserId);
        }

        $out = [];

        foreach ($q->get() as $r) {
            $who = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'An employee';

            $out[] = [
                'id'      => 'leave-' . $r->id,
                'type'    => 'leave',
                'text'    => sprintf('Leave %s for %s', strtolower((string) $r->status), $who),
                'context' => 'Leave',
                'actor'   => null,
                'at'      => $r->updated_at,
            ];
        }

        return $out;
    }
}
