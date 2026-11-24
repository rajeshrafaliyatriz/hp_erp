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

    /**
     * The tenant id the OLD sharing gave a row (the built-in products were visible to every organisation). Since the isolation (S15 follow-up, 24 Nov 2026) no row may have it and
     * no reader returns one: the constant stays only so the checker (`not_isolated`) and `product-master:isolate-tenants` can find the leftovers.
     */
    public const SHARED = 0;

    protected $table = 'g2g_product_master';

    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'parent_id' => 'integer',
        'offer_row_id' => 'integer',
        'crm_product_id' => 'integer',
    ];

    /**
     * The rows one organisation can read: ITS OWN, and nothing else (S15 follow-up, D-1). A row of organisation 0 (the old sharing) is visible to nobody, and a caller with no
     * organisation (a tenant of 0 or below) reads no row at all.
     */
    public function scopeVisibleTo(Builder $query, int $tenantId): Builder
    {
        return $tenantId > 0 ? $query->where('sub_institute_id', $tenantId) : $query->whereRaw('1 = 0');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
