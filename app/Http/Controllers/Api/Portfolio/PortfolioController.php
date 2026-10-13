<?php

namespace App\Http\Controllers\Api\Portfolio;

use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\Portfolio\ProductOffer;
use App\Domain\Portfolio\TaxonomyService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PortfolioController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * GET /portfolio/taxonomy
     * Returns the shared business taxonomy.
     */
    public function taxonomy(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 1,
            'data'   => TaxonomyService::getTaxonomy(),
        ]);
    }

    /**
     * GET /portfolio/stats
     * Returns portfolio summary statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);
        $base = ProductOffer::query()->forTenant($tenantId);

        $total = (clone $base)->count();
        $sellable = (clone $base)->partnerSellable()->count();
        $confirmed = (clone $base)->where('readiness_confirmed', true)->count();
        $byProduct = (clone $base)
            ->selectRaw('parent_product, COUNT(*) as count')
            ->groupBy('parent_product')
            ->pluck('count', 'parent_product')
            ->toArray();

        return response()->json([
            'status' => 1,
            'data'   => [
                'total_offers'        => $total,
                'partner_sellable'    => $sellable,
                'readiness_confirmed' => $confirmed,
                'by_parent_product'   => $byProduct,
            ],
        ]);
    }

    /**
     * GET /portfolio/offers
     * List portfolio offers with search and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);

        $v = Validator::make($request->query(), [
            'search'           => 'nullable|string|max:100',
            'parent_product'   => 'nullable|string|max:50',
            'readiness_status' => 'nullable|string|max:100',
            'partner_sellable' => 'nullable|in:true,false,1,0',
            'need'             => 'nullable|string|max:10',
            'segment'          => 'nullable|string|max:50',
            'per_page'         => 'nullable|integer|min:1|max:100',
            'page'             => 'nullable|integer|min:1',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $query = ProductOffer::query()->forTenant($tenantId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('offer_id', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('what_it_does', 'like', "%{$search}%")
                  ->orWhere('modules_components', 'like', "%{$search}%");
            });
        }

        if ($product = $request->query('parent_product')) {
            $query->where('parent_product', $product);
        }

        if ($readiness = $request->query('readiness_status')) {
            $query->where('readiness_status', $readiness);
        }

        if ($request->has('partner_sellable') && $request->query('partner_sellable') !== '') {
            $val = filter_var($request->query('partner_sellable'), FILTER_VALIDATE_BOOLEAN);
            $query->where('partner_sellable', $val);
        }

        if ($need = $request->query('need')) {
            $query->whereJsonContains('needs_solved', $need);
        }

        if ($segment = $request->query('segment')) {
            $query->whereJsonContains('primary_segments', $segment);
        }

        $perPage = (int) $request->query('per_page', 25);
        $offers = $query->orderBy('offer_id')->paginate($perPage);

        return response()->json([
            'status' => 1,
            'data'   => $offers,
        ]);
    }

    /**
     * GET /portfolio/offers/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);

        $offer = ProductOffer::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('offer_id', $id);
            })
            ->first();

        if (! $offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        // Fetch bundled offers if present
        $bundledOffers = [];
        if (! empty($offer->bundles_with) && is_array($offer->bundles_with)) {
            $bundledOffers = ProductOffer::query()
                ->forTenant($tenantId)
                ->whereIn('offer_id', $offer->bundles_with)
                ->get(['id', 'offer_id', 'name', 'parent_product', 'readiness_status', 'partner_sellable']);
        }

        return response()->json([
            'status' => 1,
            'data'   => array_merge($offer->toArray(), [
                'bundled_offer_details' => $bundledOffers,
            ]),
        ]);
    }

    /**
     * POST /portfolio/offers/{id}/readiness   (administrators only; see routes)
     *
     * The ONLY way readiness_confirmed changes. Records who and when, and appends a history row.
     * Nothing confirms an offer automatically: not an import, not a re-sync, not a match.
     * Body: confirmed (bool, required), note (string, required when confirming).
     */
    public function confirmReadiness(Request $request, $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $v = Validator::make($request->all(), [
            'confirmed' => 'required|boolean',
            'note' => 'required_if:confirmed,true,1|nullable|string|min:5|max:1000',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        // Only this organisation's own offers: the shared foundation rows (sub_institute_id NULL)
        // are not editable from a tenant.
        $offer = ProductOffer::query()
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('offer_id', $id);
            })
            ->first();

        if (! $offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        $confirm = $request->boolean('confirmed');
        if ((bool) $offer->readiness_confirmed === $confirm) {
            return response()->json(['status' => 1, 'message' => 'No change.', 'data' => $offer]);
        }

        DB::transaction(function () use ($offer, $confirm, $identity, $request) {
            // Query builder, so the model's saving hook and is_user_edited are not triggered by a flag change.
            DB::table('g2g_product_offers')->where('id', $offer->id)->update([
                'readiness_confirmed' => $confirm,
                'readiness_confirmed_by' => $confirm ? $identity['user_id'] : null,
                'readiness_confirmed_at' => $confirm ? now() : null,
                'updated_at' => now(),
            ]);

            DB::table('g2g_offer_readiness_log')->insert([
                'sub_institute_id' => $identity['sub_institute_id'], 'offer_row_id' => $offer->id, 'offer_id' => $offer->offer_id,
                'action' => $confirm ? 'confirmed' : 'unconfirmed', 'readiness_status' => $offer->readiness_status,
                'actor_id' => $identity['user_id'], 'note' => $request->input('note') ? mb_substr((string) $request->input('note'), 0, 1000) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['status' => 1, 'message' => $confirm ? 'Readiness confirmed.' : 'Readiness confirmation removed.', 'data' => $offer->fresh()]);
    }

    /**
     * GET /portfolio/offers/{id}/readiness-log   (admin/HR): who confirmed or un-confirmed, when and why.
     */
    public function readinessLog(Request $request, $id): JsonResponse
    {
        $tenantId = $this->apiTenantId($request);

        $offer = ProductOffer::query()->where('sub_institute_id', $tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('offer_id', $id);
            })->first();

        if (! $offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        $rows = DB::table('g2g_offer_readiness_log')
            ->where('sub_institute_id', $tenantId)->where('offer_row_id', $offer->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['status' => 1, 'data' => $rows]);
    }

    /**
     * POST /portfolio/offers
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
            'offer_id'             => 'required|string|max:50',
            'name'                 => 'required|string|max:191',
            'parent_product'       => 'required|string|max:50',
            'modules_components'   => 'nullable|string',
            'what_it_does'         => 'nullable|string',
            'needs_solved'         => 'nullable|array',
            'primary_segments'     => 'nullable|array',
            'trigger_signals'      => 'nullable|array',
            'deployment_model'     => 'nullable|string|max:100',
            'readiness_status'     => 'required|string|max:100',
            'readiness_confirmed'  => 'nullable|boolean',
            'typical_deal_band'    => 'nullable|string|max:50',
            'pricing_model'        => 'nullable|string|max:191',
            'implementation_effort'=> 'nullable|string|max:100',
            'delivery_owner'       => 'nullable|string|max:100',
            'bundles_with'         => 'nullable|array',
            'proof_points'         => 'nullable|string',
            'notes'                => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        $existing = ProductOffer::query()
            ->where('offer_id', $request->input('offer_id'))
            ->where(function ($q) use ($tenantId) {
                $q->where('sub_institute_id', $tenantId)->orWhereNull('sub_institute_id');
            })
            ->first();

        if ($existing) {
            return response()->json(['status' => 0, 'message' => 'An offer with this Offer ID already exists'], 409);
        }

        $offer = ProductOffer::create([
            'sub_institute_id'     => $tenantId,
            'offer_id'             => trim($request->input('offer_id')),
            'name'                 => trim($request->input('name')),
            'parent_product'       => trim($request->input('parent_product')),
            'modules_components'   => $request->input('modules_components'),
            'what_it_does'         => $request->input('what_it_does'),
            'needs_solved'         => $request->input('needs_solved', []),
            'primary_segments'     => $request->input('primary_segments', []),
            'trigger_signals'      => $request->input('trigger_signals', []),
            'deployment_model'     => $request->input('deployment_model'),
            'readiness_status'     => $request->input('readiness_status'),
            // Never set here: readiness is confirmed only by a person, through confirmReadiness()
            // (admin only, audited). A new offer always starts unconfirmed.
            'readiness_confirmed'  => false,
            'typical_deal_band'    => $request->input('typical_deal_band'),
            'pricing_model'        => $request->input('pricing_model'),
            'implementation_effort'=> $request->input('implementation_effort'),
            'delivery_owner'       => $request->input('delivery_owner'),
            'bundles_with'         => $request->input('bundles_with', []),
            'proof_points'         => $request->input('proof_points'),
            'notes'                => $request->input('notes'),
            'is_user_edited'       => true,
            'created_by'           => $userId,
            'updated_by'           => $userId,
        ]);

        return response()->json([
            'status'  => 1,
            'message' => 'Offer created successfully',
            'data'    => $offer,
        ], 201);
    }

    /**
     * PUT /portfolio/offers/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $userId = $identity['user_id'];

        $offer = ProductOffer::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('offer_id', $id);
            })
            ->first();

        if (! $offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'name'                 => 'sometimes|required|string|max:191',
            'parent_product'       => 'sometimes|required|string|max:50',
            'modules_components'   => 'nullable|string',
            'what_it_does'         => 'nullable|string',
            'needs_solved'         => 'nullable|array',
            'primary_segments'     => 'nullable|array',
            'trigger_signals'      => 'nullable|array',
            'deployment_model'     => 'nullable|string|max:100',
            'readiness_status'     => 'sometimes|required|string|max:100',
            'readiness_confirmed'  => 'nullable|boolean',
            'typical_deal_band'    => 'nullable|string|max:50',
            'pricing_model'        => 'nullable|string|max:191',
            'implementation_effort'=> 'nullable|string|max:100',
            'delivery_owner'       => 'nullable|string|max:100',
            'bundles_with'         => 'nullable|array',
            'proof_points'         => 'nullable|string',
            'notes'                => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 0, 'errors' => $v->errors()], 422);
        }

        // Confirming readiness is its own audited, admin-only action. This endpoint must not be a
        // back door around it, so a request that tries to change the flag is refused, not ignored.
        if ($request->has('readiness_confirmed') && $request->boolean('readiness_confirmed') !== (bool) $offer->readiness_confirmed) {
            return response()->json(['status' => 0, 'message' => 'Readiness is confirmed with POST /portfolio/offers/{id}/readiness (administrators only).'], 422);
        }

        $data = $request->only([
            'name', 'parent_product', 'modules_components', 'what_it_does',
            'needs_solved', 'primary_segments', 'trigger_signals', 'deployment_model',
            'readiness_status', 'typical_deal_band',
            'pricing_model', 'implementation_effort', 'delivery_owner',
            'bundles_with', 'proof_points', 'notes',
        ]);

        $data['is_user_edited'] = true;
        $data['updated_by'] = $userId;

        $offer->update($data);

        return response()->json([
            'status'  => 1,
            'message' => 'Offer updated successfully',
            'data'    => $offer->fresh(),
        ]);
    }

    /**
     * DELETE /portfolio/offers/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $offer = ProductOffer::query()
            ->forTenant($tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('offer_id', $id);
            })
            ->first();

        if (! $offer) {
            return response()->json(['status' => 0, 'message' => 'Offer not found'], 404);
        }

        $offer->delete();

        return response()->json([
            'status'  => 1,
            'message' => 'Offer deleted successfully',
        ]);
    }

    /**
     * POST /portfolio/import
     */
    public function import(Request $request, PortfolioImportService $importer): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $path = $request->input('path');
        $result = $importer->import($path, $tenantId);

        return response()->json([
            'status'  => 1,
            'message' => 'Import executed successfully',
            'data'    => $result,
        ]);
    }
}

