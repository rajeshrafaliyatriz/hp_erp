<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\Monitoring\MonitoringService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\RoleKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GTM operational health. Readable by Administrators and Executives. The two LIVE probes (a real minimal AI call, and a
 * mail-server login that sends nothing) cost something or touch an external service, so they are Administrator-only,
 * explicit (`?probe=ai`, `?probe=smtp`) and throttled.
 */
class MonitoringController extends Controller
{
    use ResolvesApiIdentity;

    public function show(Request $request, MonitoringService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $profile = DB::table('tbluserprofilemaster')->where('id', $identity['user']->user_profile_id)->first(['id', 'sub_institute_id', 'role_key', 'name']);
        $admin = $profile !== null && RoleKey::fromProfile($profile) === 'administrator';

        $probes = array_filter(array_map('trim', explode(',', (string) $request->query('probe', ''))));
        if ($probes !== [] && ! $admin) {
            return response()->json(['status' => 0, 'code' => 'probe_forbidden', 'message' => 'Only administrators can run a live check.'], 403);
        }
        if (array_diff($probes, ['ai', 'smtp']) !== []) {
            return response()->json(['status' => 0, 'message' => "Unknown probe. Use 'ai' and/or 'smtp'."], 422);
        }

        return response()->json(['status' => 1, 'data' => $svc->report($identity['sub_institute_id'], $admin, in_array('ai', $probes, true), in_array('smtp', $probes, true))]);
    }
}
