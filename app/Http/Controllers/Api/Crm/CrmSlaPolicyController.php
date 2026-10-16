<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Support\TicketSlaCalculator;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sales & Marketing gap-closure, Wave 4: admin CRUD for `crm_sla_policies` -
 * one row per tenant per ticket priority. Lives on the Master Fields
 * console as a fifth tab, same precedent every prior admin-config screen in
 * this effort already set (Tax Rates, Lead Scoring & Assignment, Web-to-Lead).
 *
 * Gated on the Tickets right, not Leads' `menuright:201` - this is Support
 * config, not Marketing config.
 */
class CrmSlaPolicyController extends Controller
{
    use ResolvesApiIdentity;

    private const TICKETS_LINK = '/module/crm/support/tickets';

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('crm_sla_policies as p')
            ->leftJoin('tbluser as u', 'u.id', '=', 'p.escalate_to_user_id')
            ->where('p.sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('p.priority')
            ->select('p.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as escalate_to_name"))
            ->get();
        $priorities = DB::table('crm_picklist_values')->where('module', 'tickets')->where('field_key', 'priority')->orderBy('sort_order')->pluck('value')->all();

        return response()->json([
            'status' => 1,
            'message' => 'SLA policies.',
            'data' => ['policies' => $rows->map(fn ($row) => $this->resource($row))->all(), 'priorities' => $priorities],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'priority' => 'required|string|max:50',
            'responseHours' => 'required|numeric|min:0.25|max:8760',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = $identity['sub_institute_id'];
        $existing = DB::table('crm_sla_policies')->where('sub_institute_id', $tenantId)->where('priority', $request->input('priority'))->first();

        if ($existing) {
            DB::table('crm_sla_policies')->where('id', $existing->id)->update(['response_hours' => $request->input('responseHours'), 'updated_at' => now()]);
            $id = $existing->id;
        } else {
            $id = DB::table('crm_sla_policies')->insertGetId([
                'priority' => $request->input('priority'), 'response_hours' => $request->input('responseHours'),
                'created_by' => $identity['user_id'], 'sub_institute_id' => $tenantId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $row = DB::table('crm_sla_policies')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'SLA policy saved.', 'data' => $this->resource($row)], $existing ? 200 : 201);
    }

    /**
     * Sales & Marketing gap-closure, Wave 6. Deliberately a separate action
     * from store() - that one upserts by priority and requires responseHours
     * on every call; escalation settings are edited on an EXISTING policy
     * row by id, and must never be able to clobber its responseHours by
     * omission.
     */
    public function updateEscalation(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $policy = DB::table('crm_sla_policies')->where('id', $id)->where('sub_institute_id', $tenantId)->first();

        if (! $policy) {
            return response()->json(['status' => 0, 'message' => 'SLA policy not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'escalateToUserId' => 'nullable|integer',
            'autoReassignOnBreach' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $escalateToUserId = $request->filled('escalateToUserId') ? (int) $request->input('escalateToUserId') : null;

        if ($escalateToUserId !== null) {
            $validEmployee = DB::table('tbluser')->where('id', $escalateToUserId)->where('sub_institute_id', $tenantId)->exists();

            if (! $validEmployee) {
                return response()->json(['status' => 0, 'message' => 'Choose a real employee in this organisation to escalate to.'], 422);
            }
        }

        // Same "never ship a capability with nowhere for its output to go"
        // rule as Web-to-Lead's default-assignee requirement: reassigning a
        // ticket automatically needs someone to reassign it TO.
        if ($request->boolean('autoReassignOnBreach') && $escalateToUserId === null) {
            return response()->json(['status' => 0, 'message' => 'Choose who to escalate to before turning on auto-reassignment.'], 422);
        }

        DB::table('crm_sla_policies')->where('id', $id)->update([
            'escalate_to_user_id' => $escalateToUserId,
            'auto_reassign_on_breach' => $request->boolean('autoReassignOnBreach'),
            'updated_at' => now(),
        ]);

        $row = DB::table('crm_sla_policies as p')
            ->leftJoin('tbluser as u', 'u.id', '=', 'p.escalate_to_user_id')
            ->where('p.id', $id)
            ->select('p.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as escalate_to_name"))
            ->first();

        return response()->json(['status' => 1, 'message' => 'Escalation settings saved.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $deleted = DB::table('crm_sla_policies')->where('id', $id)->where('sub_institute_id', $identity['sub_institute_id'])->delete();

        if (! $deleted) {
            return response()->json(['status' => 0, 'message' => 'SLA policy not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'SLA policy removed - that priority now has no SLA.']);
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'priority' => $row->priority,
            'responseHours' => (float) $row->response_hours,
            'escalateToUserId' => ! empty($row->escalate_to_user_id) ? (string) $row->escalate_to_user_id : null,
            'escalateToName' => ($row->escalate_to_name ?? '') !== '' ? $row->escalate_to_name : null,
            'autoReassignOnBreach' => (bool) ($row->auto_reassign_on_breach ?? false),
        ];
    }
}
