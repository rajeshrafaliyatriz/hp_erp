<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Training sessions and the session calendar.
 *
 * Sessions live in lms_virtual_classroom - the only table carrying event_date
 * plus from_time / to_time / url, which is what a scheduled slot needs.
 * lms/virtual_classroom_master already exposes it, but that is a Blade route
 * behind the session middleware and CSRF-blocked cross-origin, so this adds the
 * token-authenticated equivalent the Next.js page can use.
 *
 * Seats and attendees come from lms_session_registrations (new); calendar
 * deadline markers stay in calendar_events, which the LMS dashboard already
 * reads - the two are deliberately separate entities.
 */
class LmsSessionController extends Controller
{
    use ResolvesLmsIdentity;

    /** Profiles allowed to schedule sessions and manage attendees. */
    private const ADMIN_PROFILES = ['admin', 'hr'];

    private function guardApiToken(Request $request)
    {
        // Was: `if ($request->input('type') !== 'API') return null;` followed by
        // a token check that discarded the token's owner. Omitting `type`
        // skipped authentication entirely. Identity now always comes from the
        // token - see ResolvesLmsIdentity.
        return $this->guardLmsToken($request);
    }

    private function guardAdmin(Request $request)
    {
        // The profile now comes from the caller's tbluser row, not from
        // a `user_profile_name` they supplied themselves.
        return $this->guardLmsProfile($request, self::ADMIN_PROFILES, 'Your profile is not permitted to manage sessions.');
    }

    private function tenantId(Request $request)
    {
        // The caller's own organisation, from their token - not from whatever
        // sub_institute_id the request asked for.
        return $this->lmsTenantId($request);
    }

    /** Seats consumed per session - only live registrations count. */
    private function registrationCounts()
    {
        return DB::table('lms_session_registrations')
            ->whereNull('deleted_at')
            ->whereIn('status', ['registered', 'attended'])
            ->select('session_id', DB::raw('COUNT(*) as registered_count'))
            ->groupBy('session_id');
    }

    /**
     * Open / Almost-full / Full is derived, never stored, so it can never drift
     * from the actual registration count.
     */
    private function decorate($session, $userId = null): object
    {
        $registered = (int) ($session->registered_count ?? 0);
        $total = $session->seats_total !== null ? (int) $session->seats_total : null;

        $session->registered_count = $registered;
        $session->seats_available = $total !== null ? max(0, $total - $registered) : null;

        if ($total === null || $total === 0) {
            $session->seat_status = 'open';
        } elseif ($registered >= $total) {
            $session->seat_status = 'full';
        } elseif ($registered / $total >= 0.8) {
            $session->seat_status = 'almost-full';
        } else {
            $session->seat_status = 'open';
        }

        // A past session cannot be joined regardless of seats.
        $session->is_past = $session->event_date !== null && $session->event_date < now()->toDateString();
        if ($session->is_past) {
            $session->seat_status = 'closed';
        }

        $session->is_registered = $userId !== null && !empty($session->my_registration_status)
            && $session->my_registration_status !== 'cancelled';

        return $session;
    }

    /**
     * GET /api/lms/sessions
     *
     * Sessions in a date window, with seat counts and the caller's own
     * registration state. `from`/`to` drive the calendar grid; without them it
     * returns the current month.
     */
    public function index(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        $userId = $this->contextUserId($request);

        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
            $to = $request->input('to') ?: now()->endOfMonth()->toDateString();

            $query = DB::table('lms_virtual_classroom as v')
                ->leftJoinSub($this->registrationCounts(), 'r', fn ($join) => $join->on('r.session_id', '=', 'v.id'))
                ->leftJoin('lms_session_registrations as mine', function ($join) use ($userId) {
                    $join->on('mine.session_id', '=', 'v.id')
                         ->where('mine.user_id', '=', DB::raw((int) $userId))
                         ->whereNull('mine.deleted_at');
                })
                ->where('v.sub_institute_id', $subInstituteId)
                ->whereNull('v.deleted_at')
                ->whereBetween('v.event_date', [$from, $to]);

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('v.room_name', 'like', "%{$search}%")
                      ->orWhere('v.trainer_name', 'like', "%{$search}%")
                      ->orWhere('v.venue', 'like', "%{$search}%");
                });
            }

            if ($type = $request->input('session_type')) {
                $query->where('v.session_type', $type);
            }

            $sessions = $query
                ->orderBy('v.event_date')
                ->orderBy('v.from_time')
                ->get([
                    'v.id', 'v.room_name', 'v.session_type', 'v.description', 'v.notes',
                    'v.trainer_name', 'v.trainer_email', 'v.venue', 'v.seats_total',
                    'v.event_date', 'v.from_time', 'v.to_time', 'v.url', 'v.status',
                    'v.subject_id', 'v.standard_id',
                    DB::raw('COALESCE(r.registered_count, 0) as registered_count'),
                    'mine.status as my_registration_status',
                ])
                ->map(fn ($session) => $this->decorate($session, $userId));

            return response()->json([
                'status' => true,
                'data' => $sessions,
                'meta' => ['from' => $from, 'to' => $to],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load sessions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/sessions/stats
     *
     * The KPI row. Every figure is derived - none of these were stored.
     */
    public function stats(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        try {
            $today = now()->toDateString();
            $in30Days = now()->addDays(30)->toDateString();

            $base = fn () => DB::table('lms_virtual_classroom')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at');

            $upcoming = $base()->whereBetween('event_date', [$today, $in30Days])->count();
            $thisMonth = $base()
                ->whereBetween('event_date', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ])
                ->count();

            $totalRegistrations = DB::table('lms_session_registrations')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereIn('status', ['registered', 'attended'])
                ->count();

            // Open vs full needs the per-session count, so it is computed from
            // the upcoming set rather than with a second aggregate query.
            $upcomingSessions = DB::table('lms_virtual_classroom as v')
                ->leftJoinSub($this->registrationCounts(), 'r', fn ($join) => $join->on('r.session_id', '=', 'v.id'))
                ->where('v.sub_institute_id', $subInstituteId)
                ->whereNull('v.deleted_at')
                ->where('v.event_date', '>=', $today)
                ->get(['v.seats_total', DB::raw('COALESCE(r.registered_count, 0) as registered_count')]);

            $full = $upcomingSessions
                ->filter(fn ($s) => $s->seats_total !== null && $s->registered_count >= $s->seats_total)
                ->count();

            return response()->json([
                'status' => true,
                'data' => [
                    'upcoming_sessions' => $upcoming,
                    'total_registrations' => $totalRegistrations,
                    'open_sessions' => $upcomingSessions->count() - $full,
                    'full_sessions' => $full,
                    'sessions_this_month' => $thisMonth,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load session stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/lms/sessions/deadlines - dated markers to overlay on the calendar.
     *
     * Two sources, because neither is complete on its own:
     *  - lms_course_enroll.end_date is the real, populated deadline for a
     *    learner's assigned course.
     *  - calendar_events is the org-wide events table the dashboard already
     *    reads. It is currently empty, but it is the intended home for
     *    non-course markers, so it is unioned in rather than ignored.
     *
     * Deadlines are whole-day markers - neither source carries a time - so the
     * grid renders them in the day header rather than in an hour slot.
     */
    public function deadlines(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        $userId = (int) ($this->contextUserId($request));

        if (!$subInstituteId) {
            return response()->json(['status' => false, 'message' => 'sub_institute_id is required'], 422);
        }

        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->endOfMonth()->toDateString();

        try {
            // guardAdmin returns null when the caller is allowed, a response otherwise.
            // Admin and HR see every learner's deadline; a learner sees only theirs.
            $isAdmin = $this->guardAdmin($request) === null;

            $courseDeadlines = DB::table('lms_course_enroll as e')
                ->join('sub_std_map as m', 'm.id', '=', 'e.course_id')
                ->leftJoin('tbluser as u', 'u.id', '=', 'e.user_id')
                ->where('e.sub_institute_id', $subInstituteId)
                ->whereNull('e.deleted_at')
                ->whereNotNull('e.end_date')
                ->whereBetween('e.end_date', [$from, $to])
                ->when(!$isAdmin && $userId, fn ($q) => $q->where('e.user_id', $userId))
                ->orderBy('e.end_date')
                ->limit(200)
                ->get([
                    DB::raw('DATE(e.end_date) as date'),
                    DB::raw('m.display_name as title'),
                    'e.user_id',
                    DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
                    DB::raw("'course-deadline' as kind"),
                ]);

            $events = collect();

            if (Schema::hasTable('calendar_events')) {
                $events = DB::table('calendar_events')
                    ->where('sub_institute_id', $subInstituteId)
                    ->whereBetween('school_date', [$from, $to])
                    ->orderBy('school_date')
                    ->limit(200)
                    ->get([
                        DB::raw('DATE(school_date) as date'),
                        'title',
                        DB::raw('NULL as user_id'),
                        DB::raw('NULL as learner_name'),
                        DB::raw("'event' as kind"),
                    ]);
            }

            return response()->json([
                'status' => true,
                'data' => $courseDeadlines->concat($events)->sortBy('date')->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load calendar deadlines',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** GET /api/lms/sessions/{id}/attendees - who is on this session. */
    public function attendees(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);

        $attendees = DB::table('lms_session_registrations as r')
            ->join('tbluser as u', 'u.id', '=', 'r.user_id')
            ->where('r.session_id', $id)
            ->when($subInstituteId, fn ($q) => $q->where('r.sub_institute_id', $subInstituteId))
            ->whereNull('r.deleted_at')
            ->orderBy('r.registered_at')
            ->get([
                'r.id', 'r.user_id', 'r.status', 'r.registered_at',
                'u.employee_no', 'u.email',
                DB::raw("TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) as learner_name"),
            ]);

        return response()->json(['status' => true, 'data' => $attendees]);
    }

    /** POST /api/lms/sessions - schedule a session (admin/HR). */
    public function store(Request $request)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            $this->rules()
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $id = DB::table('lms_virtual_classroom')->insertGetId($this->payload($request) + [
                'sub_institute_id' => $subInstituteId,
                'created_by' => $this->contextUserId($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Session scheduled',
                'data' => DB::table('lms_virtual_classroom')->find($id),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to schedule the session',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** PUT /api/lms/sessions/{id} */
    public function update(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $validator = Validator::make(
            array_merge($request->all(), ['sub_institute_id' => $subInstituteId]),
            $this->rules()
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->messages()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $session = DB::table('lms_virtual_classroom')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$session) {
            return response()->json(['status' => false, 'message' => 'Session not found'], 404);
        }

        // Capacity cannot drop below the people already on the session.
        $registered = DB::table('lms_session_registrations')
            ->where('session_id', $id)
            ->whereIn('status', ['registered', 'attended'])
            ->whereNull('deleted_at')
            ->count();

        if ($request->seats_total !== null && (int) $request->seats_total < $registered) {
            return response()->json([
                'status' => false,
                'message' => "This session already has {$registered} registration(s); seats cannot be set below that.",
            ], 422);
        }

        DB::table('lms_virtual_classroom')->where('id', $id)->update($this->payload($request) + [
            'updated_by' => $this->contextUserId($request),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Session updated',
            'data' => DB::table('lms_virtual_classroom')->find($id),
        ]);
    }

    /** DELETE /api/lms/sessions/{id} - soft delete, registrations go with it. */
    public function destroy(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }
        if ($roleError = $this->guardAdmin($request)) {
            return $roleError;
        }

        $subInstituteId = $this->tenantId($request);

        $session = DB::table('lms_virtual_classroom')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$session) {
            return response()->json(['status' => false, 'message' => 'Session not found'], 404);
        }

        $now = now();
        DB::table('lms_virtual_classroom')->where('id', $id)
            ->update(['deleted_at' => $now, 'deleted_by' => $this->contextUserId($request)]);
        DB::table('lms_session_registrations')->where('session_id', $id)->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'deleted_by' => $this->contextUserId($request)]);

        return response()->json([
            'status' => true,
            'message' => 'Session cancelled',
            'data' => ['id' => (int) $id],
        ]);
    }

    /**
     * POST /api/lms/sessions/{id}/register
     *
     * A learner takes a seat, or an admin puts someone on the session by
     * passing user_id explicitly.
     */
    public function register(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $subInstituteId = $this->tenantId($request);
        $callerId = $this->contextUserId($request);
        $targetId = $request->input('learner_id', $callerId);

        if (!$targetId) {
            return response()->json(['status' => false, 'message' => 'user_id is required'], 422);
        }

        // Registering someone else is an admin action.
        if ((string) $targetId !== (string) $callerId) {
            if ($roleError = $this->guardAdmin($request)) {
                return $roleError;
            }
        }

        $session = DB::table('lms_virtual_classroom')
            ->where('id', $id)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->first();

        if (!$session) {
            return response()->json(['status' => false, 'message' => 'Session not found'], 404);
        }

        if ($session->event_date !== null && $session->event_date < now()->toDateString()) {
            return response()->json([
                'status' => false,
                'message' => 'That session has already taken place.',
            ], 422);
        }

        $registered = DB::table('lms_session_registrations')
            ->where('session_id', $id)
            ->whereIn('status', ['registered', 'attended'])
            ->whereNull('deleted_at')
            ->count();

        if ($session->seats_total !== null && $registered >= (int) $session->seats_total) {
            return response()->json(['status' => false, 'message' => 'This session is full.'], 422);
        }

        $existing = DB::table('lms_session_registrations')
            ->where('session_id', $id)
            ->where('user_id', $targetId)
            ->first();

        if ($existing && $existing->deleted_at === null && $existing->status !== 'cancelled') {
            return response()->json([
                'status' => false,
                'message' => 'Already registered for this session.',
            ], 422);
        }

        $now = now();

        if ($existing) {
            // Re-registering after a cancellation reuses the row, because the
            // (session_id, user_id) pair is unique.
            DB::table('lms_session_registrations')->where('id', $existing->id)->update([
                'status' => 'registered',
                'registered_at' => $now,
                'registered_by' => $callerId,
                'deleted_at' => null,
                'deleted_by' => null,
                'updated_by' => $callerId,
                'updated_at' => $now,
            ]);
            $registrationId = $existing->id;
        } else {
            $registrationId = DB::table('lms_session_registrations')->insertGetId([
                'session_id' => $id,
                'user_id' => $targetId,
                'status' => 'registered',
                'registered_at' => $now,
                'registered_by' => $callerId,
                'sub_institute_id' => $subInstituteId,
                'created_by' => $callerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Registered for the session',
            'data' => DB::table('lms_session_registrations')->find($registrationId),
        ], 201);
    }

    /**
     * DELETE /api/lms/sessions/{id}/register
     *
     * Give up a seat. Learners cancel their own; admins can remove anyone.
     */
    public function cancelRegistration(Request $request, $id)
    {
        if ($tokenError = $this->guardApiToken($request)) {
            return $tokenError;
        }

        $callerId = $this->contextUserId($request);
        $targetId = $request->input('learner_id', $callerId);

        if ((string) $targetId !== (string) $callerId) {
            if ($roleError = $this->guardAdmin($request)) {
                return $roleError;
            }
        }

        $registration = DB::table('lms_session_registrations')
            ->where('session_id', $id)
            ->where('user_id', $targetId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->first();

        if (!$registration) {
            return response()->json(['status' => false, 'message' => 'Registration not found'], 404);
        }

        // Cancelled rather than deleted, so the seat frees up but the history
        // of who had signed up survives.
        DB::table('lms_session_registrations')->where('id', $registration->id)->update([
            'status' => 'cancelled',
            'updated_by' => $callerId,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Registration cancelled',
            'data' => ['id' => $registration->id],
        ]);
    }

    /** Shared validation for store/update. */
    private function rules(): array
    {
        return [
            'sub_institute_id' => 'required|integer',
            'room_name'        => 'required|string|max:191',
            'session_type'     => 'nullable|in:virtual,classroom',
            'description'      => 'nullable|string',
            'notes'            => 'nullable|string',
            'trainer_name'     => 'nullable|string|max:191',
            'trainer_email'    => 'nullable|email|max:191',
            'venue'            => 'nullable|string|max:191',
            'seats_total'      => 'nullable|integer|min:1|max:10000',
            'event_date'       => 'required|date',
            'from_time'        => 'required|date_format:H:i',
            // A session must end after it starts.
            'to_time'          => 'required|date_format:H:i|after:from_time',
            'url'              => 'nullable|string|max:2000',
            'status'           => 'nullable|string|max:10',
            'subject_id'       => 'nullable|integer',
        ];
    }

    /** Shared write payload for store/update. */
    private function payload(Request $request): array
    {
        return [
            'room_name' => $request->room_name,
            'session_type' => $request->input('session_type', 'virtual'),
            'description' => $request->description,
            'notes' => $request->notes,
            'trainer_name' => $request->trainer_name,
            'trainer_email' => $request->trainer_email,
            'venue' => $request->venue,
            'seats_total' => $request->seats_total,
            'event_date' => $request->event_date,
            'from_time' => $request->from_time,
            'to_time' => $request->to_time,
            'url' => $request->url,
            'status' => $request->input('status', '1'),
            'subject_id' => $request->subject_id,
        ];
    }
}
