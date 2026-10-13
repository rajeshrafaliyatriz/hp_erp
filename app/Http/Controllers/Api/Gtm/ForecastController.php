<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\Forecast\ForecastMath;
use App\Domain\Gtm\Forecast\ForecastService;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmDeal;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Forecast: a calculation over open deals, kept apart from actual results (see ForecastService).
 * Probabilities are set here by an administrator; there are no defaults.
 */
class ForecastController extends Controller
{
    use ResolvesApiIdentity;

    public function show(Request $request, ForecastService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $v = Validator::make($request->all(), [
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'owner_user_id' => 'nullable|integer', 'department_id' => 'nullable|integer',
            'stage' => 'nullable|in:'.implode(',', GtmDeal::OPEN_STAGES), 'currency' => 'nullable|string|size:3|regex:/^[A-Z]{3}$/',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        if (! empty($v->validated()['from']) && ! empty($v->validated()['to']) && \Illuminate\Support\Carbon::parse($v->validated()['from'])->diffInMonths(\Illuminate\Support\Carbon::parse($v->validated()['to'])) > 36) {
            return response()->json(['status' => 0, 'message' => 'Choose a window of at most 36 months.'], 422);
        }

        return response()->json(['status' => 1, 'data' => $svc->build($identity['sub_institute_id'], $v->validated())]);
    }

    public function settings(Request $request, ForecastService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $settings = $svc->settings($t);

        return response()->json(['status' => 1, 'data' => [
            'stages' => $settings, 'configured' => collect($settings)->contains(fn ($s) => $s['probability'] !== null),
            'categories' => ForecastMath::CATEGORIES, 'suggestions' => $svc->historicalWinRates($t),
            'note' => 'Probabilities are yours to set. Suggestions come from your own closed deals and appear only once there are enough of them; they are never applied automatically.',
        ]]);
    }

    public function update(Request $request, ForecastService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $v = Validator::make($request->all(), ['stages' => 'required|array|min:1']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $rows = [];
        foreach ((array) $request->input('stages') as $stage => $s) {
            if (! in_array($stage, GtmDeal::OPEN_STAGES, true) || ! is_array($s)) {
                return response()->json(['status' => 0, 'message' => "'{$stage}' is not an open stage."], 422);
            }
            $prob = $s['probability'] ?? null;
            if ($prob !== null && $prob !== '' && (! is_numeric($prob) || $prob < 0 || $prob > 100)) {
                return response()->json(['status' => 0, 'message' => "Probability for {$stage} must be between 0 and 100."], 422);
            }
            $cat = $s['category'] ?? 'pipeline';
            if (! in_array($cat, ForecastMath::CATEGORIES, true)) {
                return response()->json(['status' => 0, 'message' => "Unknown category '{$cat}' for {$stage}."], 422);
            }
            $rows[$stage] = ['probability' => ($prob === null || $prob === '') ? null : round((float) $prob, 2), 'category' => $cat];
        }

        $before = $svc->settings($t);
        DB::transaction(function () use ($rows, $t, $identity) {
            foreach ($rows as $stage => $r) {
                DB::table('gtm_stage_settings')->updateOrInsert(['sub_institute_id' => $t, 'stage' => $stage], $r + ['updated_by' => $identity['user_id'], 'updated_at' => now(), 'created_at' => now()]);
            }
        });
        GtmAudit::record('gtm.forecast.settings_updated', $t, 'gtm_stage_settings', null, $identity['user_id'], ['before' => array_map(fn ($s) => ['probability' => $s['probability'], 'category' => $s['category']], $before), 'after' => $rows]);

        return $this->settings($request, $svc);
    }
}
