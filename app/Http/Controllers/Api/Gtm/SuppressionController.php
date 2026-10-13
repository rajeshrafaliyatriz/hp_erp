<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\Outreach\Suppressions;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The suppression list. Anyone with admin rights may ADD an address or domain. Only entries added by hand
 * ("manual") may be removed: unsubscribes, hard bounces and complaints reflect what the recipient or the mail
 * system did, and re-enabling them would be emailing someone who said stop.
 */
class SuppressionController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $q = DB::table('gtm_suppressions')->where('sub_institute_id', $identity['sub_institute_id']);
        if ($request->filled('q')) {
            $q->where('value', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(trim((string) $request->input('q')))).'%');
        }
        $total = (clone $q)->count();

        return response()->json(['status' => 1, 'data' => ['items' => $q->orderByDesc('id')->limit(200)->get(), 'total' => $total, 'reasons' => Suppressions::REASONS]]);
    }

    public function store(Request $request, Suppressions $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $v = Validator::make($request->all(), ['kind' => 'required|in:email,domain', 'value' => 'required|string|max:191', 'note' => 'nullable|string|max:255']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $kind = (string) $request->input('kind');
        $value = Suppressions::normalize($kind, (string) $request->input('value'));
        if ($kind === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => 0, 'message' => 'That is not a valid email address.'], 422);
        }
        if ($kind === 'domain' && ! preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $value)) {
            return response()->json(['status' => 0, 'message' => 'That is not a valid domain (for example example.com).'], 422);
        }
        $added = $svc->add($identity['sub_institute_id'], $kind, $value, 'manual', $request->input('note'), $identity['user_id']);
        if (! $added) {
            return response()->json(['status' => 0, 'code' => 'already_suppressed', 'message' => 'It is already on the suppression list.'], 422);
        }

        return response()->json(['status' => 1, 'data' => ['item' => DB::table('gtm_suppressions')->where('sub_institute_id', $identity['sub_institute_id'])->where('kind', $kind)->where('value', $value)->first()]], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $row = DB::table('gtm_suppressions')->where('sub_institute_id', $t)->find($id);
        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Entry not found'], 404);
        }
        if ($row->reason !== 'manual') {
            return response()->json(['status' => 0, 'code' => 'not_removable', 'message' => "This entry was added because of a real event ({$row->reason}) and cannot be removed. Emailing someone who unsubscribed, bounced or complained is not allowed."], 422);
        }
        DB::table('gtm_suppressions')->where('id', $id)->where('sub_institute_id', $t)->delete();
        GtmAudit::record('gtm.suppression.removed', $t, 'gtm_suppressions', $id, $identity['user_id'], ['kind' => $row->kind, 'value' => $row->value]);

        return response()->json(['status' => 1, 'message' => 'Removed']);
    }
}
