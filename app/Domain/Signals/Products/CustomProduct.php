<?php

namespace App\Domain\Signals\Products;

use Illuminate\Database\Eloquent\Model;

/**
 * One extra top-level product of ONE organisation (`sub_institute_id`). The built-in products live in config/signals_products.php and are never
 * stored here. A custom product has its own key, its own `business_fit` value and its own ICP product line, so its ICP is a separate ICP
 * in g2g_icp_profiles and is never shared with a built-in product's group.
 */
class CustomProduct extends Model
{
    // MariaDB 10.1 (live) cannot answer Eloquent's guarded-column introspection; see the trait.
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_signal_products';

    protected $guarded = ['id'];

    protected $casts = ['portfolio_names' => 'array'];
}
