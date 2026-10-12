<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\Marketing\SegmentCriteria;
use App\Domain\Gtm\OutreachMailer;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Segments: saved, validated filters over accounts or contacts, evaluated live and tenant-scoped.
 * A segment never holds a list of ids, so it cannot go stale and cannot carry another tenant's rows.
 */
class SegmentController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $rows = DB::table('gtm_segments')->where('sub_institute_id', $t)->whereNull('deleted_at')->orderByDesc('id')->get();
        foreach ($rows as $r) {
            $r->criteria = json_decode($r->criteria, true);
            $r->match_count = $this->query($t, $r->kind, $r->criteria)->count();
        }

        return response()->json(['status' => 1, 'data' => ['items' => $rows, 'filters' => $this->filterCatalogue()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        [$data, $err] = $this->validated($request, true);
        if ($err) {
            return $err;
        }
        $id = DB::table('gtm_segments')->insertGetId($data + ['sub_institute_id' => $t, 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        GtmAudit::record('gtm.segment.created', $t, 'gtm_segments', $id, $identity['user_id'], ['name' => $data['name'], 'kind' => $data['kind']]);

        return response()->json(['status' => 1, 'data' => ['segment' => $this->present($t, $id)]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $seg = DB::table('gtm_segments')->where('sub_institute_id', $t)->whereNull('deleted_at')->find($id);
        if (! $seg) {
            return response()->json(['status' => 0, 'message' => 'Segment not found'], 404);
        }
        [$data, $err] = $this->validated($request, false, $seg->kind);
        if ($err) {
            return $err;
        }
        DB::table('gtm_segments')->where('id', $id)->where('sub_institute_id', $t)->update($data + ['updated_at' => now()]);
        GtmAudit::record('gtm.segment.updated', $t, 'gtm_segments', $id, $identity['user_id'], ['changed' => array_keys($data)]);

        return response()->json(['status' => 1, 'data' => ['segment' => $this->present($t, $id)]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $n = DB::table('gtm_segments')->where('sub_institute_id', $t)->whereNull('deleted_at')->where('id', $id)->update(['deleted_at' => now()]);
        if ($n === 0) {
            return response()->json(['status' => 0, 'message' => 'Segment not found'], 404);
        }
        GtmAudit::record('gtm.segment.deleted', $t, 'gtm_segments', $id, $identity['user_id']);

        return response()->json(['status' => 1, 'message' => 'Segment removed']);
    }

    /** Who matches right now: a count and the first 50, plus how many of the contacts could actually be emailed. */
    public function preview(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $seg = DB::table('gtm_segments')->where('sub_institute_id', $t)->whereNull('deleted_at')->find($id);
        if (! $seg) {
            return response()->json(['status' => 0, 'message' => 'Segment not found'], 404);
        }
        $c = json_decode($seg->criteria, true) ?: [];
        $q = $this->query($t, $seg->kind, $c);
        $total = (clone $q)->count();
        $extra = [];
        if ($seg->kind === 'account') {
            $sample = $q->orderBy('a.id')->limit(50)->get(['a.id', 'a.name', 'a.stage', 'a.industry', 'a.location', 'a.icp_fit_score']);
        } else {
            $sample = $q->orderBy('c.id')->limit(50)->get(['c.id', 'c.full_name', 'c.title', 'c.email', 'c.role_in_deal', 'c.status', 'a.name as account_name']);
            $suppressed = $this->suppressedEmails($t);
            $extra['without_email'] = (clone $this->query($t, 'contact', $c))->where(fn ($w) => $w->whereNull('c.email')->orWhere('c.email', ''))->count();
            $extra['suppressed'] = $sample->filter(fn ($r) => $r->email && in_array(mb_strtolower($r->email), $suppressed, true))->count();
        }

        return response()->json(['status' => 1, 'data' => ['segment' => $this->present($t, $id), 'total' => $total, 'sample' => $sample] + $extra]);
    }

    /** @return array{0: ?array<string, mixed>, 1: ?JsonResponse} */
    private function validated(Request $request, bool $creating, ?string $kind = null): array
    {
        $v = Validator::make($request->all(), [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:191', 'kind' => $creating ? 'required|in:account,contact' : 'prohibited',
            'description' => 'nullable|string|max:1000', 'criteria' => ($creating ? 'required' : 'sometimes').'|array',
        ]);
        if ($v->fails()) {
            return [null, response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422)];
        }
        $data = $v->validated();
        $kind ??= $data['kind'];
        if (array_key_exists('criteria', $data)) {
            $r = SegmentCriteria::validate($kind, (array) $data['criteria']);
            if ($r['errors'] !== []) {
                return [null, response()->json(['status' => 0, 'message' => 'Invalid criteria', 'errors' => ['criteria' => $r['errors']]], 422)];
            }
            $data['criteria'] = json_encode($r['criteria']);
        }

        return [$data, null];
    }

    /** @param array<string, mixed> $criteria */
    private function query(int $t, string $kind, array $criteria)
    {
        return $kind === 'contact' ? SegmentCriteria::contacts($t, $criteria) : SegmentCriteria::accounts($t, $criteria);
    }

    /** @return object */
    private function present(int $t, int $id)
    {
        $r = DB::table('gtm_segments')->where('sub_institute_id', $t)->find($id);
        $r->criteria = json_decode($r->criteria, true);

        return $r;
    }

    /** @return list<string> lower-cased suppressed email addresses for a tenant */
    private function suppressedEmails(int $t): array
    {
        return DB::table('gtm_suppressions')->where('sub_institute_id', $t)->where('kind', 'email')->pluck('value')->map(fn ($v) => mb_strtolower($v))->all();
    }

    /** The filters each kind supports, so the screen builds its form from the server's own rules. @return array<string, mixed> */
    private function filterCatalogue(): array
    {
        return [
            'account' => ['stages' => SegmentCriteria::ACCOUNT_STAGES, 'sources' => SegmentCriteria::SOURCES, 'priorities' => SegmentCriteria::PRIORITIES],
            'contact' => ['roles' => SegmentCriteria::CONTACT_ROLES, 'statuses' => SegmentCriteria::CONTACT_STATUSES, 'account_stages' => SegmentCriteria::ACCOUNT_STAGES],
        ];
    }
}
