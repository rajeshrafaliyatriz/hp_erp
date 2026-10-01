<?php

namespace App\Domain\Portfolio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $table = 'g2g_partners';

    protected $guarded = ['id'];

    protected $casts = [
        'states_covered'           => 'array',
        'segments_covered'         => 'array',
        'needs_addressed'          => 'array',
        'procurement_routes'       => 'array',
        'offers_authorized'        => 'array',
        'empanelments'             => 'array',
        'certifications'           => 'array',
        'deal_registration_agreed' => 'boolean',
        'is_example'               => 'boolean',
        'referral_margin_pct'      => 'float',
        'conversion_rate'          => 'float',
    ];

    /**
     * Boot model and compute capacity and conversion rate on save.
     */
    protected static function booted(): void
    {
        static::saving(function (Partner $partner) {
            $max = (int) ($partner->max_concurrent_deals ?? 0);
            $active = (int) ($partner->active_deals_now ?? 0);
            $partner->capacity_available = max(0, $max - $active);

            $leads = (int) ($partner->leads_received ?? 0);
            $won = (int) ($partner->deals_won ?? 0);
            $partner->conversion_rate = $leads > 0 ? round($won / $leads, 4) : 0;
        });
    }

    /**
     * Scope to tenant or global partners.
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
     * Scope to active partners.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('partner_status', 'Active');
    }

    /**
     * Scope to partners with available capacity.
     */
    public function scopeHasCapacity(Builder $query): Builder
    {
        return $query->where('capacity_available', '>', 0);
    }
}

