<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiProviderHttpException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Gtm\DealAnalyzer;
use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmDeal;
use App\Domain\Gtm\GtmPlaybook;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * GTM deals: pipeline records, stage changes, health, and AI analysis.
 *
 * Tenant comes from the token only; another organisation's deal answers 404. Closing a deal
 * (won/lost) or reopening one is consequential, so it needs `confirm: true` in the request - a
 * stage change is never a side effect of an edit and never made by an AI result. Amounts are
 * only ever summed per currency.
 */
class DealController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request, DealHealth $health): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $q = GtmDeal::query()->where('gtm_deals.sub_institute_id', $t)
            ->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'gtm_deals.account_id')->where('a.sub_institute_id', '=', $t))
            ->select('gtm_deals.*', 'a.name as account_name');
        if (in_array($request->input('stage'), GtmDeal::STAGES, true)) {
            $q->where('gtm_deals.stage', $request->input('stage'));
        }
        if ($request->input('status') === 'open') {
            $q->whereIn('gtm_deals.stage', GtmDeal::OPEN_STAGES);
        } elseif ($request->input('status') === 'closed') {
            $q->whereIn('gtm_deals.stage', ['won', 'lost']);
        }
        if ($request->filled('account_id')) {
            $q->where('gtm_deals.account_id', (int) $request->input('account_id'));
        }
        if ($request->filled('q')) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->input('q'))).'%';
            $q->where(fn ($w) => $w->where('gtm_deals.name', 'like', $like)->orWhere('a.name', 'like', $like));
        }
        $page = $q->orderByDesc('gtm_deals.id')->paginate(min(100, max(5, (int) $request->input('per_page', 25))));
        $assessed = $health->assess($t, $page->items());

        return response()->json(['status' => 1, 'data' => [
            'items' => collect($page->items())->map(fn ($d) => $d->toArray() + ['health' => $assessed[$d->id] ?? null])->all(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(),
        ]]);
    }

    /** Open pipeline by stage (counts and per-currency value) plus recent wins/losses. All measured. */
    public function pipeline(Request $request, DealHealth $health): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $open = GtmDeal::where('sub_institute_id', $t)->whereIn('stage', GtmDeal::OPEN_STAGES)->get();
        $assessed = $health->assess($t, $open);
        $stages = [];
        foreach (GtmDeal::OPEN_STAGES as $s) {
            $inStage = $open->where('stage', $s);
            $stages[] = [
                'stage' => $s, 'count' => $inStage->count(), 'value_by_currency' => $this->valueByCurrency($inStage),
                'without_amount' => $inStage->whereNull('amount')->count(),
                'flagged' => $inStage->filter(fn ($d) => ($assessed[$d->id]['flags'] ?? []) !== [])->count(),
            ];
        }
        $days = 90;
        $closed = GtmDeal::where('sub_institute_id', $t)->whereIn('stage', ['won', 'lost'])->where('closed_at', '>=', now()->subDays($days))->get();

        return response()->json(['status' => 1, 'data' => [
            'stages' => $stages,
            'open_total' => $open->count(),
            'open_value_by_currency' => $this->valueByCurrency($open),
            'closed_last_days' => $days,
            'won' => ['count' => $closed->where('stage', 'won')->count(), 'value_by_currency' => $this->valueByCurrency($closed->where('stage', 'won'))],
            'lost' => ['count' => $closed->where('stage', 'lost')->count(), 'value_by_currency' => $this->valueByCurrency($closed->where('stage', 'lost'))],
        ]]);
    }

    /** Open deals with at least one health flag, most flagged first, plus a count per flag. */
    public function health(Request $request, DealHealth $health): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $open = GtmDeal::where('gtm_deals.sub_institute_id', $t)->whereIn('gtm_deals.stage', GtmDeal::OPEN_STAGES)
            ->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'gtm_deals.account_id')->where('a.sub_institute_id', '=', $t))
            ->select('gtm_deals.*', 'a.name as account_name')->get();
        $assessed = $health->assess($t, $open);
        $counts = [];
        $items = $open->map(fn ($d) => $d->toArray() + ['health' => $assessed[$d->id]])
            ->filter(fn ($d) => $d['health']['flags'] !== [])
            ->sortByDesc(fn ($d) => count($d['health']['flags']))->values();
        foreach ($items as $d) {
            foreach ($d['health']['flags'] as $f) {
                $counts[$f] = ($counts[$f] ?? 0) + 1;
            }
        }

        return response()->json(['status' => 1, 'data' => ['open_deals' => $open->count(), 'flagged_deals' => $items->count(), 'flag_counts' => $counts, 'flag_labels' => DealHealth::FLAGS, 'items' => $items->all()]]);
    }

    public function show(Request $request, int $id, DealHealth $health): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $deal = GtmDeal::where('sub_institute_id', $t)->find($id);
        if (! $deal) {
            return $this->notFound();
        }
        $account = GtmAccount::where('sub_institute_id', $t)->find($deal->account_id, ['id', 'name', 'stage', 'industry']);
        $latest = fn (string $kind) => DB::table('gtm_analyses')->where('sub_institute_id', $t)->where('subject_type', 'deal')->where('subject_id', $id)
            ->where('kind', $kind)->where('status', 'success')->orderByDesc('id')->first(['id', 'result', 'provider', 'model', 'created_at']);
        $decode = function ($row) {
            if ($row) {
                $row->result = json_decode($row->result ?? 'null', true);
            }

            return $row;
        };

        return response()->json(['status' => 1, 'data' => [
            'deal' => $deal,
            'account' => $account,
            'contacts' => DB::table('gtm_contacts')->where('sub_institute_id', $t)->where('account_id', $deal->account_id)->whereNull('deleted_at')
                ->get(['id', 'full_name', 'title', 'email', 'role_in_deal', 'status']),
            'activities' => GtmActivity::where('sub_institute_id', $t)->where(fn ($w) => $w->where('deal_id', $id)->orWhere('account_id', $deal->account_id))
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get(),
            'history' => DB::table('gtm_deal_stage_history')->where('sub_institute_id', $t)->where('deal_id', $id)->orderBy('id')->get(['from_stage', 'to_stage', 'note', 'changed_by', 'changed_at']),
            'health' => $health->assess($t, [$deal])[$id] ?? null,
            'flag_labels' => DealHealth::FLAGS,
            'analyses' => [
                'methodology_score' => $decode($latest('methodology_score')),
                'discovery_analysis' => $decode($latest('discovery_analysis')),
                'deal_coach' => $decode($latest('deal_coach')),
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
            return $this->invalid($v);
        }
        $data = $v->validated();
        if ($err = $this->relationError($t, $data)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }

        $deal = DB::transaction(function () use ($data, $t, $identity) {
            $deal = GtmDeal::create($data + ['sub_institute_id' => $t, 'stage' => $data['stage'] ?? 'discovery', 'stage_changed_at' => now(), 'created_by' => $identity['user_id']]);
            DB::table('gtm_deal_stage_history')->insert(['sub_institute_id' => $t, 'deal_id' => $deal->id, 'from_stage' => null, 'to_stage' => $deal->stage, 'note' => 'Created', 'changed_by' => $identity['user_id'], 'changed_at' => now()]);
            GtmActivity::create(['sub_institute_id' => $t, 'account_id' => $deal->account_id, 'deal_id' => $deal->id, 'type' => 'system', 'subject' => "Deal created: {$deal->name}", 'occurred_at' => now(), 'user_id' => $identity['user_id']]);

            return $deal;
        });
        GtmAudit::record('gtm.deal.created', $t, 'gtm_deals', $deal->id, $identity['user_id'], ['name' => $deal->name, 'account_id' => $deal->account_id]);

        return response()->json(['status' => 1, 'data' => ['deal' => $deal]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $deal = GtmDeal::where('sub_institute_id', $t)->find($id);
        if (! $deal) {
            return $this->notFound();
        }
        if (! $deal->isOpen()) {
            return response()->json(['status' => 0, 'code' => 'deal_closed', 'message' => 'A closed deal cannot be edited. Reopen it first (with confirmation).'], 422);
        }
        if ($request->has('stage') || $request->has('account_id')) {
            return response()->json(['status' => 0, 'message' => 'Use the stage endpoint to change stage; a deal cannot be moved to another account.'], 422);
        }

        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $data = $v->validated();
        // An amount and its currency travel together: validate the resulting pair, not the patch alone.
        $effective = array_merge($deal->only(['amount', 'currency']), $data);
        if (($effective['amount'] ?? null) !== null && empty($effective['currency'])) {
            return response()->json(['status' => 0, 'message' => 'A currency is required when an amount is set.', 'errors' => ['currency' => ['A currency is required when an amount is set.']]], 422);
        }
        if ($err = $this->relationError($t, $data)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }

        $deal->fill($data)->save();
        GtmAudit::record('gtm.deal.updated', $t, 'gtm_deals', $deal->id, $identity['user_id'], ['changed' => array_keys($data)]);

        return response()->json(['status' => 1, 'data' => ['deal' => $deal->fresh()]]);
    }

    /** Move a deal. Closing or reopening needs `confirm: true`; a loss needs a reason. */
    public function stage(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $deal = GtmDeal::where('sub_institute_id', $t)->find($id);
        if (! $deal) {
            return $this->notFound();
        }
        $v = Validator::make($request->all(), ['stage' => 'required|in:'.implode(',', GtmDeal::STAGES), 'confirm' => 'sometimes|boolean', 'close_reason' => 'nullable|string|max:1000', 'note' => 'nullable|string|max:500']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $to = (string) $request->input('stage');
        $from = $deal->stage;
        if ($to === $from) {
            return response()->json(['status' => 0, 'message' => 'The deal is already in this stage.'], 422);
        }
        $closing = in_array($to, ['won', 'lost'], true);
        $reopening = ! $deal->isOpen() && in_array($to, GtmDeal::OPEN_STAGES, true);
        if (($closing || $reopening || ! $deal->isOpen()) && ! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => $closing ? "Closing this deal as {$to} is final for reporting and needs your confirmation." : 'Reopening a closed deal needs your confirmation.'], 409);
        }
        if ($to === 'lost' && trim((string) $request->input('close_reason')) === '') {
            return response()->json(['status' => 0, 'code' => 'reason_required', 'message' => 'Say why the deal was lost - it is the only way the pipeline learns from it.'], 422);
        }

        $accountStageChanged = false;
        DB::transaction(function () use ($deal, $from, $to, $closing, $reopening, $request, $t, $identity, &$accountStageChanged) {
            $deal->forceFill([
                'stage' => $to, 'stage_changed_at' => now(),
                'closed_at' => $closing ? now() : null,
                'close_reason' => $closing ? ($request->input('close_reason') ?: null) : null,
            ])->save();
            DB::table('gtm_deal_stage_history')->insert(['sub_institute_id' => $t, 'deal_id' => $deal->id, 'from_stage' => $from, 'to_stage' => $to, 'note' => $request->input('note') ?: $request->input('close_reason'), 'changed_by' => $identity['user_id'], 'changed_at' => now()]);
            GtmActivity::create(['sub_institute_id' => $t, 'account_id' => $deal->account_id, 'deal_id' => $deal->id, 'type' => 'system', 'subject' => "Deal stage: {$from} → {$to}", 'occurred_at' => now(), 'user_id' => $identity['user_id']]);

            // A won deal makes its account a customer - that is what Customer Success reviews.
            if ($to === 'won') {
                $account = GtmAccount::where('sub_institute_id', $t)->find($deal->account_id);
                if ($account && $account->stage !== 'customer') {
                    $account->forceFill(['stage' => 'customer'])->save();
                    GtmActivity::create(['sub_institute_id' => $t, 'account_id' => $account->id, 'type' => 'system', 'subject' => "Stage changed: {$account->getOriginal('stage')} → customer (deal won)", 'occurred_at' => now(), 'user_id' => $identity['user_id']]);
                    $accountStageChanged = true;
                }
            }
        });
        GtmAudit::record('gtm.deal.stage_changed', $t, 'gtm_deals', $deal->id, $identity['user_id'], ['from' => $from, 'to' => $to, 'confirmed' => $request->boolean('confirm'), 'account_made_customer' => $accountStageChanged]);

        return response()->json(['status' => 1, 'data' => ['deal' => $deal->fresh(), 'account_stage_changed' => $accountStageChanged]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $deal = GtmDeal::where('sub_institute_id', $t)->find($id);
        if (! $deal) {
            return $this->notFound();
        }
        $deal->delete();
        GtmAudit::record('gtm.deal.deleted', $t, 'gtm_deals', $id, $identity['user_id'], ['name' => $deal->name, 'stage' => $deal->stage]);

        return response()->json(['status' => 1, 'message' => 'Deal removed']);
    }

    public function logActivity(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $deal = GtmDeal::where('sub_institute_id', $t)->find($id);
        if (! $deal) {
            return $this->notFound();
        }
        $v = Validator::make($request->all(), ['type' => 'required|in:note,call,meeting,email,linkedin,task', 'subject' => 'required|string|max:255', 'body' => 'nullable|string|max:20000', 'direction' => 'nullable|in:inbound,outbound']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $activity = GtmActivity::create($v->validated() + ['sub_institute_id' => $t, 'account_id' => $deal->account_id, 'deal_id' => $deal->id, 'occurred_at' => now(), 'user_id' => $identity['user_id']]);
        GtmAudit::record('gtm.activity.logged', $t, 'gtm_activities', $activity->id, $identity['user_id'], ['deal_id' => $deal->id, 'type' => $activity->type]);

        return response()->json(['status' => 1, 'data' => ['activity' => $activity]], 201);
    }

    public function score(Request $request, int $id, DealAnalyzer $analyzer): JsonResponse
    {
        return $this->analyse($request, $id, fn ($deal, $uid) => $analyzer->score($deal, $this->methodologyFor($request, $deal), $uid), 'deal_scored');
    }

    public function discovery(Request $request, int $id, DealAnalyzer $analyzer): JsonResponse
    {
        $v = Validator::make($request->all(), ['notes' => 'required|string|min:40|max:20000']);
        if ($v->fails()) {
            return $this->invalid($v);
        }

        return $this->analyse($request, $id, fn ($deal, $uid) => $analyzer->discovery($deal, (string) $request->input('notes'), $uid), 'deal_discovery_analysed');
    }

    /** Shared wrapper: tenant lookup, then the AI call with every failure mapped to a safe response. */
    private function analyse(Request $request, int $id, callable $fn, string $event): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $deal = GtmDeal::where('sub_institute_id', $t)->find($id);
        if (! $deal) {
            return $this->notFound();
        }
        try {
            $out = $fn($deal, $identity['user_id']);
        } catch (\DomainException $e) {
            return response()->json(['status' => 0, 'code' => 'invalid_request', 'message' => $e->getMessage()], 422);
        } catch (AiNotConfiguredException $e) {
            return response()->json(['status' => 0, 'code' => 'ai_not_configured', 'message' => $e->getMessage()], 503);
        } catch (AiQuotaExceededException|AiCredentialsExhaustedException $e) {
            return response()->json(['status' => 0, 'code' => 'ai_unavailable', 'message' => $e->getMessage()], 503);
        } catch (AiProviderHttpException $e) {
            report($e);
            if ($e->httpStatus === 429) {
                return response()->json(['status' => 0, 'code' => 'ai_rate_limited', 'message' => 'The AI provider\'s quota or rate limit has been reached. Nothing was changed - try again later.'], 503);
            }

            return response()->json(['status' => 0, 'code' => $e->httpStatus >= 500 ? 'ai_provider_busy' : 'ai_error', 'message' => $e->httpStatus >= 500 ? 'The AI provider is temporarily overloaded. Nothing was changed - please try again in a minute.' : 'The AI provider rejected this request. Nothing was changed.'], $e->httpStatus >= 500 ? 503 : 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 0, 'code' => 'ai_error', 'message' => 'The AI provider could not complete this analysis. Nothing was changed.'], 502);
        }
        GtmAudit::record("gtm.{$event}", $t, 'gtm_deals', $deal->id, $identity['user_id'], ['analysis_id' => $out['analysis_id'] ?? null]);

        return response()->json(['status' => 1, 'data' => $out]);
    }

    /** The methodology asked for, else the deal's own, else the organisation's/ platform's first active one. */
    private function methodologyFor(Request $request, GtmDeal $deal): string
    {
        $slug = (string) ($request->input('methodology_slug') ?: $deal->methodology_slug);
        if ($slug !== '') {
            return $slug;
        }
        $t = (int) $deal->sub_institute_id;
        $first = GtmPlaybook::where('kind', 'methodology')->where('status', 'active')->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $t))
            ->orderByRaw('sub_institute_id IS NULL')->orderBy('id')->value('slug');
        if (! $first) {
            throw new \DomainException('No qualification methodology is available. Add one under GTM → Playbooks.');
        }

        return $first;
    }

    /** @return array<string, float> value per currency; amounts are never added across currencies. */
    private function valueByCurrency($deals): array
    {
        $out = [];
        foreach ($deals as $d) {
            if ($d->amount !== null && $d->currency) {
                $out[$d->currency] = round(($out[$d->currency] ?? 0) + (float) $d->amount, 2);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function relationError(int $t, array $data): ?string
    {
        if (isset($data['account_id']) && ! GtmAccount::where('sub_institute_id', $t)->whereKey($data['account_id'])->exists()) {
            return 'Choose one of your own accounts.';
        }
        if (! empty($data['owner_user_id']) && ! DB::table('tbluser')->where('id', $data['owner_user_id'])->where('sub_institute_id', $t)->exists()) {
            return 'Owner must be a user in this organisation.';
        }
        if (! empty($data['methodology_slug']) && ! GtmPlaybook::where('kind', 'methodology')->where('status', 'active')->where('slug', $data['methodology_slug'])
            ->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $t))->exists()) {
            return 'That methodology does not exist or is not active.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'account_id' => $creating ? 'required|integer' : 'prohibited',
            'name' => "$req|string|max:191",
            'stage' => $creating ? 'sometimes|in:'.implode(',', GtmDeal::OPEN_STAGES) : 'prohibited',
            'amount' => 'nullable|numeric|min:0|max:999999999999',
            'currency' => 'nullable|string|size:3|regex:/^[A-Z]{3}$/'.($creating ? '|required_with:amount' : ''),
            'expected_close_date' => 'nullable|date',
            'owner_user_id' => 'nullable|integer',
            'next_step' => 'nullable|string|max:1000',
            'next_step_due' => 'nullable|date',
            'methodology_slug' => 'nullable|string|max:100',
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Deal not found'], 404);
    }

    private function invalid($validator): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    }
}
