<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Campaigns — the legacy CRM's Marketing module, last of the three target
 * types (Leads/Contacts/Organizations already exist; Campaigns links to all
 * three via one polymorphic pivot, `crm_campaign_targets`).
 *
 * Raw `DB::table()` query builder throughout, tenant-scoped by
 * `sub_institute_id` only, matching the Leads/Contacts/Organizations
 * controllers this one is modeled on.
 */
class CrmCampaignController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;

    /** @var array<string, string> */
    private const TARGET_TABLES = [
        'lead' => 'crm_leads',
        'contact' => 'crm_contacts',
        'organization' => 'crm_organizations',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_campaigns')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($campaignStatus = $request->input('campaign_status')) {
            $query->where('campaign_status', $campaignStatus);
        }

        $sortableColumns = ['name', 'campaign_type', 'campaign_status', 'closing_date', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Campaigns.',
            'data' => [
                'items' => $rows->map(fn ($row) => $this->resource($row))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Campaign.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), $this->validationRules());

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request);
        $data['campaign_no'] = 'CAM-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_campaigns')->insertGetId($data);
        $row = DB::table('crm_campaigns')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Campaign created.', 'data' => $this->resource($row)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), $this->validationRules(forUpdate: true));

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_campaigns')->where('id', $id)->update($data);
        $row = DB::table('crm_campaigns')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Campaign updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        DB::table('crm_campaigns')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        return response()->json(['status' => 1, 'message' => 'Campaign moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkDeleteRows($request, 'crm_campaigns', $identity['sub_institute_id'], $identity['user_id'], 'Campaign');
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->bulkAssignRows($request, 'crm_campaigns', $identity['sub_institute_id'], $identity['user_id'], 'Campaign');
    }

    /**
     * Add one target (Lead/Contact/Organization) to this campaign.
     *
     * Idempotent: re-adding the same (campaign, type, id) is a no-op, never a
     * 500 from the `crm_campaign_targets_unique` constraint — a caller
     * re-submitting a bulk-add or double-clicking "Add" must see success, not
     * an error for something that already happened.
     */
    public function addTarget(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'targetType' => 'required|in:lead,contact,organization',
            'targetId' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $targetType = $request->input('targetType');
        $targetId = (int) $request->input('targetId');

        if (! $this->targetExists($identity['sub_institute_id'], $targetType, $targetId)) {
            return response()->json(['status' => 0, 'message' => ucfirst($targetType) . ' not found.'], 422);
        }

        $this->insertTargetIdempotent($id, $targetType, $targetId);

        return response()->json([
            'status' => 1,
            'message' => 'Target added.',
            'data' => $this->targetsPayload($identity['sub_institute_id'], $id),
        ]);
    }

    public function removeTarget(Request $request, int $id, int $targetRowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        DB::table('crm_campaign_targets')
            ->where('id', $targetRowId)
            ->where('campaign_id', $id)
            ->delete();

        return response()->json(['status' => 1, 'message' => 'Target removed.']);
    }

    public function updateTargetStatus(Request $request, int $id, int $targetRowId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'responseStatus' => 'required|in:none,contacted_successful,contacted_unsuccessful,contacted_never_again',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        DB::table('crm_campaign_targets')
            ->where('id', $targetRowId)
            ->where('campaign_id', $id)
            ->update(['response_status' => $request->input('responseStatus')]);

        return response()->json(['status' => 1, 'message' => 'Target status updated.']);
    }

    public function getTargets(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        return response()->json([
            'status' => 1,
            'message' => 'Campaign targets.',
            'data' => $this->targetsPayload($identity['sub_institute_id'], $id),
        ]);
    }

    /**
     * Add every Lead/Contact/Organization matching a search term as a
     * target, in one batch. Reuses the same idempotent-insert path as
     * addTarget() per row, so re-running the same search is always safe.
     */
    public function bulkAddFromSearch(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'targetType' => 'required|in:lead,contact,organization',
            'search' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $targetType = $request->input('targetType');
        $search = trim((string) $request->input('search', ''));

        $added = 0;
        $alreadyPresent = 0;

        foreach ($this->searchTargetIds($identity['sub_institute_id'], $targetType, $search) as $targetId) {
            if ($this->insertTargetIdempotent($id, $targetType, $targetId)) {
                $added++;
            } else {
                $alreadyPresent++;
            }
        }

        return response()->json([
            'status' => 1,
            'message' => "{$added} target(s) added, {$alreadyPresent} already present.",
            'data' => [
                'added' => $added,
                'alreadyPresent' => $alreadyPresent,
            ],
        ]);
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_campaigns')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    private function targetExists(int $tenantId, string $targetType, int $targetId): bool
    {
        $table = self::TARGET_TABLES[$targetType];

        return DB::table($table)
            ->where('id', $targetId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Insert one (campaign_id, target_type, target_id) row if it is not
     * already there. Returns true when a new row was inserted, false when it
     * was already present (whether found up front, or discovered only via
     * the unique-constraint violation of a concurrent duplicate insert).
     */
    private function insertTargetIdempotent(int $campaignId, string $targetType, int $targetId): bool
    {
        $exists = DB::table('crm_campaign_targets')
            ->where('campaign_id', $campaignId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            DB::table('crm_campaign_targets')->insert([
                'campaign_id' => $campaignId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'response_status' => 'none',
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // 23000 = integrity constraint violation. Another request won the
            // race between our existence check above and this insert - that
            // is the same "already present" outcome, never a 500.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /** @return array<int, int> */
    private function searchTargetIds(int $tenantId, string $targetType, string $search): array
    {
        $table = self::TARGET_TABLES[$targetType];

        $query = DB::table($table)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at');

        if ($search !== '') {
            if ($targetType === 'lead') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('company', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            } elseif ($targetType === 'contact') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            } else {
                $query->where('name', 'like', "%{$search}%");
            }
        }

        return $query->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Every target row for this campaign, grouped by type and enriched with
     * the target's own display name - a broken reference (the underlying
     * Lead/Contact/Organization since soft-deleted) is filtered out rather
     * than shown blank.
     *
     * @return array{leads: array<int, array<string, mixed>>, contacts: array<int, array<string, mixed>>, organizations: array<int, array<string, mixed>>}
     */
    private function targetsPayload(int $tenantId, int $campaignId): array
    {
        $leads = DB::table('crm_campaign_targets as t')
            ->join('crm_leads as l', 'l.id', '=', 't.target_id')
            ->where('t.campaign_id', $campaignId)
            ->where('t.target_type', 'lead')
            ->where('l.sub_institute_id', $tenantId)
            ->whereNull('l.deleted_at')
            ->orderBy('t.id')
            ->get(['t.id as target_row_id', 't.target_id', 't.response_status', 'l.first_name', 'l.last_name', 'l.company'])
            ->map(fn ($row) => [
                'targetRowId' => (string) $row->target_row_id,
                'targetId' => (string) $row->target_id,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'subLabel' => $row->company,
                'responseStatus' => $row->response_status,
            ])
            ->all();

        $contacts = DB::table('crm_campaign_targets as t')
            ->join('crm_contacts as c', 'c.id', '=', 't.target_id')
            ->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('t.campaign_id', $campaignId)
            ->where('t.target_type', 'contact')
            ->where('c.sub_institute_id', $tenantId)
            ->whereNull('c.deleted_at')
            ->orderBy('t.id')
            ->get(['t.id as target_row_id', 't.target_id', 't.response_status', 'c.first_name', 'c.last_name', 'o.name as organization_name'])
            ->map(fn ($row) => [
                'targetRowId' => (string) $row->target_row_id,
                'targetId' => (string) $row->target_id,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'subLabel' => $row->organization_name,
                'responseStatus' => $row->response_status,
            ])
            ->all();

        $organizations = DB::table('crm_campaign_targets as t')
            ->join('crm_organizations as o', 'o.id', '=', 't.target_id')
            ->where('t.campaign_id', $campaignId)
            ->where('t.target_type', 'organization')
            ->where('o.sub_institute_id', $tenantId)
            ->whereNull('o.deleted_at')
            ->orderBy('t.id')
            ->get(['t.id as target_row_id', 't.target_id', 't.response_status', 'o.name'])
            ->map(fn ($row) => [
                'targetRowId' => (string) $row->target_row_id,
                'targetId' => (string) $row->target_id,
                'name' => $row->name,
                'subLabel' => null,
                'responseStatus' => $row->response_status,
            ])
            ->all();

        return ['leads' => $leads, 'contacts' => $contacts, 'organizations' => $organizations];
    }

    /** @return array<string, string> */
    private function validationRules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes|required' : 'required';

        return [
            'name' => "{$required}|string|max:191",
            'assignedTo' => "{$required}|integer",
            'campaignType' => 'nullable|string|max:100',
            'campaignStatus' => 'nullable|string|max:100',
            'expectedRevenue' => 'nullable|numeric',
            'budgetCost' => 'nullable|numeric',
            'actualCost' => 'nullable|numeric',
            'expectedResponse' => 'nullable|string|max:50',
            'numSent' => 'nullable|integer',
            'sponsor' => 'nullable|string|max:191',
            'targetAudience' => 'nullable|string|max:191',
            'targetSize' => 'nullable|integer',
            'expectedResponseCount' => 'nullable|integer',
            'expectedSalesCount' => 'nullable|integer',
            'actualResponseCount' => 'nullable|integer',
            'actualSalesCount' => 'nullable|integer',
            'expectedRoi' => 'nullable|numeric',
            'actualRoi' => 'nullable|numeric',
            'closingDate' => 'nullable|date',
            'productId' => 'nullable|integer',
            'description' => 'nullable|string',
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'campaignType' => 'campaign_type', 'campaignStatus' => 'campaign_status',
            'expectedRevenue' => 'expected_revenue', 'budgetCost' => 'budget_cost', 'actualCost' => 'actual_cost',
            'expectedResponse' => 'expected_response', 'numSent' => 'num_sent', 'sponsor' => 'sponsor',
            'targetAudience' => 'target_audience', 'targetSize' => 'target_size',
            'expectedResponseCount' => 'expected_response_count', 'expectedSalesCount' => 'expected_sales_count',
            'actualResponseCount' => 'actual_response_count', 'actualSalesCount' => 'actual_sales_count',
            'expectedRoi' => 'expected_roi', 'actualRoi' => 'actual_roi', 'closingDate' => 'closing_date',
            'productId' => 'product_id', 'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('product_id', $data) && $data['product_id'] === '') {
            $data['product_id'] = null;
        }

        if (array_key_exists('closing_date', $data) && $data['closing_date'] === '') {
            $data['closing_date'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'campaignNo' => $row->campaign_no,
            'name' => $row->name,
            'campaignType' => $row->campaign_type,
            'campaignStatus' => $row->campaign_status,
            'expectedRevenue' => $row->expected_revenue !== null ? (float) $row->expected_revenue : null,
            'budgetCost' => $row->budget_cost !== null ? (float) $row->budget_cost : null,
            'actualCost' => $row->actual_cost !== null ? (float) $row->actual_cost : null,
            'expectedResponse' => $row->expected_response,
            'numSent' => $row->num_sent !== null ? (int) $row->num_sent : null,
            'sponsor' => $row->sponsor,
            'targetAudience' => $row->target_audience,
            'targetSize' => $row->target_size !== null ? (int) $row->target_size : null,
            'expectedResponseCount' => $row->expected_response_count !== null ? (int) $row->expected_response_count : null,
            'expectedSalesCount' => $row->expected_sales_count !== null ? (int) $row->expected_sales_count : null,
            'actualResponseCount' => $row->actual_response_count !== null ? (int) $row->actual_response_count : null,
            'actualSalesCount' => $row->actual_sales_count !== null ? (int) $row->actual_sales_count : null,
            'expectedRoi' => $row->expected_roi !== null ? (float) $row->expected_roi : null,
            'actualRoi' => $row->actual_roi !== null ? (float) $row->actual_roi : null,
            'closingDate' => $row->closing_date,
            'productId' => $row->product_id ? (string) $row->product_id : null,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
