<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Leads\LeadScorer;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sales & Marketing gap-closure, Wave 2: the admin screen behind automated
 * lead scoring and round-robin assignment. Lives on the Master Fields
 * console as a third tab, same precedent as Tax Rates - gated on the same
 * `menuright:201` the rest of Leads already uses (not a new, inconsistent
 * gate), because this config is Lead-specific, not CRM-wide.
 *
 * Two independent concerns, kept in one controller because they are both
 * small and both scoped to "how a new lead gets routed":
 *  - Scoring rules: plain CRUD over `crm_lead_scoring_rules`.
 *  - Assignment: one settings row per tenant (`crm_lead_assignment_settings`,
 *    auto-created on first touch) + an ordered member list
 *    (`crm_lead_assignment_rotation_members`). No reorder endpoint -
 *    members are tried in the order they were added (`sort_order`, an
 *    auto-incrementing counter); removing and re-adding someone moves them
 *    to the end, which is the only reordering this needs.
 */
class CrmLeadRoutingController extends Controller
{
    use ResolvesApiIdentity;

    // ── Scoring rules ────────────────────────────────────────────────────

    public function scoringRules(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('crm_lead_scoring_rules')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Lead scoring rules.',
            'data' => [
                'rules' => $rows->map(fn ($row) => $this->ruleResource($row))->all(),
                'fields' => LeadScorer::ALLOWED_FIELDS,
                'operators' => LeadScorer::OPERATORS,
                'numericOperators' => LeadScorer::NUMERIC_OPERATORS,
            ],
        ]);
    }

    public function storeScoringRule(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'fieldKey' => 'required|string|in:'.implode(',', LeadScorer::ALLOWED_FIELDS),
            'operator' => 'required|string|in:'.implode(',', LeadScorer::OPERATORS),
            'value' => ['nullable', 'string', 'max:191', function ($attr, $val, $fail) use ($request) {
                if ($request->input('operator') !== 'is_not_empty' && ($val === null || trim((string) $val) === '')) {
                    $fail('A value is required for this operator.');
                }
            }],
            'points' => 'required|integer|min:-1000|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if (in_array($request->input('operator'), LeadScorer::NUMERIC_OPERATORS, true) && ! is_numeric($request->input('value'))) {
            return response()->json(['status' => 0, 'message' => 'This operator needs a numeric value.'], 422);
        }

        $nextOrder = (int) DB::table('crm_lead_scoring_rules')->where('sub_institute_id', $identity['sub_institute_id'])->max('sort_order') + 1;

        $id = DB::table('crm_lead_scoring_rules')->insertGetId([
            'field_key' => $request->input('fieldKey'),
            'operator' => $request->input('operator'),
            'value' => $request->input('operator') === 'is_not_empty' ? null : $request->input('value'),
            'points' => (int) $request->input('points'),
            'is_active' => true,
            'sort_order' => $nextOrder,
            'created_by' => $identity['user_id'],
            'sub_institute_id' => $identity['sub_institute_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('crm_lead_scoring_rules')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Scoring rule added.', 'data' => $this->ruleResource($row)], 201);
    }

    public function updateScoringRule(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = DB::table('crm_lead_scoring_rules')->where('id', $id)->where('sub_institute_id', $identity['sub_institute_id'])->first();

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Scoring rule not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'points' => 'sometimes|required|integer|min:-1000|max:1000',
            'isActive' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = ['updated_at' => now()];

        if ($request->has('points')) {
            $data['points'] = (int) $request->input('points');
        }
        if ($request->has('isActive')) {
            $data['is_active'] = $request->boolean('isActive');
        }

        DB::table('crm_lead_scoring_rules')->where('id', $id)->update($data);

        $row = DB::table('crm_lead_scoring_rules')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Scoring rule updated.', 'data' => $this->ruleResource($row)]);
    }

    public function destroyScoringRule(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $deleted = DB::table('crm_lead_scoring_rules')->where('id', $id)->where('sub_institute_id', $identity['sub_institute_id'])->delete();

        if (! $deleted) {
            return response()->json(['status' => 0, 'message' => 'Scoring rule not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Scoring rule removed.']);
    }

    // ── Assignment rotation ──────────────────────────────────────────────

    public function assignmentSettings(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $settings = $this->ownSettingsRow($identity['sub_institute_id']);
        $members = DB::table('crm_lead_assignment_rotation_members as m')
            ->leftJoin('tbluser as u', 'u.id', '=', 'm.user_id')
            ->where('m.sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('m.sort_order')
            ->select('m.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as user_name"))
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Lead assignment settings.',
            'data' => [
                'isEnabled' => (bool) ($settings->is_enabled ?? false),
                'lastAssignedUserId' => $settings && $settings->last_assigned_user_id ? (string) $settings->last_assigned_user_id : null,
                'members' => $members->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'userId' => (string) $row->user_id,
                    'userName' => ($row->user_name ?? '') !== '' ? $row->user_name : "User #{$row->user_id}",
                    'isActive' => (bool) $row->is_active,
                ])->all(),
            ],
        ]);
    }

    public function updateAssignmentSettings(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), ['isEnabled' => 'required|boolean']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = $identity['sub_institute_id'];
        $isEnabled = $request->boolean('isEnabled');

        if ($isEnabled) {
            $hasActiveMember = DB::table('crm_lead_assignment_rotation_members')->where('sub_institute_id', $tenantId)->where('is_active', true)->exists();

            if (! $hasActiveMember) {
                return response()->json(['status' => 0, 'message' => 'Add at least one person to the rotation before turning this on.'], 422);
            }
        }

        $this->ownSettingsRow($tenantId, createIfMissing: true);
        DB::table('crm_lead_assignment_settings')->where('sub_institute_id', $tenantId)->update(['is_enabled' => $isEnabled, 'updated_at' => now()]);

        return response()->json(['status' => 1, 'message' => $isEnabled ? 'Auto-assignment turned on.' : 'Auto-assignment turned off.']);
    }

    public function addRotationMember(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), ['userId' => 'required|integer']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = $identity['sub_institute_id'];
        $userId = (int) $request->input('userId');

        $userExists = DB::table('tbluser')->where('id', $userId)->where('sub_institute_id', $tenantId)->exists();

        if (! $userExists) {
            return response()->json(['status' => 0, 'message' => 'That user was not found.'], 422);
        }

        $existing = DB::table('crm_lead_assignment_rotation_members')->where('sub_institute_id', $tenantId)->where('user_id', $userId)->first();

        if ($existing) {
            // Already in the rotation - reactivating is an update(), not a second insert
            // (the unique(tenant, user) constraint would reject a duplicate anyway).
            DB::table('crm_lead_assignment_rotation_members')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => now()]);
        } else {
            $nextOrder = (int) DB::table('crm_lead_assignment_rotation_members')->where('sub_institute_id', $tenantId)->max('sort_order') + 1;
            DB::table('crm_lead_assignment_rotation_members')->insert([
                'sub_institute_id' => $tenantId, 'user_id' => $userId, 'sort_order' => $nextOrder,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['status' => 1, 'message' => 'Added to the rotation.']);
    }

    public function removeRotationMember(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $deleted = DB::table('crm_lead_assignment_rotation_members')->where('id', $id)->where('sub_institute_id', $identity['sub_institute_id'])->delete();

        if (! $deleted) {
            return response()->json(['status' => 0, 'message' => 'Rotation member not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Removed from the rotation.']);
    }

    private function ownSettingsRow(int $tenantId, bool $createIfMissing = false): ?object
    {
        $row = DB::table('crm_lead_assignment_settings')->where('sub_institute_id', $tenantId)->first();

        if (! $row && $createIfMissing) {
            DB::table('crm_lead_assignment_settings')->insert(['sub_institute_id' => $tenantId, 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('crm_lead_assignment_settings')->where('sub_institute_id', $tenantId)->first();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function ruleResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'fieldKey' => $row->field_key,
            'operator' => $row->operator,
            'value' => $row->value,
            'points' => (int) $row->points,
            'isActive' => (bool) $row->is_active,
        ];
    }
}
