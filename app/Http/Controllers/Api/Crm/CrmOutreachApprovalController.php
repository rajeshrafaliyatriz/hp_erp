<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Outreach Approval Queue — Phase 1 CRM Automation.
 *
 * Provides the human-in-the-loop gate required by the Revenue Operating System
 * spec: before a campaign message reaches a target, a row is created here with
 * status=pending, reviewed by a named user, and only then dispatched via
 * markSent().
 *
 * Raw DB::table() throughout, tenant-scoped by sub_institute_id, matching
 * every other CRM controller in this namespace.
 */
class CrmOutreachApprovalController extends Controller
{
    use ResolvesApiIdentity;

    private const VALID_STATUSES = ['pending', 'approved', 'rejected', 'sent'];
    private const VALID_CHANNELS = ['email', 'whatsapp', 'sms', 'phone'];
    private const VALID_TARGET_TYPES = ['lead', 'contact', 'organization'];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page    = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_outreach_approvals')
            ->where('sub_institute_id', $identity['sub_institute_id']);

        if ($status = $request->input('status')) {
            if (in_array($status, self::VALID_STATUSES, true)) {
                $query->where('status', $status);
            }
        } else {
            // Default to pending queue view
            $query->where('status', 'pending');
        }

        if ($campaignId = $request->input('campaign_id')) {
            $query->where('campaign_id', (int) $campaignId);
        }

        $total = (clone $query)->count();
        $rows  = $query->orderBy('created_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'status'  => 1,
            'message' => 'Outreach approvals.',
            'data'    => [
                'items'      => $rows->map(fn ($row) => $this->resource($row))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => (int) max(1, ceil($total / $perPage)),
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'campaign_id'  => 'required|integer|min:1',
            'body'         => 'required|string',
            'target_type'  => 'required|in:' . implode(',', self::VALID_TARGET_TYPES),
            'target_id'    => 'required|integer|min:1',
            'subject'      => 'nullable|string|max:500',
            'channel'      => 'nullable|in:' . implode(',', self::VALID_CHANNELS),
            'target_row_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        // Verify the campaign belongs to this tenant
        $campaign = DB::table('crm_campaigns')
            ->where('id', $request->integer('campaign_id'))
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first(['id']);

        if (! $campaign) {
            return response()->json(['status' => 0, 'message' => 'Campaign not found.'], 404);
        }

        $id = DB::table('crm_outreach_approvals')->insertGetId([
            'campaign_id'   => $request->integer('campaign_id'),
            'target_row_id' => $request->integer('target_row_id') ?: null,
            'target_type'   => $request->input('target_type'),
            'target_id'     => $request->integer('target_id'),
            'subject'       => $request->input('subject'),
            'body'          => $request->input('body'),
            'channel'       => $request->input('channel', 'email'),
            'status'        => 'pending',
            'requested_by'  => $identity['user_id'],
            'sub_institute_id' => $identity['sub_institute_id'],
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $row = DB::table('crm_outreach_approvals')->where('id', $id)->first();

        return response()->json([
            'status'  => 1,
            'message' => 'Approval request created.',
            'data'    => $this->resource($row),
        ], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = DB::table('crm_outreach_approvals')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Approval not found.'], 404);
        }

        if ($row->status !== 'pending') {
            return response()->json(['status' => 0, 'message' => 'Only pending approvals can be approved.'], 422);
        }

        DB::table('crm_outreach_approvals')->where('id', $id)->update([
            'status'      => 'approved',
            'reviewed_by' => $identity['user_id'],
            'reviewed_at' => now(),
            'updated_at'  => now(),
        ]);

        $updated = DB::table('crm_outreach_approvals')->where('id', $id)->first();

        return response()->json([
            'status'  => 1,
            'message' => 'Approval approved.',
            'data'    => $this->resource($updated),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $row = DB::table('crm_outreach_approvals')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Approval not found.'], 404);
        }

        if ($row->status !== 'pending') {
            return response()->json(['status' => 0, 'message' => 'Only pending approvals can be rejected.'], 422);
        }

        DB::table('crm_outreach_approvals')->where('id', $id)->update([
            'status'           => 'rejected',
            'reviewed_by'      => $identity['user_id'],
            'reviewed_at'      => now(),
            'rejection_reason' => $request->input('rejection_reason'),
            'updated_at'       => now(),
        ]);

        $updated = DB::table('crm_outreach_approvals')->where('id', $id)->first();

        return response()->json([
            'status'  => 1,
            'message' => 'Approval rejected.',
            'data'    => $this->resource($updated),
        ]);
    }

    public function markSent(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = DB::table('crm_outreach_approvals')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Approval not found.'], 404);
        }

        if ($row->status !== 'approved') {
            return response()->json(['status' => 0, 'message' => 'Only approved items can be marked as sent.'], 422);
        }

        $sentAt = now();

        DB::table('crm_outreach_approvals')->where('id', $id)->update([
            'status'     => 'sent',
            'sent_at'    => $sentAt,
            'updated_at' => $sentAt,
        ]);

        // Update the campaign target's sent_at timestamp if the target row is linked
        if ($row->target_row_id) {
            DB::table('crm_campaign_targets')
                ->where('id', $row->target_row_id)
                ->whereNull('sent_at')
                ->update(['sent_at' => $sentAt]);
        }

        $updated = DB::table('crm_outreach_approvals')->where('id', $id)->first();

        return response()->json([
            'status'  => 1,
            'message' => 'Marked as sent.',
            'data'    => $this->resource($updated),
        ]);
    }

    private function resource(object $row): array
    {
        return [
            'id'              => (string) $row->id,
            'campaignId'      => (string) $row->campaign_id,
            'targetRowId'     => $row->target_row_id ? (string) $row->target_row_id : null,
            'targetType'      => $row->target_type,
            'targetId'        => $row->target_id ? (string) $row->target_id : null,
            'subject'         => $row->subject,
            'body'            => $row->body,
            'channel'         => $row->channel,
            'status'          => $row->status,
            'requestedBy'     => $row->requested_by ? (string) $row->requested_by : null,
            'reviewedBy'      => $row->reviewed_by ? (string) $row->reviewed_by : null,
            'reviewedAt'      => $row->reviewed_at,
            'rejectionReason' => $row->rejection_reason,
            'sentAt'          => $row->sent_at,
            'createdAt'       => $row->created_at,
            'updatedAt'       => $row->updated_at,
        ];
    }
}
