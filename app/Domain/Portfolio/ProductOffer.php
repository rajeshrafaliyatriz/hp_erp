<?php

namespace App\Domain\Portfolio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductOffer extends Model
{
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
     * Scope to tenant or global foundation offers.
     */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        if ($tenantId === null) {
            return $query->whereNull('sub_institute_id');
        }

        return $query->where(function (Builder $q) use ($tenantId) {
            $q->where('sub_institute_id', $tenantId)
              ->orWhereNull('sub_institute_id');
        });
    }

    /**
     * Scope to partner sellable offers.
     */
    public function scopePartnerSellable(Builder $query): Builder
    {
        return $query->where('partner_sellable', true);
    }
}

