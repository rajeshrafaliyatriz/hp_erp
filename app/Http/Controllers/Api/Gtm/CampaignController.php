<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmPlaybook;
use App\Domain\Gtm\Marketing\CampaignMembers;
use App\Domain\Gtm\Marketing\CampaignMetrics;
use App\Domain\Gtm\Marketing\SegmentCriteria;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Campaigns (account-based and otherwise): name, goal, an optional industry pack, a status, and the
 * accounts and contacts targeted. Results come from CampaignMetrics, counted live from real records.
 */
class CampaignController extends Controller
{
    use ResolvesApiIdentity;

    public const KINDS = ['abm', 'outbound', 'nurture', 'event'];
    public const STATUSES = ['draft', 'active', 'paused', 'completed', 'archived'];
    /** @var array<string, list<string>> allowed moves; archived is terminal and completed can only be archived */
    private const TRANSITIONS = ['draft' => ['active', 'archived'], 'active' => ['paused', 'completed', 'archived'], 'paused' => ['active', 'completed', 'archived'], 'completed' => ['archived'], 'archived' => []];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $q = DB::table('gtm_campaigns')->where('sub_institute_id', $t)->whereNull('deleted_at');
        if (in_array($request->input('status'), self::STATUSES, true)) {
            $q->where('status', $request->input('status'));
        } else {
            $q->where('status', '!=', 'archived');
        }
        $rows = $q->orderByDesc('id')->get();
        $counts = DB::table('gtm_campaign_members')->where('sub_institute_id', $t)->selectRaw('campaign_id, COUNT(DISTINCT account_id) accounts')->groupBy('campaign_id')->pluck('accounts', 'campaign_id');
        foreach ($rows as $r) {
            $r->target_accounts = (int) ($counts[$r->id] ?? 0);
        }

        return response()->json(['status' => 1, 'data' => ['items' => $rows, 'kinds' => self::KINDS, 'packs' => $this->packs($t)]]);
    }

    public function show(Request $request, int $id, CampaignMetrics $metrics): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $c = $this->find($t, $id);
        if (! $c) {
            return $this->notFound();
        }
        $members = DB::table('gtm_campaign_members as m')->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'm.account_id')->where('a.sub_institute_id', '=', $t))
            ->leftJoin('gtm_contacts as ct', fn ($j) => $j->on('ct.id', '=', 'm.contact_id')->where('ct.sub_institute_id', '=', $t))
            ->where('m.sub_institute_id', $t)->where('m.campaign_id', $id)->orderBy('a.name')
            ->get(['m.id', 'm.account_id', 'm.contact_id', 'm.created_at', 'a.name as account_name', 'a.stage as account_stage', 'ct.full_name as contact_name', 'ct.status as contact_status']);
        $pack = $c->industry_pack_slug ? $this->pack($t, $c->industry_pack_slug) : null;

        return response()->json(['status' => 1, 'data' => ['campaign' => $c, 'members' => $members, 'metrics' => $metrics->for($t, $id), 'industry_pack' => $pack, 'transitions' => self::TRANSITIONS[$c->status] ?? []]]);
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
        $data = $v->validated();
        if ($err = $this->relationError($t, $data)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }
        $id = DB::table('gtm_campaigns')->insertGetId($data + ['sub_institute_id' => $t, 'status' => 'draft', 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        GtmAudit::record('gtm.campaign.created', $t, 'gtm_campaigns', $id, $identity['user_id'], ['name' => $data['name'], 'kind' => $data['kind']]);

        return response()->json(['status' => 1, 'data' => ['campaign' => $this->find($t, $id)]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $c = $this->find($t, $id);
        if (! $c) {
            return $this->notFound();
        }
        if (in_array($c->status, ['completed', 'archived'], true)) {
            return response()->json(['status' => 0, 'code' => 'campaign_closed', 'message' => "A {$c->status} campaign cannot be edited."], 422);
        }
        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        if ($err = $this->relationError($t, $data)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }
        $merged = array_merge((array) $c, $data);
        if (! empty($merged['starts_on']) && ! empty($merged['ends_on']) && $merged['ends_on'] < $merged['starts_on']) {
            return response()->json(['status' => 0, 'message' => 'The end date cannot be before the start date.'], 422);
        }
        DB::table('gtm_campaigns')->where('id', $id)->where('sub_institute_id', $t)->update($data + ['updated_at' => now()]);
        GtmAudit::record('gtm.campaign.updated', $t, 'gtm_campaigns', $id, $identity['user_id'], ['changed' => array_keys($data)]);

        return response()->json(['status' => 1, 'data' => ['campaign' => $this->find($t, $id)]]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $c = $this->find($t, $id);
        if (! $c) {
            return $this->notFound();
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:'.implode(',', self::STATUSES)]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $to = (string) $request->input('status');
        if (! in_array($to, self::TRANSITIONS[$c->status] ?? [], true)) {
            return response()->json(['status' => 0, 'code' => 'bad_transition', 'message' => "A {$c->status} campaign cannot become {$to}."], 422);
        }
        if ($to === 'active' && DB::table('gtm_campaign_members')->where('campaign_id', $id)->doesntExist()) {
            return response()->json(['status' => 0, 'code' => 'no_targets', 'message' => 'Add at least one target account before activating a campaign.'], 422);
        }
        if (in_array($to, ['completed', 'archived'], true) && ! $request->boolean('confirm')) {
            return response()->json(['status' => 0, 'code' => 'confirmation_required', 'message' => "Marking a campaign {$to} is final. Confirm to continue."], 409);
        }
        DB::table('gtm_campaigns')->where('id', $id)->where('sub_institute_id', $t)->update(['status' => $to, 'updated_at' => now()]);
        // Archiving stops the campaign's pending outreach: nothing unsent may leave under a closed campaign.
        $cancelled = 0;
        if (in_array($to, ['completed', 'archived'], true)) {
            $cancelled = DB::table('gtm_outreach_messages')->where('sub_institute_id', $t)->where('campaign_id', $id)->whereIn('status', ['draft', 'pending_approval', 'approved', 'rejected'])
                ->update(['status' => 'cancelled', 'decision_note' => "Campaign {$to}", 'scheduled_for' => null, 'updated_at' => now()]);
        }
        GtmAudit::record('gtm.campaign.status_changed', $t, 'gtm_campaigns', $id, $identity['user_id'], ['from' => $c->status, 'to' => $to, 'outreach_cancelled' => $cancelled]);

        return response()->json(['status' => 1, 'data' => ['campaign' => $this->find($t, $id), 'outreach_cancelled' => $cancelled]]);
    }

    public function addMembers(Request $request, int $id, CampaignMembers $members): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $c = $this->find($t, $id);
        if (! $c) {
            return $this->notFound();
        }
        if (in_array($c->status, ['completed', 'archived'], true)) {
            return response()->json(['status' => 0, 'code' => 'campaign_closed', 'message' => "A {$c->status} campaign cannot take new targets."], 422);
        }
        $v = Validator::make($request->all(), ['segment_id' => 'nullable|integer', 'account_ids' => 'nullable|array|max:2000', 'account_ids.*' => 'integer', 'contact_ids' => 'nullable|array|max:2000', 'contact_ids.*' => 'integer']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $accountIds = (array) $request->input('account_ids', []);
        $contactIds = (array) $request->input('contact_ids', []);
        if ($request->filled('segment_id')) {
            $seg = DB::table('gtm_segments')->where('sub_institute_id', $t)->whereNull('deleted_at')->find((int) $request->input('segment_id'));
            if (! $seg) {
                return response()->json(['status' => 0, 'message' => 'Segment not found'], 404);
            }
            $crit = json_decode($seg->criteria, true) ?: [];
            if ($seg->kind === 'account') {
                $accountIds = array_merge($accountIds, SegmentCriteria::accounts($t, $crit)->limit(2000)->pluck('a.id')->all());
            } else {
                $contactIds = array_merge($contactIds, SegmentCriteria::contacts($t, $crit)->limit(2000)->pluck('c.id')->all());
            }
        }
        if ($accountIds === [] && $contactIds === []) {
            return response()->json(['status' => 0, 'message' => 'Choose a segment, accounts or contacts to add.'], 422);
        }

        return response()->json(['status' => 1, 'data' => $members->add($t, $id, array_map('intval', $accountIds), array_map('intval', $contactIds), $identity['user_id'])]);
    }

    public function removeMember(Request $request, int $id, int $memberId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if (! $this->find($t, $id)) {
            return $this->notFound();
        }
        $n = DB::table('gtm_campaign_members')->where('sub_institute_id', $t)->where('campaign_id', $id)->where('id', $memberId)->delete();

        return $n ? response()->json(['status' => 1, 'message' => 'Target removed']) : response()->json(['status' => 0, 'message' => 'Target not found'], 404);
    }

    private function find(int $t, int $id): ?object
    {
        return DB::table('gtm_campaigns')->where('sub_institute_id', $t)->whereNull('deleted_at')->find($id);
    }

    /** @param array<string, mixed> $data */
    private function relationError(int $t, array $data): ?string
    {
        if (! empty($data['industry_pack_slug']) && ! $this->pack($t, $data['industry_pack_slug'])) {
            return 'That industry pack does not exist or is not active.';
        }
        if (! empty($data['segment_id']) && ! DB::table('gtm_segments')->where('sub_institute_id', $t)->whereNull('deleted_at')->where('id', $data['segment_id'])->exists()) {
            return 'Choose one of your own segments.';
        }
        if (! empty($data['owner_user_id']) && ! DB::table('tbluser')->where('id', $data['owner_user_id'])->where('sub_institute_id', $t)->exists()) {
            return 'Owner must be a user in this organisation.';
        }

        return null;
    }

    /** The organisation's active copy of a pack, else the platform default. @return array<string, mixed>|null */
    private function pack(int $t, string $slug): ?array
    {
        $p = GtmPlaybook::where('kind', 'industry_pack')->where('slug', $slug)->where('status', 'active')->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $t))
            ->orderByRaw('sub_institute_id IS NULL')->first();

        return $p ? ['slug' => $p->slug, 'title' => $p->title, 'description' => $p->description, 'version' => $p->version, 'definition' => $p->definition] : null;
    }

    /** @return list<array{slug: string, title: string}> */
    private function packs(int $t): array
    {
        return GtmPlaybook::where('kind', 'industry_pack')->where('status', 'active')->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $t))
            ->orderBy('title')->get(['slug', 'title'])->unique('slug')->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->title])->values()->all();
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "$req|string|max:191", 'kind' => "$req|in:".implode(',', self::KINDS), 'goal' => 'nullable|string|max:2000',
            'industry_pack_slug' => 'nullable|string|max:100', 'segment_id' => 'nullable|integer', 'owner_user_id' => 'nullable|integer',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Campaign not found'], 404);
    }
}
