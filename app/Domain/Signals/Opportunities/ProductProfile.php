<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class ProductProfile extends Model
{
    protected $table = 'g2g_product_profiles';

    protected $guarded = ['id'];

    protected $casts = [
        'target_industries' => 'array',
        'target_company_types' => 'array',
        'target_markets' => 'array',
        'keywords' => 'array',
        'excluded' => 'array',
        'competitors' => 'array',
        'research_enabled' => 'boolean',
        'recency_days' => 'integer',
    ];
}
