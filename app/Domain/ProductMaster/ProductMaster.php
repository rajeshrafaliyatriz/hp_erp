<?php

namespace App\Domain\ProductMaster;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One product, sub-product or catalogue-only item (table g2g_product_master). Identity and pointers only: a sub-product's content stays in
 * g2g_product_offers, its price and SKU in crm_products. See Docs/agentic-crm/slices/S01-product-master.md.
 */
class ProductMaster extends Model
{
    // MariaDB 10.1 (live) cannot answer Eloquent's guarded-column introspection; see the trait.
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    public const KIND_PRODUCT = 'product';

    public const KIND_SUB_PRODUCT = 'sub_product';

    public const KIND_ITEM = 'item';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /** The tenant id of a row shared by every organisation (the built-in products). Never NULL. */
    public const SHARED = 0;

    protected $table = 'g2g_product_master';

    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'parent_id' => 'integer',
        'offer_row_id' => 'integer',
        'crm_product_id' => 'integer',
    ];

    /** The rows one organisation can read: its own and the shared ones. Writes always use the organisation's own id. */
    public function scopeVisibleTo(Builder $query, int $tenantId): Builder
    {
        return $query->whereIn('sub_institute_id', array_values(array_unique([max(0, $tenantId), self::SHARED])));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
