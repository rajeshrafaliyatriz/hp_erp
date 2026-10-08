<?php

namespace App\Services\Attendance;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Write a corrected attendance day, and say what it used to be.
 *
 * ── WHY THIS IS A SERVICE AND NOT A SECOND COPY ─────────────────────────────
 *
 * This logic was `AttendanceRegularisationApiController::applyCorrection`, and
 * it is the only writer in the application that changes somebody else's
 * attendance correctly: tenant-scoped, update-or-insert, `timestamp_diff`
 * recomputed, before-image captured ahead of the write.
 *
 * HR now needs the same write from a different direction - a correction the
 * employee never asked for - and there were three ways to get it:
 *
 *   - copy the method. Then two writers drift, and the one that drifts is the
 *     one with no regularisation request attached to explain it.
 *   - have HR's screen file a regularisation and immediately approve it. That
 *     reuses everything, but it puts a request in the employee's own list that
 *     they never made, and "pending → approved in the same millisecond" is a
 *     lie about what happened.
 *   - lift it here, and let both callers share it. That is this.
 *
 * ── WHAT CALLERS MUST STILL DO THEMSELVES ───────────────────────────────────
 *
 * This writes the attendance row and returns the before/after. It does NOT
 * authorise, does not open a transaction, and does not record the audit event -
 * the two callers differ on all three, and burying them here would make the
 * permission check invisible at the point where the permission is decided.
 *
 * ── AN ATTENDANCE EDIT IS A PAY EDIT ────────────────────────────────────────
 *
 * `timestamp_diff` is read by payroll, and a corrected 2nd-Saturday punch-in
 * changes the late count that is subtracted from payable days. That is why the
 * before-image is mandatory rather than nice to have: an unrecorded correction
 * is an unexplained pay change six months later.
 */
class AttendanceCorrector
{
    /**
     * Apply a correction to one employee-day.
     *
     * @param  object  $row  needs user_id, sub_institute_id, day, and the
     *                       requested_in_time / requested_out_time to apply.
     *                       A null requested time means "leave that side alone",
     *                       which is how a half-correction works.
     * @param  int  $actorId  whoever is making the change - the approver on the
     *                        self-service path, the HR user on the admin one.
     *
     * @return array{before: ?array, after: array, attendance_id: int}
     */
    public function apply(object $row, int $actorId): array
    {
        $day = Carbon::parse($row->day)->toDateString();

        $existing = DB::table('hrms_attendances')
            ->where('user_id', $row->user_id)
            ->where('sub_institute_id', $row->sub_institute_id)
            ->whereNull('deleted_at')
            ->whereDate('day', $day)
            ->first();

        /*
         * Normalised to a full Y-m-d H:i:s rather than left as the caller sent
         * it. The requested times arrive as H:i, so `$day.' '.$t` is
         * "1999-01-11 09:15" - which MySQL silently widens to "09:15:00" on the
         * way into the DATETIME column, while the audit trail's plain string
         * column keeps the narrow form. The two then disagree about the same
         * change, and the before/after record stops being evidence of it.
         *
         * Carbon also absorbs the existing value, which comes back from the
         * driver already in the wide form, so both branches produce one shape.
         */
        $punchIn  = $this->stamp($row->requested_in_time ? $day . ' ' . $row->requested_in_time
                                                         : ($existing->punchin_time ?? null));

        $punchOut = $this->stamp($row->requested_out_time ? $day . ' ' . $row->requested_out_time
                                                          : ($existing->punchout_time ?? null));

        $update = [
            'punchin_time'   => $punchIn,
            'punchout_time'  => $punchOut,
            'timestamp_diff' => $this->duration($punchIn, $punchOut),
            'updated_at'     => now(),
            'updated_by'     => $actorId,
        ];

        /*
         * Captured BEFORE the write. Without it the old punch times are gone and
         * "what did this row say yesterday" has no answer.
         */
        $before = $existing
            ? [
                'attendance_id'  => (int) $existing->id,
                'punchin_time'   => $existing->punchin_time,
                'punchout_time'  => $existing->punchout_time,
                'timestamp_diff' => $existing->timestamp_diff,
            ]
            : null;   // no row existed - this correction CREATES the day

        if ($existing) {
            DB::table('hrms_attendances')->where('id', $existing->id)->update($update);

            return ['before' => $before, 'after' => $update, 'attendance_id' => (int) $existing->id];
        }

        /*
         * The created row carries the same marks a punch would leave.
         *
         * The self-service path did not set these, so a day created by an
         * approval was distinguishable from a day created by punching - in_note
         * and out_note stayed 0 where every real punch sets them to 1. A
         * corrected day should look like a day, not like a row somebody typed.
         */
        $newId = DB::table('hrms_attendances')->insertGetId(array_merge($update, [
            'user_id'          => $row->user_id,
            'sub_institute_id' => $row->sub_institute_id,
            'day'              => $day,
            'in_note'          => $punchIn ? 1 : 0,
            'out_note'         => $punchOut ? 1 : 0,
            'work_mode'        => $row->work_mode ?? 'office',
            'created_at'       => now(),
            'created_by'       => $actorId,
        ]));

        return ['before' => null, 'after' => $update, 'attendance_id' => (int) $newId];
    }

    /**
     * Worked time, as the TIME column wants it.
     *
     * Null when either side is missing or the out is not after the in - an
     * overnight shift is not supported here, and inventing a negative or a
     * wrapped duration would feed payroll a number nobody can defend.
     *
     * Emitted as HH:MM:SS. The punch-out path writes HH:MM into the same column
     * and the two have disagreed for as long as both have existed; this is the
     * form the column is declared as.
     */
    /**
     * One datetime shape for both tables.
     *
     * Returns null for null so "there was no punch" stays distinguishable from
     * "the punch was at midnight" - that distinction is the whole reason the
     * audit columns are nullable strings rather than TIMEs.
     */
    private function stamp(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null;
    }

    public function duration(?string $punchIn, ?string $punchOut): ?string
    {
        if (!$punchIn || !$punchOut) {
            return null;
        }

        $in  = Carbon::parse($punchIn);
        $out = Carbon::parse($punchOut);

        if ($out->lessThanOrEqualTo($in)) {
            return null;
        }

        $minutes = $in->diffInMinutes($out);

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
