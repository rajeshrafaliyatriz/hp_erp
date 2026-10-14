<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Scheduled/emailed report runs (Docs/reports-engine/PLAN.md, Phase 4) — same owner-only management convention as ReportShareController, since a schedule is just as sensitive as a share (both decide who gets this report's data, one by permission, one by inbox). */
class ReportScheduleController extends Controller
{
    use ResolvesApiIdentity;

    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** GET /api/reports/{id}/schedules */
    public function index(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $report = $this->ownedReport($id, (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $schedules = DB::table('report_schedules')
            ->where('report_id', $id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->resource($row));

        return response()->json(['status' => true, 'data' => ['schedules' => $schedules]]);
    }

    /** POST /api/reports/{id}/schedules */
    public function store(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $report = $this->ownedReport($id, $tenantId, (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $data = $this->validatePayload($request);

        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        $scheduleId = DB::table('report_schedules')->insertGetId([
            'report_id' => $id,
            'sub_institute_id' => $tenantId,
            'frequency' => $data['frequency'],
            'day_of_week' => $data['day_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'time_of_day' => $data['time_of_day'],
            'recipients' => json_encode($data['recipients']),
            'is_active' => true,
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('report_schedules')->where('id', $scheduleId)->first();

        return response()->json(['status' => true, 'message' => 'Schedule created.', 'data' => $this->resource($row)], 201);
    }

    /** PUT /api/reports/{id}/schedules/{scheduleId} */
    public function update(Request $request, int $id, int $scheduleId)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $report = $this->ownedReport($id, (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $existing = DB::table('report_schedules')->where('id', $scheduleId)->where('report_id', $id)->first();

        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Schedule not found.'], 404);
        }

        $data = $request->has('frequency') || $request->has('recipients')
            ? $this->validatePayload($request)
            : [];

        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        $update = ['updated_at' => now()];

        if ($request->has('is_active')) {
            $update['is_active'] = $request->boolean('is_active');
        }

        if (isset($data['frequency'])) {
            $update['frequency'] = $data['frequency'];
            $update['day_of_week'] = $data['day_of_week'] ?? null;
            $update['day_of_month'] = $data['day_of_month'] ?? null;
            $update['time_of_day'] = $data['time_of_day'];
            $update['recipients'] = json_encode($data['recipients']);
        }

        DB::table('report_schedules')->where('id', $scheduleId)->update($update);

        $row = DB::table('report_schedules')->where('id', $scheduleId)->first();

        return response()->json(['status' => true, 'message' => 'Schedule updated.', 'data' => $this->resource($row)]);
    }

    /** DELETE /api/reports/{id}/schedules/{scheduleId} */
    public function destroy(Request $request, int $id, int $scheduleId)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $report = $this->ownedReport($id, (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        $deleted = DB::table('report_schedules')->where('id', $scheduleId)->where('report_id', $id)->delete();

        if (!$deleted) {
            return response()->json(['status' => false, 'message' => 'Schedule not found.'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Schedule removed.']);
    }

    /** @return array|\Illuminate\Http\JsonResponse */
    private function validatePayload(Request $request)
    {
        $data = $request->validate([
            'frequency' => ['required', 'string', 'in:' . implode(',', self::FREQUENCIES)],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'time_of_day' => ['required', 'date_format:H:i'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email'],
        ]);

        if ($data['frequency'] === 'weekly' && ($data['day_of_week'] ?? null) === null) {
            return response()->json(['status' => false, 'message' => 'A weekly schedule needs a day of the week.'], 422);
        }

        if ($data['frequency'] === 'monthly' && ($data['day_of_month'] ?? null) === null) {
            return response()->json(['status' => false, 'message' => 'A monthly schedule needs a day of the month.'], 422);
        }

        $data['time_of_day'] .= ':00';

        return $data;
    }

    private function resource(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'frequency' => $row->frequency,
            'day_of_week' => $row->day_of_week !== null ? (int) $row->day_of_week : null,
            'day_of_month' => $row->day_of_month !== null ? (int) $row->day_of_month : null,
            'time_of_day' => substr((string) $row->time_of_day, 0, 5),
            'recipients' => json_decode($row->recipients, true) ?? [],
            'is_active' => (bool) $row->is_active,
            'last_sent_at' => $row->last_sent_at,
        ];
    }

    private function ownedReport(int $id, int $tenantId, int $userId): ?object
    {
        return DB::table('reports')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->where('owner_id', $userId)
            ->whereNull('deleted_at')
            ->first(['id', 'title', 'description']);
    }
}
