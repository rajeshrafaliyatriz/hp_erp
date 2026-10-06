<?php

namespace App\Domain\Portfolio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OpportunityMatch extends Model
{
    protected $table = 'g2g_opportunity_matches';

    protected $guarded = ['id'];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('sub_institute_id', $tenantId);
    }
}

