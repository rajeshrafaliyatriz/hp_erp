<?php

namespace App\Http\Controllers\Api\Portfolio;

use App\Domain\Portfolio\Partner;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PartnerController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * GET /portfolio/partners/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);
        $base = Partner::query()->forTenant($tenantId)->where('is_example', false);

        $total = (clone $base)->count();
        $active = (clone $base)->where('partner_status', 'Active')->count();
        $pipeline = (clone $base)->whereIn('partner_status', ['Prospect', 'Onboarding'])->count();
        $capacity = (clone $base)->sum('capacity_available');

        return response()->json([
            'status' => 1,
            'data'   => [
                'total_partners'    => $total,
                'active_partners'   => $active,
                'pipeline_partners' => $pipeline,
                'total_capacity'    => (int) $capacity,
            ],
        ]);
    }

    /**
     * GET /portfolio/partners
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);

        $v = Validator::make($request->query(), [
            'search'       => 'nullable|string|max:100',
            'partner_type' => 'nullable|string|max:100',
            'status'       => 'nullable|string|max:50',
            'state'        => 'nullable|string|max:100',
            'segment'      => 'nullable|string|max:50',
            'offer'        => 'nullable|string|max:50',
            'has_capacity' => 'nullable|in:true,false,1,0',
            'per_page'     => 'nullable|integer|min:1|max:100',
            'page'         => 'nullable|integer|min:1',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $query = Partner::query()->forTenant($tenantId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('partner_name', 'like', "%{$search}%")
                  ->orWhere('partner_id', 'like', "%{$search}%")
                  ->orWhere('sales_contact_name', 'like', "%{$search}%")
                  ->orWhere('key_buyer_relationships', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if ($type = $request->query('partner_type')) {
            $query->where('partner_type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('partner_status', $status);
        }

        if ($state = $request->query('state')) {
            $query->where(function ($q) use ($state) {
                $q->whereJsonContains('states_covered', $state)
                  ->orWhereJsonContains('states_covered', 'All India')
                  ->orWhere('hq_state', $state);
            });
        }

        if ($segment = $request->query('segment')) {
            $query->whereJsonContains('segments_covered', $segment);
        }

        if ($offer = $request->query('offer')) {
            $query->whereJsonContains('offers_authorized', $offer);
        }

        if ($request->has('has_capacity') && $request->query('has_capacity') !== '') {
            $val = filter_var($request->query('has_capacity'), FILTER_VALIDATE_BOOLEAN);
            if ($val) {
                $query->where('capacity_available', '>', 0);
            }
        }

        $perPage = (int) $request->query('per_page', 25);
        $partners = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'status' => 1,
            'data'   => $partners,
        ]);
    }

    /**
     * GET /portfolio/partners/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);

        $partner = Partner::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('partner_id', $id);
            })
            ->first();

        if (! $partner) {
            return response()->json(['status' => 0, 'message' => 'Partner not found'], 404);
        }

        return response()->json([
            'status' => 1,
            'data'   => $partner,
        ]);
    }

    /**
     * POST /portfolio/partners
     */
    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = $identity['user_id'];

        $v = Validator::make($request->all(), [
            'partner_id'               => 'required|string|max:50',
            'partner_name'             => 'required|string|max:191',
            'partner_type'             => 'required|string|max:100',
            'hq_state'                 => 'nullable|string|max:100',
            'states_covered'           => 'nullable|array',
            'segments_covered'         => 'nullable|array',
            'needs_addressed'          => 'nullable|array',
            'procurement_routes'       => 'nullable|array',
            'offers_authorized'        => 'nullable|array',
            'empanelments'             => 'nullable|array',
            'certifications'           => 'nullable|array',
            'key_buyer_relationships'  => 'nullable|string',
            'delivery_capability'      => 'nullable|string|max:100',
            'max_concurrent_deals'     => 'nullable|integer|min:0|max:1000',
            'active_deals_now'         => 'nullable|integer|min:0|max:1000',
            'sales_contact_name'       => 'nullable|string|max:191',
            'contact_email'            => 'nullable|email|max:191',
            'contact_phone'            => 'nullable|string|max:100',
            'preferred_channel'        => 'nullable|string|max:50',
            'commercial_model'         => 'nullable|string|max:100',
            'referral_margin_pct'      => 'nullable|numeric|min:0|max:100',
            'deal_registration_agreed' => 'nullable|boolean',
            'conflicts'                => 'nullable|string',
            'leads_received'           => 'nullable|integer|min:0',
            'acknowledged_within_sla'  => 'nullable|integer|min:0',
            'deals_won'                => 'nullable|integer|min:0',
            'partner_status'           => 'required|string|max:50',
            'avg_days_to_first_meeting'=> 'nullable|integer|min:0',
            'notes'                    => 'nullable|string',
            'is_example'               => 'nullable|boolean',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $existing = Partner::query()
            ->where('partner_id', $request->input('partner_id'))
            ->where(function ($q) use ($tenantId) {
                $q->where('sub_institute_id', $tenantId)->orWhereNull('sub_institute_id');
            })
            ->first();

        if ($existing) {
            return response()->json(['status' => 0, 'message' => 'A partner with this Partner ID already exists'], 409);
        }

        $partner = Partner::create([
            'sub_institute_id'         => $tenantId,
            'partner_id'               => trim($request->input('partner_id')),
            'partner_name'             => trim($request->input('partner_name')),
            'partner_type'             => trim($request->input('partner_type')),
            'hq_state'                 => $request->input('hq_state'),
            'states_covered'           => $request->input('states_covered', []),
            'segments_covered'         => $request->input('segments_covered', []),
            'needs_addressed'          => $request->input('needs_addressed', []),
            'procurement_routes'       => $request->input('procurement_routes', []),
            'offers_authorized'        => $request->input('offers_authorized', []),
            'empanelments'             => $request->input('empanelments', []),
            'certifications'           => $request->input('certifications', []),
            'key_buyer_relationships'  => $request->input('key_buyer_relationships'),
            'delivery_capability'      => $request->input('delivery_capability'),
            'max_concurrent_deals'     => (int) $request->input('max_concurrent_deals', 1),
            'active_deals_now'         => (int) $request->input('active_deals_now', 0),
            'sales_contact_name'       => $request->input('sales_contact_name'),
            'contact_email'            => $request->input('contact_email'),
            'contact_phone'            => $request->input('contact_phone'),
            'preferred_channel'        => $request->input('preferred_channel'),
            'commercial_model'         => $request->input('commercial_model'),
            'referral_margin_pct'      => $request->input('referral_margin_pct'),
            'deal_registration_agreed' => (bool) $request->input('deal_registration_agreed', false),
            'conflicts'                => $request->input('conflicts'),
            'leads_received'           => (int) $request->input('leads_received', 0),
            'acknowledged_within_sla'  => (int) $request->input('acknowledged_within_sla', 0),
            'deals_won'                => (int) $request->input('deals_won', 0),
            'partner_status'           => $request->input('partner_status', 'Prospect'),
            'avg_days_to_first_meeting'=> $request->input('avg_days_to_first_meeting'),
            'notes'                    => $request->input('notes'),
            'is_example'               => (bool) $request->input('is_example', false),
            'created_by'               => $userId,
            'updated_by'               => $userId,
        ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Partner created successfully',
            'data'    => $partner,
        ], 201);
    }

    /**
     * PUT /portfolio/partners/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = $identity['user_id'];

        $partner = Partner::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('partner_id', $id);
            })
            ->first();

        if (! $partner) {
            return response()->json(['status' => 0, 'message' => 'Partner not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'partner_name'             => 'sometimes|required|string|max:191',
            'partner_type'             => 'sometimes|required|string|max:100',
            'hq_state'                 => 'nullable|string|max:100',
            'states_covered'           => 'nullable|array',
            'segments_covered'         => 'nullable|array',
            'needs_addressed'          => 'nullable|array',
            'procurement_routes'       => 'nullable|array',
            'offers_authorized'        => 'nullable|array',
            'empanelments'             => 'nullable|array',
            'certifications'           => 'nullable|array',
            'key_buyer_relationships'  => 'nullable|string',
            'delivery_capability'      => 'nullable|string|max:100',
            'max_concurrent_deals'     => 'nullable|integer|min:0|max:1000',
            'active_deals_now'         => 'nullable|integer|min:0|max:1000',
            'sales_contact_name'       => 'nullable|string|max:191',
            'contact_email'            => 'nullable|email|max:191',
            'contact_phone'            => 'nullable|string|max:100',
            'preferred_channel'        => 'nullable|string|max:50',
            'commercial_model'         => 'nullable|string|max:100',
            'referral_margin_pct'      => 'nullable|numeric|min:0|max:100',
            'deal_registration_agreed' => 'nullable|boolean',
            'conflicts'                => 'nullable|string',
            'leads_received'           => 'nullable|integer|min:0',
            'acknowledged_within_sla'  => 'nullable|integer|min:0',
            'deals_won'                => 'nullable|integer|min:0',
            'partner_status'           => 'sometimes|required|string|max:50',
            'avg_days_to_first_meeting'=> 'nullable|integer|min:0',
            'notes'                    => 'nullable|string',
            'is_example'               => 'nullable|boolean',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $data = $request->only([
            'partner_name', 'partner_type', 'hq_state', 'states_covered', 'segments_covered',
            'needs_addressed', 'procurement_routes', 'offers_authorized', 'empanelments',
            'certifications', 'key_buyer_relationships', 'delivery_capability',
            'max_concurrent_deals', 'active_deals_now', 'sales_contact_name',
            'contact_email', 'contact_phone', 'preferred_channel', 'commercial_model',
            'referral_margin_pct', 'deal_registration_agreed', 'conflicts',
            'leads_received', 'acknowledged_within_sla', 'deals_won',
            'partner_status', 'avg_days_to_first_meeting', 'notes', 'is_example',
        ]);

        $data['updated_by'] = $userId;
        $partner->update($data);

        return response()->json([
            'status'  => 1,
            'message' => 'Partner updated successfully',
            'data'    => $partner->fresh(),
        ]);
    }

    /**
     * DELETE /portfolio/partners/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $partner = Partner::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('partner_id', $id);
            })
            ->first();

        if (! $partner) {
            return response()->json(['status' => 0, 'message' => 'Partner not found'], 404);
        }

        $partner->delete();

        return response()->json([
            'status'  => 1,
            'message' => 'Partner deleted successfully',
        ]);
    }
}

