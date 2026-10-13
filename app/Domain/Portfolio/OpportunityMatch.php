<?php

namespace App\Domain\Portfolio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OpportunityMatch extends Model
{
    // MariaDB 10.1 (the live host) has no information_schema.generation_expression, which Eloquent's
    // guarded-column check selects: without this, create()/fill() throws there. See the trait for the full story.
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_opportunity_matches';

    protected $guarded = ['id'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'matched_need_codes' => 'array',
        'is_deliverable' => 'boolean',
    ];

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('sub_institute_id', $tenantId);
    }
}

