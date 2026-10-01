<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Signal;
use App\Domain\Signals\SignalRun;
use App\Domain\Signals\SignalRunner;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateSignalsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Signals API.
 *
 * TENANT COMES FROM THE TOKEN (ResolvesApiIdentity), never the request. Every query
 * below is bounded by that tenant, and a signal id belonging to another organisation
 * is answered exactly like one that does not exist (404), so ids cannot be probed.
 *
 * Nothing here executes a recommendation. Review and dismiss only change the
 * signal's own status; any real organisational change stays a human action.
 */
class SignalController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /signals */
    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $v = Validator::make($request->query(), [
            'department_id' => 'nullable|integer|min:1',
            'type' => 'nullable|string|in:' . implode(',', array_keys(config('signals.types'))),
            'priority' => 'nullable|string|in:' . implode(',', Signal::PRIORITIES),
            'status' => 'nullable|string|in:' . implode(',', Signal::STATUSES),
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }

        // Filters shared by the list and the summary counts. Status and priority are
        // applied to the list only: the summary cards describe the set the user is
        // looking at, and would all read zero but one if they narrowed themselves.
        $base = fn () => $this->filtered($request, $tenantId);

        $perPage = (int) $request->query('per_page', 10);
        $list = $base()
            ->when($request->query('status'), fn ($q, $s) => $q->where('s.status', $s))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('s.priority', $p))
            ->orderByRaw("CASE s.priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END")
            ->orderByDesc('s.generated_at')
            ->orderByDesc('s.id')
            ->paginate($perPage);

        $counts = $base()->selectRaw(
            "COUNT(*) AS total,
             COALESCE(SUM(CASE WHEN s.status = 'New' THEN 1 ELSE 0 END), 0) AS new_count,
             COALESCE(SUM(CASE WHEN s.priority = 'High' AND s.status <> 'Dismissed' THEN 1 ELSE 0 END), 0) AS high_count,
             COALESCE(SUM(CASE WHEN s.status = 'Reviewed' THEN 1 ELSE 0 END), 0) AS reviewed_count"
        )->first();

        $departments = DB::table('g2g_signals as s')
            ->join('hrms_departments as d', 'd.id', '=', 's.department_id')
            ->where('s.sub_institute_id', $tenantId)
            ->where('d.sub_institute_id', $tenantId)
            ->distinct()->orderBy('d.department')
            ->get(['d.id', 'd.department as name']);

        return response()->json([
            'status' => 1,
            'data' => collect($list->items())->map(fn ($row) => $this->present($row))->all(),
            'meta' => [
                'page' => $list->currentPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'last_page' => $list->lastPage(),
            ],
            'summary' => [
                'total' => (int) $counts->total,
                'new' => (int) $counts->new_count,
                'high_priority' => (int) $counts->high_count,
                'reviewed' => (int) $counts->reviewed_count,
            ],
            'filters' => [
                'types' => collect(config('signals.types'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'departments' => $departments,
            ],
        ]);
    }

    /** GET /signals/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->query($identity['sub_institute_id'])->where('s.id', $id)->first();

        return $row
            ? response()->json(['status' => 1, 'data' => $this->present($row, true)])
            : response()->json(['status' => 0, 'message' => 'Signal not found'], 404);
    }

    /** PATCH /signals/{id}/review */
    public function review(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, Signal::STATUS_REVIEWED);
    }

    /** PATCH /signals/{id}/dismiss */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, Signal::STATUS_DISMISSED);
    }

    /** POST /signals/generate */
    public function generate(Request $request, SignalRunner $runner): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (! $runner->aiConfigured($tenantId)) {
            return response()->json([
                'status' => 0,
                'code' => 'ai_not_configured',
                'message' => 'No AI provider is configured for this organisation.',
            ], 422);
        }

        $staleAfter = now()->subMinutes((int) config('signals.stale_run_minutes', 15));
        if (SignalRun::where('sub_institute_id', $tenantId)->where('status', SignalRun::RUNNING)->where('started_at', '>=', $staleAfter)->exists()) {
            return response()->json(['status' => 0, 'code' => 'already_running', 'message' => 'Signals are already being generated.'], 409);
        }

        $cooldown = (int) config('signals.manual_cooldown_minutes', 5);
        $recent = SignalRun::where('sub_institute_id', $tenantId)
            ->where('trigger', 'manual')
            ->where('status', '!=', SignalRun::SKIPPED)
            ->where('started_at', '>=', now()->subMinutes($cooldown))
            ->orderByDesc('started_at')->first();
        if ($recent) {
            $wait = max(1, (int) ceil($recent->started_at->copy()->addMinutes($cooldown)->diffInSeconds(now(), true)));

            return response()->json([
                'status' => 0,
                'code' => 'cooldown',
                'message' => 'Signals were generated a moment ago. Please wait before running again.',
                'retry_after_seconds' => $wait,
            ], 429)->header('Retry-After', (string) $wait);
        }

        // Queued (needs `php artisan queue:work`); the client polls /signals/status.
        GenerateSignalsJob::dispatch($tenantId, $identity['user_id']);

        return response()->json(['status' => 1, 'message' => 'Signal generation started.'], 202);
    }

    /** GET /signals/status */
    public function status(Request $request, SignalRunner $runner): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $latest = SignalRun::where('sub_institute_id', $tenantId)->orderByDesc('id')->first();
        $running = SignalRun::where('sub_institute_id', $tenantId)->where('status', SignalRun::RUNNING)
            ->where('started_at', '>=', now()->subMinutes((int) config('signals.stale_run_minutes', 15)))->exists();
        $lastGenerated = Signal::where('sub_institute_id', $tenantId)->max('generated_at');

        return response()->json([
            'status' => 1,
            'data' => [
                'ai_configured' => $runner->aiConfigured($tenantId),
                'running' => $running,
                'last_generated_at' => $lastGenerated ? \Illuminate\Support\Carbon::parse($lastGenerated)->toIso8601String() : null,
                'latest_run' => $latest ? $this->presentRun($latest) : null,
                'schedule' => [
                    'enabled' => (bool) config('signals.schedule_enabled'),
                    'time' => config('signals.schedule_time'),
                    'timezone' => config('signals.timezone'),
                ],
            ],
        ]);
    }

    /** GET /signals/runs */
    public function runs(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $runs = SignalRun::where('sub_institute_id', $identity['sub_institute_id'])
            ->orderByDesc('id')->limit(20)->get();

        return response()->json(['status' => 1, 'data' => $runs->map(fn ($r) => $this->presentRun($r))->values()]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function transition(Request $request, int $id, string $to): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $signal = Signal::where('sub_institute_id', $tenantId)->where('id', $id)->first();
        if (! $signal) {
            return response()->json(['status' => 0, 'message' => 'Signal not found'], 404);
        }

        $signal->forceFill(['status' => $to, 'reviewed_by' => $identity['user_id'], 'reviewed_at' => now()])->save();

        $row = $this->query($tenantId)->where('s.id', $id)->first();

        return response()->json(['status' => 1, 'data' => $this->present($row, true)]);
    }

    private function query(int $tenantId)
    {
        return DB::table('g2g_signals as s')
            ->leftJoin('hrms_departments as d', function ($join) use ($tenantId) {
                $join->on('d.id', '=', 's.department_id')->where('d.sub_institute_id', '=', $tenantId);
            })
            ->where('s.sub_institute_id', $tenantId)
            ->select('s.*', 'd.department as department_name');
    }

    private function filtered(Request $request, int $tenantId)
    {
        $query = $this->query($tenantId);

        if ($id = $request->query('department_id')) {
            $query->where('s.department_id', (int) $id);
        }
        if ($type = $request->query('type')) {
            $query->where('s.signal_type', $type);
        }
        if ($from = $request->query('from')) {
            $query->where('s.generated_at', '>=', \Illuminate\Support\Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $query->where('s.generated_at', '<=', \Illuminate\Support\Carbon::parse($to)->endOfDay());
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where(fn ($q) => $q->where('s.title', 'like', $like)
                ->orWhere('s.summary', 'like', $like)
                ->orWhere('s.explanation', 'like', $like));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(object $row, bool $detail = false): array
    {
        $types = config('signals.types');
        $out = [
            'id' => (int) $row->id,
            'title' => $row->title,
            'summary' => $row->summary,
            'signal_type' => $row->signal_type,
            'signal_type_label' => $types[$row->signal_type] ?? ucfirst(str_replace('_', ' ', $row->signal_type)),
            'priority' => $row->priority,
            'status' => $row->status,
            'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
            'department_name' => $row->department_name,
            'why_it_matters' => $row->why_it_matters,
            'recommended_action' => $row->recommended_action,
            'origin' => $row->origin,
            'generated_at' => \Illuminate\Support\Carbon::parse($row->generated_at)->toIso8601String(),
            'reviewed_at' => $row->reviewed_at ? \Illuminate\Support\Carbon::parse($row->reviewed_at)->toIso8601String() : null,
        ];

        if ($detail) {
            $out['explanation'] = $row->explanation;
            $out['evidence'] = json_decode($row->evidence ?? '[]', true) ?: [];
            $out['sources'] = json_decode($row->sources ?? '[]', true) ?: [];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function presentRun(SignalRun $run): array
    {
        return [
            'id' => $run->id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'signals_generated' => $run->signals_generated,
            'signals_duplicate' => $run->signals_duplicate,
            'signals_rejected' => $run->signals_rejected,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
        ];
    }

    private function invalid($validator): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    }
}
