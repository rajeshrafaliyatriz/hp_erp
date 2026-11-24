<?php

namespace App\Domain\Portfolio;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Products\SubProductIcp;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the ideal-customer profiles of a Portfolio offer (rows of g2g_icp_profiles, one per customer model b2b / b2c / b2g) and the offer's own `icp_config`
 * column in step with an ICP payload.
 *
 * Moved out of PortfolioController::syncIndividualIcp (S15 plan section 2.2, Docs/agentic-crm/slices/S15-product-portfolio-merge.md) so that the one product
 * writer can call exactly the same code as the Portfolio endpoints (POST/PUT /api/portfolio/offers) do. BEHAVIOUR UNCHANGED: the body is the controller's, line
 * for line; only the fully qualified class names became imports.
 *
 * What it does for each customer model in the payload that carries something:
 *   - the profile is found by its key (SubProductIcp::key; for b2b also the older base key), or created ACTIVE with version 1; a found one is re-keyed, renamed,
 *     given the new config and its version raised by one;
 *   - the offer's `icp_config` keeps the other models as they were and takes this one (written only when the offers table has that column).
 * A table the host does not have (g2g_icp_profiles) is skipped, so a host that has not had the ICP migration still saves the offer's own config.
 */
final class OfferIcpSync
{
    /**
     * @param  array<string, mixed>  $icpPayload  either one config (with an optional `customer_model`, b2b by default) or `b2b` / `b2c` / `b2g` keys holding one config each
     */
    public function sync(int $tenantId, ?int $userId, ProductOffer $offer, array $icpPayload): void
    {
        $existing = SubProductIcp::normalizeStoredConfig(
            is_array($offer->icp_config) ? $offer->icp_config : (is_string($offer->icp_config) ? json_decode($offer->icp_config, true) : null)
        );

        $isMulti = isset($icpPayload['b2b']) || isset($icpPayload['b2c']) || isset($icpPayload['b2g']);
        $modelsToProcess = $isMulti ? ['b2b', 'b2c', 'b2g'] : [
            isset($icpPayload['customer_model']) && in_array($icpPayload['customer_model'], ['b2b', 'b2c', 'b2g'], true) ? $icpPayload['customer_model'] : 'b2b',
        ];

        $combinedConfig = $existing;

        foreach ($modelsToProcess as $model) {
            $cfg = $isMulti ? ($icpPayload[$model] ?? null) : $icpPayload;
            if (! is_array($cfg) || empty(array_filter($cfg))) {
                continue;
            }

            $cfg['customer_model'] = $model;
            $cfg['offer_ids'] = [(string) $offer->offer_id];
            $cfg['label'] = $cfg['label'] ?? ($offer->name . ' ' . strtoupper($model) . ' ICP');
            $combinedConfig[$model] = $cfg;

            if (Schema::hasTable('g2g_icp_profiles')) {
                $icpKey = SubProductIcp::key((string) $offer->offer_id, $model);
                $profile = IcpProfile::where('sub_institute_id', $tenantId)
                    ->where(function ($q) use ($icpKey, $offer, $model) {
                        $q->where('key', $icpKey);
                        if ($model === 'b2b') {
                            $q->orWhere('key', SubProductIcp::baseKey((string) $offer->offer_id));
                        }
                    })
                    ->first();

                $modelLabel = strtoupper($model);
                $name = ($cfg['label'] ?? $offer->name) . " {$modelLabel} ICP";

                if ($profile) {
                    $profile->key = $icpKey;
                    $profile->name = $name;
                    $profile->config = $cfg;
                    $profile->updated_by = $userId;
                    $profile->version = (int) $profile->version + 1;
                    $profile->save();
                } else {
                    IcpProfile::create([
                        'sub_institute_id' => $tenantId,
                        'key'              => $icpKey,
                        'name'             => $name,
                        'product_line'     => 'offer_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $offer->offer_id)) . '_' . $model,
                        'status'           => 'active',
                        'is_default'       => false,
                        'version'          => 1,
                        'config'           => $cfg,
                        'created_by'       => $userId,
                        'updated_by'       => $userId,
                    ]);
                }
            }
        }

        if (Schema::hasTable('g2g_product_offers') && ColumnExists::has('g2g_product_offers', 'icp_config')) {
            $offer->icp_config = $combinedConfig;
            $offer->save();
        }
    }
}
