<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Travel Visit API: /api/travel-visits.
 *
 * The mobile app starts a trip, streams position fixes (about one a minute) and
 * ends it; the web lists trips and draws the route.
 *
 * Tenant and actor come from the token, never the body. An employee can only
 * start, track and end THEIR OWN trip. Reading: own trips always; a reporting
 * manager also sees their team's; HR/admin/executive/auditor see everyone's.
 */
class TravelVisitController extends Controller
{
    use ResolvesApiIdentity;

    private const PURPOSES = ['Client Visit', 'Site Visit', 'Installation', 'Maintenance', 'Collection', 'Delivery', 'Meeting', 'Other'];
    private const VEHICLES = ['Two Wheeler', 'Four Wheeler', 'Public Transport', 'Walking', 'Other'];

    /** A fix less accurate than this (metres) is stored but ignored for distance. */
    private const MAX_ACCURACY_M = 100;
    /** Movement smaller than this (km) between fixes is GPS jitter, not travel. */
    private const MIN_STEP_KM = 0.005;
    /** A hop faster than this (km/h) is a GPS jump, not travel. */
    private const MAX_SPEED_KMH = 200;

    /** GET /api/travel-visits/reference-data */
    public function referenceData(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => [
            'purposes' => self::PURPOSES,
            'vehicle_types' => self::VEHICLES,
            'scopes' => $this->availableScopes($identity),
        ]]);
    }

    /** GET /api/travel-visits?scope=mine|team|all&status=&from=&to=&employee_id= */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        $scope = (string) $request->input('scope', 'mine');
        if (!in_array($scope, $this->availableScopes($identity), true)) {
            $scope = 'mine';
        }

        $query = $this->baseQuery($tenantId)->orderByDesc('v.start_at')->orderByDesc('v.id');

        if ($scope === 'mine') {
            $query->where('v.employee_id', $userId);
        } elseif ($scope === 'team') {
            $query->whereIn('v.employee_id', $this->teamIds($tenantId, $userId));
        }

        if ($request->filled('employee_id') && $scope !== 'mine') {
            $query->where('v.employee_id', (int) $request->input('employee_id'));
        }
        if (in_array($request->input('status'), ['ongoing', 'completed'], true)) {
            $query->where('v.status', $request->input('status'));
        }
        if ($request->filled('from') && strtotime((string) $request->input('from'))) {
            $query->where('v.start_at', '>=', date('Y-m-d 00:00:00', strtotime((string) $request->input('from'))));
        }
        if ($request->filled('to') && strtotime((string) $request->input('to'))) {
            $query->where('v.start_at', '<=', date('Y-m-d 23:59:59', strtotime((string) $request->input('to'))));
        }

        $summary = (clone $query)->reorder()->select(
            DB::raw('COUNT(*) as visits'),
            DB::raw('COALESCE(SUM(v.distance), 0) as distance'),
            DB::raw("SUM(CASE WHEN v.status = 'ongoing' THEN 1 ELSE 0 END) as ongoing")
        )->first();

        $rows = $query->limit(500)->get($this->listColumns());

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'scope' => $scope,
            'data' => $rows->map(fn ($r) => $this->present($r))->values(),
            'summary' => [
                'visits' => (int) ($summary->visits ?? 0),
                'ongoing' => (int) ($summary->ongoing ?? 0),
                'distance' => round((float) ($summary->distance ?? 0), 3),
            ],
        ]);
    }

    /** GET /api/travel-visits/active - the caller's own ongoing trip, or null. */
    public function active(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $visit = $this->baseQuery($identity['sub_institute_id'])
            ->where('v.employee_id', $identity['user_id'])
            ->where('v.status', 'ongoing')
            ->orderByDesc('v.id')
            ->first($this->listColumns());

        return response()->json([
            'status' => 1,
            'message' => 'Success',
            'data' => $visit ? $this->present($visit) : null,
            'last_point_at' => $visit
                ? DB::table('hrms_travel_visit_points')->where('visit_id', $visit->id)->max('recorded_at')
                : null,
        ]);
    }

    /** GET /api/travel-visits/{id}  - with every point, ordered. */
    public function show(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $visit = $this->findVisible((int) $id, $identity);
        if (!$visit) {
            return response()->json(['status' => 0, 'message' => 'Travel visit not found'], 404);
        }

        $points = DB::table('hrms_travel_visit_points')
            ->where('visit_id', $visit->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['lat', 'lng', 'accuracy', 'speed', 'recorded_at']);

        $data = $this->present($visit);
        $data['points'] = $points->map(fn ($p) => [
            'lat' => (float) $p->lat,
            'lng' => (float) $p->lng,
            'accuracy' => $p->accuracy !== null ? (float) $p->accuracy : null,
            'speed' => $p->speed !== null ? (float) $p->speed : null,
            'recorded_at' => $this->iso($p->recorded_at),
        ])->values();

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => $data]);
    }

    /**
     * POST /api/travel-visits/start
     * { lat, lng, address?, purpose?, vehicle_type?, recorded_at? }  (selfie? remarks? optional, ignored by the app)
     *
     * The selfie and the remark are taken when the trip ENDS. A start selfie is still
     * accepted and stored if one is sent, so a client built the other way keeps working.
     */
    public function start(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];

        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'purpose' => 'nullable|string|max:100',
            'vehicle_type' => 'nullable|string|max:50',
            'remarks' => 'nullable|string|max:2000',
            'recorded_at' => 'nullable|date',
            'selfie' => 'nullable|image|max:8192',
        ], ['selfie.image' => 'The selfie must be a photo.']);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $running = $this->baseQuery($tenantId)
            ->where('v.employee_id', $userId)
            ->where('v.status', 'ongoing')
            ->first($this->listColumns());
        if ($running) {
            // Not an error for the app: it resumes tracking this trip.
            return response()->json([
                'status' => 0,
                'message' => 'You already have a trip in progress. End it before starting another.',
                'data' => $this->present($running),
            ], 409);
        }

        $startedAt = $this->parseTime($request->input('recorded_at'));

        $selfiePath = null;
        if ($request->hasFile('selfie')) {
            $selfiePath = $this->storeSelfie($request->file('selfie'), $tenantId, $userId);
            if (!$selfiePath) {
                return response()->json(['status' => 0, 'message' => 'The selfie could not be saved. Please try again.'], 500);
            }
        }

        try {
        $id = DB::transaction(function () use ($request, $tenantId, $userId, $startedAt, $selfiePath) {
            $id = DB::table('hrms_travel_visits')->insertGetId([
                'sub_institute_id' => $tenantId,
                'employee_id' => $userId,
                'visit_no' => $this->nextVisitNo($tenantId, $userId, $startedAt),
                'purpose' => $request->filled('purpose') ? trim((string) $request->input('purpose')) : null,
                'vehicle_type' => $request->filled('vehicle_type') ? trim((string) $request->input('vehicle_type')) : null,
                'remarks' => $request->filled('remarks') ? trim((string) $request->input('remarks')) : null,
                'status' => 'ongoing',
                'selfie_ref' => $selfiePath,
                'start_at' => $startedAt,
                'from_lat' => $request->input('lat'),
                'from_lng' => $request->input('lng'),
                'from_address' => $request->filled('address') ? trim((string) $request->input('address')) : null,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The start position is the first point of the trail.
            DB::table('hrms_travel_visit_points')->insert([
                'sub_institute_id' => $tenantId,
                'visit_id' => $id,
                'lat' => $request->input('lat'),
                'lng' => $request->input('lng'),
                'recorded_at' => $startedAt,
                'created_at' => now(),
            ]);

            return $id;
        });
        } catch (\Throwable $e) {
            // The trip was not created: do not leave its selfie behind.
            if ($selfiePath) {
                Storage::disk('digitalocean')->delete($selfiePath);
            }
            throw $e;
        }

        return response()->json([
            'status' => 1,
            'message' => 'Trip started.',
            'data' => $this->present($this->findVisible($id, $identity)),
        ], 201);
    }

    /**
     * POST /api/travel-visits/{id}/points
     * { points: [{ lat, lng, accuracy?, speed?, recorded_at }] }   (max 500)
     *
     * Safe to repeat: (visit, recorded_at) is unique, so a retried batch adds
     * nothing twice. Owner only, and only while the trip is ongoing.
     */
    public function addPoints(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $visit = $this->findOwn((int) $id, $identity);
        if (!$visit) {
            return response()->json(['status' => 0, 'message' => 'Travel visit not found'], 404);
        }
        if ($visit->status !== 'ongoing') {
            return response()->json(['status' => 0, 'message' => 'This trip has already ended.', 'ended' => true], 409);
        }

        $validator = Validator::make($request->all(), [
            'points' => 'required|array|min:1|max:500',
            'points.*.lat' => 'required|numeric|between:-90,90',
            'points.*.lng' => 'required|numeric|between:-180,180',
            'points.*.accuracy' => 'nullable|numeric|min:0',
            'points.*.speed' => 'nullable|numeric',
            'points.*.recorded_at' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $rows = [];
        foreach ($request->input('points') as $p) {
            $at = $this->parseTime($p['recorded_at']);
            $rows[$at] = [   // keyed by time: a duplicate inside one batch collapses here
                'sub_institute_id' => $identity['sub_institute_id'],
                'visit_id' => $visit->id,
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'accuracy' => $p['accuracy'] ?? null,
                'speed' => isset($p['speed']) ? max((float) $p['speed'], 0) : null,
                'recorded_at' => $at,
                'created_at' => now(),
            ];
        }

        DB::table('hrms_travel_visit_points')->insertOrIgnore(array_values($rows));

        $distance = $this->computeDistance((int) $visit->id);
        DB::table('hrms_travel_visits')->where('id', $visit->id)->update(['distance' => $distance, 'updated_at' => now()]);

        return response()->json([
            'status' => 1,
            'message' => 'Saved.',
            'received' => count($rows),
            'distance' => $distance,
        ]);
    }

    /**
     * POST /api/travel-visits/{id}/end
     * multipart: { selfie (image, required), lat, lng, address?, app_distance?, remarks?, recorded_at?,
     *              points_json? (a JSON array of points) }
     *
     * Any final points ride along so the last stretch is never lost. A multipart
     * body cannot carry an array of objects as neatly as JSON can, so they arrive as
     * one JSON string. `remarks` here is the END remark: the start remark is kept.
     */
    public function end(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $visit = $this->findOwn((int) $id, $identity);
        if (!$visit) {
            return response()->json(['status' => 0, 'message' => 'Travel visit not found'], 404);
        }
        if ($visit->status !== 'ongoing') {
            return response()->json(['status' => 0, 'message' => 'This trip has already ended.'], 409);
        }

        if ($request->filled('points_json')) {
            $decoded = json_decode((string) $request->input('points_json'), true);
            $request->merge(['points' => is_array($decoded) ? $decoded : []]);
        }

        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'app_distance' => 'nullable|numeric|min:0|max:5000',
            'remarks' => 'nullable|string|max:2000',
            'recorded_at' => 'nullable|date',
            'points' => 'nullable|array|max:500',
            'points.*.lat' => 'required_with:points|numeric|between:-90,90',
            'points.*.lng' => 'required_with:points|numeric|between:-180,180',
            'points.*.accuracy' => 'nullable|numeric|min:0',
            'points.*.speed' => 'nullable|numeric',
            'points.*.recorded_at' => 'required_with:points|date',
            'selfie' => 'required|image|max:8192',
        ], ['selfie.required' => 'Take a selfie to end the trip.', 'selfie.image' => 'The selfie must be a photo.']);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $endSelfie = $this->storeSelfie($request->file('selfie'), (int) $identity['sub_institute_id'], (int) $identity['user_id'], 'end');
        if (!$endSelfie) {
            return response()->json(['status' => 0, 'message' => 'The selfie could not be saved. Please try again.'], 500);
        }

        $endedAt = $this->parseTime($request->input('recorded_at'));

        $rows = [];
        foreach ((array) $request->input('points', []) as $p) {
            $at = $this->parseTime($p['recorded_at']);
            $rows[$at] = [
                'sub_institute_id' => $identity['sub_institute_id'],
                'visit_id' => $visit->id,
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'accuracy' => $p['accuracy'] ?? null,
                'speed' => isset($p['speed']) ? max((float) $p['speed'], 0) : null,
                'recorded_at' => $at,
                'created_at' => now(),
            ];
        }
        // The end position closes the trail.
        $rows[$endedAt] = [
            'sub_institute_id' => $identity['sub_institute_id'],
            'visit_id' => $visit->id,
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'accuracy' => null,
            'speed' => null,
            'recorded_at' => $endedAt,
            'created_at' => now(),
        ];

        try {
        DB::transaction(function () use ($rows, $visit, $request, $endedAt, $identity, $endSelfie) {
            DB::table('hrms_travel_visit_points')->insertOrIgnore(array_values($rows));

            DB::table('hrms_travel_visits')->where('id', $visit->id)->update([
                'status' => 'completed',
                'end_at' => $endedAt,
                'to_lat' => $request->input('lat'),
                'to_lng' => $request->input('lng'),
                'to_address' => $request->filled('address') ? trim((string) $request->input('address')) : null,
                'distance' => $this->computeDistance((int) $visit->id),
                'app_distance' => $request->filled('app_distance') ? $request->input('app_distance') : null,
                'end_remarks' => $request->filled('remarks') ? trim((string) $request->input('remarks')) : null,
                'end_selfie_ref' => $endSelfie,
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]);
        });
        } catch (\Throwable $e) {
            Storage::disk('digitalocean')->delete($endSelfie);
            throw $e;
        }

        return response()->json([
            'status' => 1,
            'message' => 'Trip ended.',
            'data' => $this->present($this->findVisible((int) $visit->id, $identity)),
        ]);
    }

    /** GET /api/travel-visits/{id}/selfie?which=start|end - a trip's selfie, to whoever may read the trip. */
    public function selfie(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $visit = $this->findVisible((int) $id, $identity);
        $path = $visit ? ($request->input('which') === 'end' ? $visit->end_selfie_ref : $visit->selfie_ref) : null;
        if (!$path || !Storage::disk('digitalocean')->exists($path)) {
            return response()->json(['status' => 0, 'message' => 'No selfie for this trip'], 404);
        }

        return Storage::disk('digitalocean')->response($path);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Scopes the caller may ask for: always mine; team for managers; all for HR-level roles. */
    private function availableScopes(array $identity): array
    {
        $role = RoleKey::forUserId((int) $identity['user_id']);

        if (RoleKey::satisfies($role, ['admin', 'hr', 'executive', 'auditor'])) {
            return ['mine', 'team', 'all'];
        }

        $hasTeam = DB::table('tbluser')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->where('reporting_manager_id', $identity['user_id'])
            ->exists();

        return $hasTeam ? ['mine', 'team'] : ['mine'];
    }

    /** Ids of the people who report to $userId. */
    private function teamIds(int $tenantId, int $userId): array
    {
        return DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('reporting_manager_id', $userId)
            ->pluck('id')
            ->all() ?: [0];
    }

    private function baseQuery(int $tenantId)
    {
        return DB::table('hrms_travel_visits as v')
            ->leftJoin('tbluser as u', 'u.id', '=', 'v.employee_id')
            ->where('v.sub_institute_id', $tenantId)
            ->whereNull('v.deleted_at');
    }

    private function listColumns(): array
    {
        return [
            'v.id', 'v.visit_no', 'v.employee_id', 'v.purpose', 'v.vehicle_type', 'v.remarks', 'v.status',
            'v.start_at', 'v.end_at', 'v.from_lat', 'v.from_lng', 'v.from_address',
            'v.to_lat', 'v.to_lng', 'v.to_address', 'v.distance', 'v.app_distance', 'v.amount', 'v.selfie_ref', 'v.end_selfie_ref', 'v.end_remarks',
            DB::raw("TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) as employee_name"),
            'u.employee_no as employee_code',
        ];
    }

    /** A trip the caller is allowed to READ, or null. */
    private function findVisible(int $id, array $identity)
    {
        $visit = $this->baseQuery($identity['sub_institute_id'])->where('v.id', $id)->first($this->listColumns());
        if (!$visit) {
            return null;
        }

        $userId = (int) $identity['user_id'];
        if ((int) $visit->employee_id === $userId) {
            return $visit;
        }

        $scopes = $this->availableScopes($identity);
        if (in_array('all', $scopes, true)) {
            return $visit;
        }
        if (in_array('team', $scopes, true) && in_array((int) $visit->employee_id, $this->teamIds($identity['sub_institute_id'], $userId), true)) {
            return $visit;
        }

        return null;
    }

    /** A trip that belongs to the caller, or null. Tracking is never done on someone else's behalf. */
    private function findOwn(int $id, array $identity)
    {
        return DB::table('hrms_travel_visits')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('employee_id', $identity['user_id'])
            ->whereNull('deleted_at')
            ->first();
    }

    private function present($row): array
    {
        return [
            'id' => (int) $row->id,
            'visit_no' => $row->visit_no,
            'employee_id' => (int) $row->employee_id,
            'employee_name' => $row->employee_name ?: null,
            'employee_code' => $row->employee_code ?? null,
            'purpose' => $row->purpose,
            'vehicle_type' => $row->vehicle_type,
            'remarks' => $row->remarks,
            'status' => $row->status,
            'start_at' => $this->iso($row->start_at),
            'end_at' => $this->iso($row->end_at),
            'from' => $row->from_lat !== null ? ['lat' => (float) $row->from_lat, 'lng' => (float) $row->from_lng, 'address' => $row->from_address] : null,
            'to' => $row->to_lat !== null ? ['lat' => (float) $row->to_lat, 'lng' => (float) $row->to_lng, 'address' => $row->to_address] : null,
            'distance' => (float) $row->distance,
            'app_distance' => $row->app_distance !== null ? (float) $row->app_distance : null,
            'amount' => (float) $row->amount,
            'has_selfie' => !empty($row->selfie_ref),
            'has_end_selfie' => !empty($row->end_selfie_ref),
            'end_remarks' => $row->end_remarks,
        ];
    }

    /**
     * A stored datetime as ISO-8601 WITH its offset. Times are kept in the app's
     * timezone with no zone attached, so sending them bare made every client guess
     * (a phone in IST read UTC times as local and showed them 5h30m early).
     */
    private function iso($value): ?string
    {
        return $value ? \Carbon\Carbon::parse($value, config('app.timezone'))->toIso8601String() : null;
    }

    /** Saved under the tenant's own private folder with a generated name; null if the disk refused it. */
    private function storeSelfie($file, int $tenantId, int $userId, string $kind = 'start'): ?string
    {
        if (!$file || !$file->isValid()) {
            return null;
        }

        $name = $kind . '_selfie_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($file->extension() ?: 'jpg');
        $dir = 'private/travel_visits/' . $tenantId;

        try {
            $ok = Storage::disk('digitalocean')->putFileAs($dir, $file, $name, [
                'visibility' => 'private',
                'ContentType' => $file->getClientMimeType(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $ok ? $dir . '/' . $name : null;
    }

    /** TV/{employee}/{MMYY}/{running no. for that employee and month}. */
    private function nextVisitNo(int $tenantId, int $userId, string $startedAt): string
    {
        $prefix = 'TV/' . $userId . '/' . date('my', strtotime($startedAt)) . '/';

        // Includes soft-deleted rows so a number is never reused.
        $count = DB::table('hrms_travel_visits')
            ->where('sub_institute_id', $tenantId)
            ->where('visit_no', 'like', $prefix . '%')
            ->count();

        return $prefix . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Kilometres along the trail. Fixes the phone itself called inaccurate are
     * skipped, as are steps that are jitter (too short) or a GPS jump (too fast).
     */
    private function computeDistance(int $visitId): float
    {
        $points = DB::table('hrms_travel_visit_points')
            ->where('visit_id', $visitId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['lat', 'lng', 'accuracy', 'recorded_at']);

        $total = 0.0;
        $prev = null;

        foreach ($points as $p) {
            if ($p->accuracy !== null && (float) $p->accuracy > self::MAX_ACCURACY_M) {
                continue;
            }
            if ($prev !== null) {
                $km = $this->haversineKm((float) $prev->lat, (float) $prev->lng, (float) $p->lat, (float) $p->lng);
                $hours = max(strtotime($p->recorded_at) - strtotime($prev->recorded_at), 1) / 3600;

                if ($km >= self::MIN_STEP_KM && ($km / $hours) <= self::MAX_SPEED_KMH) {
                    $total += $km;
                } elseif ($km >= self::MIN_STEP_KM) {
                    continue;   // a jump: do not move the anchor onto the bad fix
                }
            }
            $prev = $p;
        }

        return round($total, 3);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** A client time (ISO or Y-m-d H:i:s) as a server-local datetime string; "now" if missing/bad. */
    private function parseTime($value): string
    {
        $ts = $value ? strtotime((string) $value) : false;

        return date('Y-m-d H:i:s', $ts ?: time());
    }

    private function invalid($validator)
    {
        return response()->json([
            'status' => 0,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }
}
