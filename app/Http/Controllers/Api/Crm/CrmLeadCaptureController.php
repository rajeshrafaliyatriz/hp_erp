<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Sales & Marketing gap-closure, Wave 3: the ADMIN, authenticated side of
 * public web-to-lead capture - manage the one settings row per tenant.
 * The anonymous-visitor side (actually submitting the form) is a
 * deliberately separate controller, PublicLeadCaptureController, carrying
 * none of this controller's `resolveApiIdentity()`/`menuright` gating - two
 * trust boundaries never share one class, so a middleware mistake on one
 * route can't silently expose the other.
 *
 * Gated on the same `menuright:201` every other Lead route already uses.
 */
class CrmLeadCaptureController extends Controller
{
    use ResolvesApiIdentity;

    public function settings(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRow($identity['sub_institute_id'], createIfMissing: true);

        return response()->json(['status' => 1, 'message' => 'Lead capture settings.', 'data' => $this->resource($identity['sub_institute_id'], $row)]);
    }

    /**
     * `isEnabled: true` requires `defaultAssignedTo` to already be set (on this request or a
     * prior one) - crm_leads.assigned_to is NOT NULL, and a public submission with no
     * round-robin rotation configured (App\Domain\Crm\Leads\LeadAssigner) has nowhere else to
     * land. Same "never let a new capability ship with nowhere for its output to go" rule
     * this effort already applied to Invoice payments and Opportunity close reasons.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'isEnabled' => 'sometimes|required|boolean',
            'defaultLeadSource' => 'sometimes|required|string|max:191',
            'defaultAssignedTo' => 'sometimes|nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $tenantId = $identity['sub_institute_id'];
        $row = $this->ownRow($tenantId, createIfMissing: true);
        $data = ['updated_at' => now()];

        if ($request->has('defaultLeadSource')) {
            $data['default_lead_source'] = $request->input('defaultLeadSource');
        }

        if ($request->has('defaultAssignedTo')) {
            $assignedTo = $request->filled('defaultAssignedTo') ? (int) $request->input('defaultAssignedTo') : null;

            if ($assignedTo !== null) {
                $userExists = DB::table('tbluser')->where('id', $assignedTo)->where('sub_institute_id', $tenantId)->exists();

                if (! $userExists) {
                    return response()->json(['status' => 0, 'message' => 'That user was not found.'], 422);
                }
            }

            $data['default_assigned_to'] = $assignedTo;
        }

        if ($request->has('isEnabled')) {
            $willBeEnabled = $request->boolean('isEnabled');
            $willHaveAssignee = array_key_exists('default_assigned_to', $data) ? $data['default_assigned_to'] !== null : $row->default_assigned_to !== null;

            if ($willBeEnabled && ! $willHaveAssignee) {
                return response()->json(['status' => 0, 'message' => 'Choose a default assignee before turning this on - every submission needs somewhere to land.'], 422);
            }

            $data['is_enabled'] = $willBeEnabled;
        }

        DB::table('crm_lead_capture_settings')->where('id', $row->id)->update($data);

        $row = $this->ownRow($tenantId);

        return response()->json(['status' => 1, 'message' => $request->has('isEnabled') ? ($request->boolean('isEnabled') ? 'Web-to-lead turned on.' : 'Web-to-lead turned off.') : 'Settings updated.', 'data' => $this->resource($tenantId, $row)]);
    }

    /** Invalidates the old public URL immediately - anyone with the old link gets a 404 from here on. */
    public function regenerateKey(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $row = $this->ownRow($tenantId, createIfMissing: true);

        DB::table('crm_lead_capture_settings')->where('id', $row->id)->update(['public_key' => self::newPublicKey(), 'updated_at' => now()]);

        $row = $this->ownRow($tenantId);

        return response()->json(['status' => 1, 'message' => 'Form link regenerated - the old link no longer works.', 'data' => $this->resource($tenantId, $row)]);
    }

    private function ownRow(int $tenantId, bool $createIfMissing = false): ?object
    {
        $row = DB::table('crm_lead_capture_settings')->where('sub_institute_id', $tenantId)->first();

        if (! $row && $createIfMissing) {
            DB::table('crm_lead_capture_settings')->insert([
                'sub_institute_id' => $tenantId, 'public_key' => self::newPublicKey(), 'is_enabled' => false,
                'default_lead_source' => 'Website', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('crm_lead_capture_settings')->where('sub_institute_id', $tenantId)->first();
        }

        return $row;
    }

    private static function newPublicKey(): string
    {
        return Str::random(40);
    }

    /** @return array<string, mixed> */
    private function resource(int $tenantId, ?object $row): array
    {
        if (! $row) {
            return ['isEnabled' => false, 'publicKey' => null, 'defaultLeadSource' => 'Website', 'defaultAssignedTo' => null, 'submissionsCount' => 0, 'lastSubmissionAt' => null];
        }

        return [
            'isEnabled' => (bool) $row->is_enabled,
            'publicKey' => $row->public_key,
            'defaultLeadSource' => $row->default_lead_source,
            'defaultAssignedTo' => $row->default_assigned_to ? (string) $row->default_assigned_to : null,
            'submissionsCount' => (int) $row->submissions_count,
            'lastSubmissionAt' => $row->last_submission_at,
        ];
    }
}
