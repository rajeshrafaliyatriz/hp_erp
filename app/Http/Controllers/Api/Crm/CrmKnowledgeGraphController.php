<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 3 — Knowledge Graph: entity relationships, knowledge sources, Account 360.
 *
 * All queries via DB::table(); no Eloquent models — matching the CRM
 * convention established by CrmLeadController. Tenant-scoped by
 * sub_institute_id throughout.
 */
class CrmKnowledgeGraphController extends Controller
{
    use ResolvesApiIdentity;

    // ── Account 360 ──────────────────────────────────────────────────────

    public function account360(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $sid = $identity['sub_institute_id'];

        $org = DB::table('crm_organizations')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (! $org) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $contacts = DB::table('crm_contacts')
            ->where('organization_id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'consent_status', 'lead_source', 'assigned_to', 'created_at']);

        $leads = DB::table('crm_leads')
            ->where('converted_organization_id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'company', 'email', 'lead_status', 'lifecycle_status', 'lead_source', 'assigned_to', 'created_at']);

        $opportunities = DB::table('crm_opportunities')
            ->where('organization_id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'opportunity_name', 'sales_stage', 'amount', 'close_date', 'assigned_to', 'created_at']);

        $campaigns = DB::table('crm_campaign_targets as ct')
            ->join('crm_campaigns as c', 'c.id', '=', 'ct.campaign_id')
            ->where('ct.target_type', 'organization')
            ->where('ct.target_id', $id)
            ->where('c.sub_institute_id', $sid)
            ->orderByDesc('ct.created_at')
            ->limit(5)
            ->get(['c.id as campaignId', 'c.campaign_name as campaignName', 'ct.response_status as responseStatus', 'ct.sent_at as sentAt'])
            ->toArray();

        $relationships = DB::table('crm_entity_relationships')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($id) {
                $q->where(function ($q2) use ($id) {
                    $q2->where('from_type', 'organization')->where('from_id', $id);
                })->orWhere(function ($q2) use ($id) {
                    $q2->where('to_type', 'organization')->where('to_id', $id);
                });
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->toArray();

        $knowledgeSources = DB::table('crm_knowledge_sources')
            ->where('entity_type', 'organization')
            ->where('entity_id', $id)
            ->where('sub_institute_id', $sid)
            ->orderByDesc('collected_at')
            ->limit(10)
            ->get()
            ->toArray();

        return response()->json([
            'status' => 1,
            'message' => 'Account 360.',
            'data' => [
                'organization'    => $this->orgResource($org),
                'contacts'        => $contacts,
                'leads'           => $leads,
                'opportunities'   => $opportunities,
                'campaigns'       => $campaigns,
                'relationships'   => array_map(fn ($r) => $this->relationshipResource($r), $relationships),
                'knowledgeSources' => array_map(fn ($k) => $this->knowledgeSourceResource($k), $knowledgeSources),
            ],
        ]);
    }

    // ── Entity Relationships ─────────────────────────────────────────────

    public function relationships(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $sid = $identity['sub_institute_id'];
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_entity_relationships')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');

        if ($fromType = $request->input('from_type')) {
            $query->where('from_type', $fromType);
        }
        if ($fromId = $request->input('from_id')) {
            $query->where('from_id', (int) $fromId);
        }
        if ($toType = $request->input('to_type')) {
            $query->where('to_type', $toType);
        }
        if ($toId = $request->input('to_id')) {
            $query->where('to_id', (int) $toId);
        }
        if ($relType = $request->input('relationship_type')) {
            $query->where('relationship_type', $relType);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Entity relationships.',
            'data' => [
                'items' => $rows->map(fn ($r) => $this->relationshipResource($r))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => (int) max(1, ceil($total / $perPage)),
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
        ]);
    }

    public function storeRelationship(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'from_type'         => 'required|string|max:30',
            'from_id'           => 'required|integer|min:1',
            'to_type'           => 'required|string|max:30',
            'to_id'             => 'required|integer|min:1',
            'relationship_type' => 'required|string|max:50',
            'confidence'        => 'nullable|numeric|min:0|max:1',
            'source'            => 'nullable|string|max:191',
            'source_url'        => 'nullable|string',
            'evidence'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = $identity['sub_institute_id'];
        $userId = $identity['user_id'];

        $id = DB::table('crm_entity_relationships')->insertGetId([
            'from_type'         => $request->input('from_type'),
            'from_id'           => (int) $request->input('from_id'),
            'to_type'           => $request->input('to_type'),
            'to_id'             => (int) $request->input('to_id'),
            'relationship_type' => $request->input('relationship_type'),
            'confidence'        => $request->input('confidence'),
            'source'            => $request->input('source', 'manual'),
            'source_url'        => $request->input('source_url'),
            'evidence'          => $request->input('evidence'),
            'verified'          => 0,
            'sub_institute_id'  => $sid,
            'created_by'        => $userId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $row = DB::table('crm_entity_relationships')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Relationship created.', 'data' => $this->relationshipResource($row)], 201);
    }

    public function deleteRelationship(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $affected = DB::table('crm_entity_relationships')
            ->where('id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (! $affected) {
            return response()->json(['status' => 0, 'message' => 'Relationship not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Relationship removed.']);
    }

    // ── Knowledge Sources ────────────────────────────────────────────────

    public function knowledgeSources(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $sid = $identity['sub_institute_id'];
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_knowledge_sources')->where('sub_institute_id', $sid);

        if ($entityType = $request->input('entity_type')) {
            $query->where('entity_type', $entityType);
        }
        if ($entityId = $request->input('entity_id')) {
            $query->where('entity_id', (int) $entityId);
        }
        if ($sourceType = $request->input('source_type')) {
            $query->where('source_type', $sourceType);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('collected_at')->forPage($page, $perPage)->get();

        return response()->json([
            'status' => 1,
            'message' => 'Knowledge sources.',
            'data' => [
                'items' => $rows->map(fn ($k) => $this->knowledgeSourceResource($k))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => (int) max(1, ceil($total / $perPage)),
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
        ]);
    }

    public function storeKnowledgeSource(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|in:organization,contact,lead,opportunity',
            'entity_id'   => 'required|integer|min:1',
            'source_type' => 'required|in:web_scrape,linkedin,news,document,manual,api',
            'title'       => 'nullable|string|max:500',
            'url'         => 'nullable|string',
            'content'     => 'nullable|string',
            'confidence'  => 'nullable|numeric|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = $identity['sub_institute_id'];

        $id = DB::table('crm_knowledge_sources')->insertGetId([
            'entity_type'     => $request->input('entity_type'),
            'entity_id'       => (int) $request->input('entity_id'),
            'source_type'     => $request->input('source_type'),
            'title'           => $request->input('title'),
            'url'             => $request->input('url'),
            'content'         => $request->input('content'),
            'confidence'      => $request->input('confidence'),
            'verified'        => 0,
            'collected_at'    => now(),
            'sub_institute_id' => $sid,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $row = DB::table('crm_knowledge_sources')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Knowledge source saved.', 'data' => $this->knowledgeSourceResource($row)], 201);
    }

    // ── Resource formatters ──────────────────────────────────────────────

    private function orgResource(object $org): array
    {
        return [
            'id'          => (string) $org->id,
            'name'        => $org->name,
            'industry'    => $org->industry ?? null,
            'website'     => $org->website ?? null,
            'phone'       => $org->phone ?? null,
            'email'       => $org->email ?? null,
            'assignedTo'  => isset($org->assigned_to) ? (string) $org->assigned_to : null,
            'createdAt'   => $org->created_at ?? null,
        ];
    }

    private function relationshipResource(object $r): array
    {
        return [
            'id'               => (string) $r->id,
            'fromType'         => $r->from_type,
            'fromId'           => (string) $r->from_id,
            'toType'           => $r->to_type,
            'toId'             => (string) $r->to_id,
            'relationshipType' => $r->relationship_type,
            'confidence'       => $r->confidence !== null ? (float) $r->confidence : null,
            'source'           => $r->source ?? null,
            'sourceUrl'        => $r->source_url ?? null,
            'evidence'         => $r->evidence ?? null,
            'verified'         => (bool) $r->verified,
            'createdAt'        => $r->created_at ?? null,
        ];
    }

    private function knowledgeSourceResource(object $k): array
    {
        return [
            'id'           => (string) $k->id,
            'entityType'   => $k->entity_type,
            'entityId'     => (string) $k->entity_id,
            'sourceType'   => $k->source_type,
            'title'        => $k->title ?? null,
            'url'          => $k->url ?? null,
            'content'      => $k->content ?? null,
            'confidence'   => $k->confidence !== null ? (float) $k->confidence : null,
            'verified'     => (bool) $k->verified,
            'collectedAt'  => $k->collected_at ?? null,
        ];
    }
}
