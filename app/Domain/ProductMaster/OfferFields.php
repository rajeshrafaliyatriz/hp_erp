<?php

namespace App\Domain\ProductMaster;

/**
 * The one map of the About section onto `g2g_product_offers` (S15 WPA2-H3W3 / guard H3, 23 Nov 2026), the twin of CatalogueFields for `crm_products`.
 *
 * ProductWriter writes the About section of a Sub-product through this map (it used to keep it as a private constant). tests/Unit/OfferFieldsScanTest reads every
 * migration that works on `g2g_product_offers` and fails, by file and column, when a column is neither
 *   - a column of ABOUT_MAP or HEADER (so the one Sub-product form writes it), nor
 *   - one of systemOfferColumns(), the short list of columns the system or another screen fills, each with a written reason of 30 characters or more.
 * A column added to the table later therefore cannot be forgotten: whoever adds it must map it (and so write it) or explain why no form of this door touches it.
 */
final class OfferFields
{
    /** About key => `g2g_product_offers` column: the 13 fields of the About section (the offer's name, group and readiness are the other three of its 16: see HEADER). */
    public const ABOUT_MAP = [
        'whatItDoes' => 'what_it_does', 'modulesComponents' => 'modules_components', 'deploymentModel' => 'deployment_model', 'proofPoints' => 'proof_points',
        'notes' => 'notes', 'needsSolved' => 'needs_solved', 'primarySegments' => 'primary_segments', 'triggerSignals' => 'trigger_signals',
        'pricingModel' => 'pricing_model', 'typicalDealBand' => 'typical_deal_band', 'implementationEffort' => 'implementation_effort', 'deliveryOwner' => 'delivery_owner',
        'oftenSoldTogether' => 'bundles_with',
    ];

    /** The About columns that hold a list (stored as JSON text by the model's array cast). */
    public const ABOUT_LISTS = ['needs_solved', 'primary_segments', 'trigger_signals', 'bundles_with'];

    /**
     * The three other fields of the offer that the writer sets from the top of the request (not from `about`): request key => column. `productId` is the level-1
     * Product, and the offer stores that Product's Portfolio name as its group (`parent_product`); `readiness` is the status label of the offer.
     */
    public const HEADER = ['name' => 'name', 'productId' => 'parent_product', 'readiness' => 'readiness_status'];

    /** @return list<string> the columns the About section writes */
    public static function aboutColumns(): array
    {
        return array_values(self::ABOUT_MAP);
    }

    /** @return list<string> every column the one Sub-product form writes on an offer (the About fields and the header) */
    public static function columns(): array
    {
        return array_merge(self::aboutColumns(), array_values(self::HEADER));
    }

    /**
     * The columns of `g2g_product_offers` that no field of the form writes, each with the reason (30 characters or more, one a reviewer can disagree with).
     *
     * @return array<string, string> column => reason
     */
    public static function systemOfferColumns(): array
    {
        return [
            'id' => 'the auto-increment key of the row, given by the database and never part of any request',
            'sub_institute_id' => 'the organisation of the offer (NULL for a shared foundation offer), taken from the caller\'s identity and never from a request field',
            'offer_id' => 'the stable offer code, chosen once on create (request key offerId) and never changed by an update, so it is not an editable About field',
            'readiness_confirmed' => 'confirmed only by a person through the Portfolio\'s own audited action; the writer always stores false and never changes it',
            'readiness_confirmed_by' => 'who confirmed the readiness: set only by the Portfolio\'s audited confirm action, never by the one Sub-product form',
            'readiness_confirmed_at' => 'when the readiness was confirmed: set only by the Portfolio\'s audited confirm action, never by the one Sub-product form',
            'partner_sellable' => 'calculated by the ProductOffer model on every save from the readiness status, so no form may set it',
            'is_user_edited' => 'set to true by every write of the form so that a later import does not overwrite a person\'s edit; not a field a person fills',
            'icp_config' => 'the individual ICP payload of the sub-product, written by OfferIcpSync from the icp section of the request, not by an About field',
            'created_by' => 'who created the row, taken from the caller\'s identity by the writer and never from a request field',
            'updated_by' => 'who changed the row last, taken from the caller\'s identity by the writer and never from a request field',
            'created_at' => 'the creation time, set by the model\'s timestamps and never part of any request',
            'updated_at' => 'the time of the last change, set by the model\'s timestamps and never part of any request',
        ];
    }
}
