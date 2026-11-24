<?php

namespace App\Domain\Portfolio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductOffer extends Model
{
    // MariaDB 10.1 (the live host) has no information_schema.generation_expression, which Eloquent's
    // guarded-column check selects: without this, create()/fill() throws there. See the trait for the full story.
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_product_offers';

    protected $guarded = ['id'];

    protected $casts = [
        'needs_solved'        => 'array',
        'primary_segments'    => 'array',
        'trigger_signals'     => 'array',
        'bundles_with'        => 'array',
        'readiness_confirmed' => 'boolean',
        'partner_sellable'    => 'boolean',
        'is_user_edited'      => 'boolean',
        'icp_config'          => 'array',
    ];

    /**
     * Boot model and automatically calculate partner_sellable on save.
     */
    protected static function booted(): void
    {
        static::saving(function (ProductOffer $offer) {
            $offer->partner_sellable = TaxonomyService::isPartnerSellable($offer->readiness_status ?? '');
        });
    }

    /**
     * Scope to ONE organisation's own offers (S15 follow-up, D-1: an About belongs to one organisation and is visible to that organisation only; the old "or the foundation
     * offers with no organisation" is gone, and `product-master:isolate-tenants` gives the ones that existed to their owner). A `null` tenant keeps meaning "no organisation":
     * it reads the rows that have none, which after the isolation is nothing.
     *
     * An offer whose Sub-product is ARCHIVED in the Product Master is not an offer any more: matching, research, the ICP offer lists, `/portfolio/products` and the old
     * Portfolio screens all read offers through this one scope, so archiving a Sub-product removes it from all of them, and restoring it brings it back (audit finding G-E1,
     * slice 15). An offer with no master row, or whose row is in any other status, is unchanged; a host without the master table is unchanged. The unified screens and the
     * writer reach an archived Sub-product's About by its row number, not through this scope.
     */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        if ($tenantId === null) {
            $query->whereNull('sub_institute_id');
        } else {
            $query->where('sub_institute_id', $tenantId);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('g2g_product_master')) {
            $offers = $query->getModel()->qualifyColumn('id');
            $query->whereNotExists(function ($archived) use ($offers) {
                $archived->selectRaw('1')->from('g2g_product_master as pm_archived')
                    ->whereColumn('pm_archived.offer_row_id', $offers)
                    ->where('pm_archived.status', \App\Domain\ProductMaster\ProductMaster::STATUS_ARCHIVED);
            });
        }

        return $query;
    }

    /**
     * Scope to partner sellable offers.
     */
    public function scopePartnerSellable(Builder $query): Builder
    {
        return $query->where('partner_sellable', true);
    }
}

