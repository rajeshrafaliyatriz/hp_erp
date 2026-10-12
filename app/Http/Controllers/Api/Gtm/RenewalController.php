<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Renewals: the contract renewals people record for customer accounts. Nothing is inferred - a customer with
 * no renewal row simply has none recorded, and the screens say so. Closing a renewal (renewed/churned/
 * cancelled) is final and needs confirmation; a churn needs a reason.
 */
class RenewalController extends Controller
{
    use ResolvesApiIdentity;

    public const STATUSES = ['upcoming', 'in_progress', 'renewed', 'churned', 'cancelled'];
    private const OPEN = ['upcoming', 'in_progress'];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $q = DB::table('gtm_renewals as r')->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'r.account_id')->where('a.sub_institute_id', '=', $t))
            ->where('r.sub_institute_id', $t)->whereNull('r.deleted_at')->select('r.*', 'a.name as account_name');
        if (in_array($request->input('status'), self::STATUSES, true)) {
            $q->where('r.status', $request->input('status'));
        } elseif ($request->input('status') !== 'all') {
            $q->whereIn('r.status', self::OPEN);
        }
        if ($request->filled('account_id')) {
            $q->where('r.account_id', (int) $request->input('account_id'));
        }
        if (in_array((int) $request->input('within_days'), [30, 60, 90], true)) {
            $q->where('r.renewal_date', '<=', now()->addDays((int) $request->input('within_days'))->toDateString());
        }
        $items = $q->orderBy('r.renewal_date')->limit(500)->get();

        // The renewal pipeline, from the same rows: what is open and when, per currency. Never added across currencies.
        $open = DB::table('gtm_renewals')->where('sub_institute_id', $t)->whereNull('deleted_at')->whereIn('status', self::OPEN)->get(['renewal_date', 'amount', 'currency', 'status']);
        $bucket = function (callable $in) use ($open) {
            $rows = $open->filter($in);
            $by = [];
            foreach ($rows as $r) {
                if ($r->amount !== null && $r->currency) {
                    $by[$r->currency] = ($by[$r->currency] ?? 0) + (int) round((float) $r->amount * 100);
                }
            }

            return ['count' => $rows->count(), 'value_by_currency' => array_map(fn ($c) => $c / 100, $by), 'without_amount' => $rows->filter(fn ($r) => $r->amount === null)->count()];
        };
        $today = now()->startOfDay();
        $days = fn ($r) => (int) $today->diffInDays(Carbon::parse($r->renewal_date)->startOfDay(), false);

        return response()->json(['status' => 1, 'data' => [
            'items' => $items,
            'pipeline' => [
                'overdue' => $bucket(fn ($r) => $days($r) < 0), 'due_30' => $bucket(fn ($r) => $days($r) >= 0 && $days($r) <= 30),
                'due_60' => $bucket(fn ($r) => $days($r) > 30 && $days($r) <= 60), 'due_90' => $bucket(fn ($r) => $days($r) > 60 && $days($r) <= 90),
                'later' => $bucket(fn ($r) => $days($r) > 90), 'open_total' => $bucket(fn ($r) => true),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();
        if ($err = $this->relationError($t, $d, null)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }
        $id = DB::table('gtm_renewals')->insertGetId($d + ['sub_institute_id' => $t, 'status' => 'upcoming', 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        GtmAudit::record('gtm.renewal.created', $t, 'gtm_renewals', $id, $identity['user_id'], ['account_id' => $d['account_id'], 'renewal_date' => $d['renewal_date']]);

        return response()->json(['status' => 1, 'data' => ['renewal' => DB::table('gtm_renewals')->find($id)]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $r = $this->find($t, $id);
        if (! $r) {
            return $this->notFound();
        }
        if (! in_array($r->status, self::OPEN, true)) {
            return response()->json(['status' => 0, 'code' => 'renewal_closed', 'message' => "A {$r->status} renewal cannot be edited."], 422);
        }
        if ($request->has('account_id')) {
            return response()->json(['status' => 0, 'message' => 'A renewal cannot be moved to another account.'], 422);
        }
        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $d = $v->validated();
        $eff = array_merge(['amount' => $r->amount, 'currency' => $r->currency], $d);
        if (($eff['amount'] ?? null) !== null && empty($eff['currency'])) {
            return response()->json(['status' => 0, 'message' => 'A currency is required when an amount is set.', 'errors' => ['currency' => ['A currency is required when an amount is set.']]], 422);
        }
        if ($err = $this->relationError($t, $d + ['account_id' => $r->account_id], $r)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }
        DB::table('gtm_renewals')->where('id', $id)->where('sub_institute_id', $t)->update($d + ['updated_at' => now()]);
        GtmAudit::record('gtm.renewal.updated', $t, 'gtm_renewals', $id, $identity['user_id'], ['changed' => array_keys($d)]);

        return response()->json(['status' => 1, 'data' => ['renewal' => $this->find($t, $id)]]);
    }

    /** upcoming -> in_progress freely; renewed / churned / cancelled are final and need `confirm`; churned needs a reason. */
    public function status(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $r = $this->find($t, $id);
        if (! $r) {
            return $this->notFound();
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:in_progress,renewed,churned,cancelled', 'note' => 'nullable|string|max:1000', 'confirm' => 'sometimes|boolean', 'next_renewal_date' => 'nullable|date|after:today']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $to = (string) $request->input('status');
        if (! in_array($r->status, self::OPEN, true)) {
            return response()->json(['status' => 0, 'code' => 'renewal_closed', 'message' => "This renewal is already {$r->status}."], 422);
        }
        if ($to === 'in_progress' && $r->status === 'in_progress') {
            return response()->json(['status' => 0, 'message' => 'It is already in progress.'], 422);
        }
        $final = $to !== 'in_progress';
        if ($final && ! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => "Marking this renewal {$to} is final and needs your confirmation."], 409);
        }
        if ($to === 'churned' && trim((string) $request->input('note')) === '') {
            return response()->json(['status' => 0, 'code' => 'reason_required', 'message' => 'Say why the customer churned.'], 422);
        }
        if ($request->filled('next_renewal_date') && $to !== 'renewed') {
            return response()->json(['status' => 0, 'message' => 'A next renewal date only applies when marking a renewal renewed.'], 422);
        }

        $accountStage = null;
        $next = null;
        DB::transaction(function () use ($r, $to, $final, $request, $t, $identity, &$accountStage, &$next) {
            DB::table('gtm_renewals')->where('id', $r->id)->where('sub_institute_id', $t)->update(['status' => $to, 'closed_at' => $final ? now() : null, 'outcome_note' => $request->input('note') ?: $r->outcome_note, 'updated_at' => now()]);
            GtmActivity::create(['sub_institute_id' => $t, 'account_id' => $r->account_id, 'type' => 'system', 'subject' => "Renewal \"{$r->name}\": {$r->status} → {$to}", 'occurred_at' => now(), 'user_id' => $identity['user_id']]);
            if ($to === 'churned') {
                // The customer is gone only if nothing else is still being renewed for them.
                $stillOpen = DB::table('gtm_renewals')->where('sub_institute_id', $t)->where('account_id', $r->account_id)->where('id', '!=', $r->id)->whereNull('deleted_at')->whereIn('status', self::OPEN)->exists();
                if (! $stillOpen && DB::table('gtm_accounts')->where('id', $r->account_id)->where('sub_institute_id', $t)->where('stage', 'customer')->update(['stage' => 'churned', 'updated_at' => now()])) {
                    $accountStage = 'churned';
                    GtmActivity::create(['sub_institute_id' => $t, 'account_id' => $r->account_id, 'type' => 'system', 'subject' => 'Stage changed: customer → churned (renewal churned)', 'occurred_at' => now(), 'user_id' => $identity['user_id']]);
                }
            }
            if ($to === 'renewed' && $request->filled('next_renewal_date')) {
                $next = DB::table('gtm_renewals')->insertGetId(['sub_institute_id' => $t, 'account_id' => $r->account_id, 'deal_id' => $r->deal_id, 'name' => $r->name, 'amount' => $r->amount, 'currency' => $r->currency,
                    'renewal_date' => $request->input('next_renewal_date'), 'status' => 'upcoming', 'owner_user_id' => $r->owner_user_id, 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        GtmAudit::record('gtm.renewal.status_changed', $t, 'gtm_renewals', $r->id, $identity['user_id'], ['from' => $r->status, 'to' => $to, 'account_stage' => $accountStage, 'next_renewal_id' => $next]);

        return response()->json(['status' => 1, 'data' => ['renewal' => $this->find($t, $id), 'account_stage_changed_to' => $accountStage, 'next_renewal_id' => $next]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $r = $this->find($t, $id);
        if (! $r) {
            return $this->notFound();
        }
        DB::table('gtm_renewals')->where('id', $id)->where('sub_institute_id', $t)->update(['deleted_at' => now()]);
        GtmAudit::record('gtm.renewal.updated', $t, 'gtm_renewals', $id, $identity['user_id'], ['changed' => ['deleted']]);

        return response()->json(['status' => 1, 'message' => 'Renewal removed']);
    }

    private function find(int $t, int $id): ?object
    {
        return DB::table('gtm_renewals')->where('sub_institute_id', $t)->whereNull('deleted_at')->find($id);
    }

    /** @param array<string, mixed> $d */
    private function relationError(int $t, array $d, ?object $existing): ?string
    {
        if (isset($d['account_id'])) {
            $acct = DB::table('gtm_accounts')->where('sub_institute_id', $t)->whereNull('deleted_at')->find((int) $d['account_id'], ['id', 'stage']);
            if (! $acct) {
                return 'Choose one of your own accounts.';
            }
            if ($existing === null && $acct->stage !== 'customer') {
                return 'Only customer accounts have renewals. Close a won deal for this account, or set its stage to customer, first.';
            }
        }
        if (! empty($d['deal_id']) && ! DB::table('gtm_deals')->where('sub_institute_id', $t)->whereNull('deleted_at')->where('id', $d['deal_id'])->where('account_id', $d['account_id'])->where('stage', 'won')->exists()) {
            return 'The linked deal must be a won deal on this account.';
        }
        if (! empty($d['owner_user_id']) && ! DB::table('tbluser')->where('id', $d['owner_user_id'])->where('sub_institute_id', $t)->exists()) {
            return 'Owner must be a user in this organisation.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'account_id' => $creating ? 'required|integer' : 'prohibited', 'name' => "$req|string|max:191", 'renewal_date' => "$req|date",
            'amount' => 'nullable|numeric|min:0|max:999999999999', 'currency' => 'nullable|string|size:3|regex:/^[A-Z]{3}$/'.($creating ? '|required_with:amount' : ''),
            'deal_id' => 'nullable|integer', 'owner_user_id' => 'nullable|integer',
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Renewal not found'], 404);
    }
}
