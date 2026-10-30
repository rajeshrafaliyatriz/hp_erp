<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\ClassifyCrmSignalJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 2 — Social Listening Signal Inbox.
 *
 * Endpoints for ingesting, reviewing, assigning and promoting signals.
 * Raw DB::table() throughout, matching CrmLeadController convention.
 */
class CrmSignalInboxController extends Controller
{
    use ResolvesApiIdentity;

    private const VALID_CLASSIFICATIONS = [
        'unclassified', 'buying_signal', 'brand_mention',
        'competitor_move', 'industry_trend', 'irrelevant',
    ];

    private const VALID_STATUSES = ['pending', 'accepted', 'rejected', 'dismissed', 'promoted'];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_signal_inbox as si')
            ->leftJoin('crm_organizations as org', 'org.id', '=', 'si.linked_account_id')
            ->where('si.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('si.deleted_at')
            ->select([
                'si.*',
                'org.name as linked_account_name',
            ]);

        if ($status = $request->input('status')) {
            $query->where('si.status', $status);
        }

        if ($classification = $request->input('classification')) {
            $query->where('si.classification', $classification);
        }

        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('si.assigned_to', $assignedTo);
        }

        if ($from = $request->input('from')) {
            $query->where('si.published_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->where('si.published_at', '<=', $to);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('si.published_at', 'desc')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Signal inbox.',
            'data' => [
                'items' => $rows->map(fn ($r) => $this->resource($r))->all(),
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

        $row = DB::table('crm_signal_inbox as si')
            ->leftJoin('crm_organizations as org', 'org.id', '=', 'si.linked_account_id')
            ->where('si.id', $id)
            ->where('si.sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('si.deleted_at')
            ->select(['si.*', 'org.name as linked_account_name'])
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Signal not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Signal.', 'data' => $this->resource($row)]);
    }

    public function ingest(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'signals' => 'required|array|min:1|max:200',
            'signals.*.signal_key' => 'required|string|max:64',
            'signals.*.title' => 'required|string|max:500',
            'signals.*.source_name' => 'nullable|string|max:191',
            'signals.*.source_url' => 'nullable|string',
            'signals.*.body' => 'nullable|string',
            'signals.*.published_at' => 'nullable|date',
            'signals.*.classification' => 'nullable|in:' . implode(',', self::VALID_CLASSIFICATIONS),
            'signals.*.classification_confidence' => 'nullable|numeric|min:0|max:1',
            'signals.*.classification_reason' => 'nullable|string',
            'signals.*.relevance_score' => 'nullable|numeric|min:0|max:1',
            'signals.*.linked_account_id' => 'nullable|integer',
            'signals.*.linked_account_confidence' => 'nullable|numeric|min:0|max:1',
            'signals.*.tags' => 'nullable|array',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation error.', 'errors' => $v->errors()], 422);
        }

        $inserted = 0;
        $skipped = 0;
        $now = now();

        foreach ($request->input('signals') as $sig) {
            $key = $sig['signal_key'];

            if (DB::table('crm_signal_inbox')->where('signal_key', $key)->exists()) {
                $skipped++;
                continue;
            }

            DB::table('crm_signal_inbox')->insert([
                'signal_key' => $key,
                'source_name' => $sig['source_name'] ?? null,
                'source_url' => $sig['source_url'] ?? null,
                'title' => $sig['title'],
                'body' => $sig['body'] ?? null,
                'published_at' => isset($sig['published_at']) ? date('Y-m-d H:i:s', strtotime($sig['published_at'])) : null,
                'ingested_at' => $now,
                'classification' => $sig['classification'] ?? 'unclassified',
                'classification_confidence' => $sig['classification_confidence'] ?? null,
                'classification_reason' => $sig['classification_reason'] ?? null,
                'relevance_score' => $sig['relevance_score'] ?? null,
                'linked_account_id' => $sig['linked_account_id'] ?? null,
                'linked_account_confidence' => $sig['linked_account_confidence'] ?? null,
                'status' => 'pending',
                'tags' => isset($sig['tags']) ? json_encode($sig['tags']) : null,
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $inserted++;

            // Dispatch async AI classification for signals that arrived without one
            if (($sig['classification'] ?? 'unclassified') === 'unclassified') {
                $newId = DB::table('crm_signal_inbox')
                    ->where('signal_key', $key)
                    ->where('sub_institute_id', $identity['sub_institute_id'])
                    ->value('id');
                if ($newId) {
                    ClassifyCrmSignalJob::dispatch($identity['sub_institute_id'], (int) $newId);
                }
            }
        }

        return response()->json([
            'status' => 1,
            'message' => "Ingested {$inserted} signal(s), skipped {$skipped} duplicate(s).",
            'data' => ['inserted' => $inserted, 'skipped' => $skipped],
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'status' => 'required|in:accepted,rejected,dismissed',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation error.', 'errors' => $v->errors()], 422);
        }

        $row = DB::table('crm_signal_inbox')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Signal not found.'], 404);
        }

        DB::table('crm_signal_inbox')->where('id', $id)->update([
            'status' => $request->input('status'),
            'reviewed_by' => $identity['user_id'],
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Signal reviewed.']);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'assigned_to' => 'required|integer|min:1',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation error.', 'errors' => $v->errors()], 422);
        }

        $row = DB::table('crm_signal_inbox')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Signal not found.'], 404);
        }

        DB::table('crm_signal_inbox')->where('id', $id)->update([
            'assigned_to' => (int) $request->input('assigned_to'),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Signal assigned.']);
    }

    public function promote(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'promoted_to' => 'required|in:lead,opportunity',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation error.', 'errors' => $v->errors()], 422);
        }

        $row = DB::table('crm_signal_inbox')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Signal not found.'], 404);
        }

        if ($row->status === 'promoted') {
            return response()->json(['status' => 0, 'message' => 'Signal already promoted.'], 422);
        }

        $promotedTo = $request->input('promoted_to');
        $now = now();

        if ($promotedTo === 'lead') {
            // Resolve company name from linked account if available
            $company = null;
            if ($row->linked_account_id) {
                $company = DB::table('crm_organizations')->where('id', $row->linked_account_id)->value('name');
            }

            $promotedId = DB::table('crm_leads')->insertGetId([
                'last_name' => 'Signal Lead',
                'company' => $company ?? 'Unknown',
                'description' => $row->title . ($row->body ? "\n\n" . $row->body : ''),
                'lead_source' => 'signal_inbox',
                'lead_status' => 'New',
                'lifecycle_status' => 'new',
                'assigned_to' => $identity['user_id'],
                'converted' => false,
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            // opportunity
            $company = null;
            if ($row->linked_account_id) {
                $company = DB::table('crm_organizations')->where('id', $row->linked_account_id)->value('name');
            }

            $promotedId = DB::table('crm_opportunities')->insertGetId([
                'opportunity_name' => $row->title,
                'account_id' => $row->linked_account_id,
                'lead_source' => 'signal_inbox',
                'sales_stage' => 'Prospecting',
                'description' => $row->body,
                'assigned_to' => $identity['user_id'],
                'sub_institute_id' => $identity['sub_institute_id'],
                'created_by' => $identity['user_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('crm_signal_inbox')->where('id', $id)->update([
            'status' => 'promoted',
            'promoted_to' => $promotedTo,
            'promoted_id' => $promotedId,
            'reviewed_by' => $identity['user_id'],
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => 1,
            'message' => "Signal promoted to {$promotedTo}.",
            'data' => ['promoted_to' => $promotedTo, 'promoted_id' => $promotedId],
        ]);
    }

    public function topics(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $rows = DB::table('crm_signal_topics')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Signal topics.',
            'data' => $rows->map(fn ($r) => $this->topicResource($r))->all(),
        ]);
    }

    public function storeTopic(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'keywords' => 'required|array|min:1',
            'keywords.*' => 'required|string|max:191',
            'topic_type' => 'required|in:brand,competitor,industry,buying_intent,custom',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation error.', 'errors' => $v->errors()], 422);
        }

        $id = DB::table('crm_signal_topics')->insertGetId([
            'name' => $request->input('name'),
            'keywords' => json_encode($request->input('keywords')),
            'topic_type' => $request->input('topic_type'),
            'status' => 1,
            'sub_institute_id' => $identity['sub_institute_id'],
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('crm_signal_topics')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Topic created.', 'data' => $this->topicResource($row)], 201);
    }

    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'signalKey' => $row->signal_key,
            'sourceName' => $row->source_name,
            'sourceUrl' => $row->source_url,
            'title' => $row->title,
            'body' => $row->body,
            'publishedAt' => $row->published_at,
            'ingestedAt' => $row->ingested_at,
            'classification' => $row->classification,
            'classificationConfidence' => $row->classification_confidence !== null ? (float) $row->classification_confidence : null,
            'classificationReason' => $row->classification_reason,
            'relevanceScore' => $row->relevance_score !== null ? (float) $row->relevance_score : null,
            'linkedAccountId' => $row->linked_account_id ? (string) $row->linked_account_id : null,
            'linkedAccountName' => $row->linked_account_name ?? null,
            'linkedAccountConfidence' => $row->linked_account_confidence !== null ? (float) $row->linked_account_confidence : null,
            'status' => $row->status,
            'reviewedBy' => $row->reviewed_by ? (string) $row->reviewed_by : null,
            'reviewedAt' => $row->reviewed_at,
            'promotedTo' => $row->promoted_to,
            'promotedId' => $row->promoted_id ? (string) $row->promoted_id : null,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'tags' => $row->tags ? json_decode($row->tags, true) : [],
        ];
    }

    private function topicResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'keywords' => json_decode($row->keywords, true) ?? [],
            'topicType' => $row->topic_type,
            'status' => (bool) $row->status,
        ];
    }
}
