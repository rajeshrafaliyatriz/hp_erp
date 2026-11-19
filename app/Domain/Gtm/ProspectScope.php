<?php

namespace App\Domain\Gtm;

use App\Domain\Signals\Products\ProductCatalog;

/**
 * The product and customer type a prospecting account belongs to (the same two choices as Company Opportunities):
 *   b2b = customer acquisition, b2c = partner acquisition (partners, channel, resellers), b2g = government.
 *
 * "all" or an empty value means "no filter" when reading, and "not chosen" when writing: the callers that write require a real choice.
 * A product is checked against the organisation's own catalogue, so a key that does not exist is refused, never stored.
 */
final class ProspectScope
{
    public const MODELS = ['b2b', 'b2c', 'b2g'];

    /**
     * @return array{product: ?string, model: ?string}
     *
     * @throws \DomainException with a message that is safe to show
     */
    public static function fromInput(mixed $product, mixed $model, int $tenantId): array
    {
        $productKey = null;
        $product = strtolower(trim((string) $product));
        if ($product !== '' && $product !== 'all') {
            $productKey = ProductCatalog::normalise($product, $tenantId);
            if ($productKey === null) {
                throw new \DomainException('Choose one of your products.');
            }
        }

        $modelKey = null;
        $model = strtolower(trim((string) $model));
        if ($model !== '' && $model !== 'all') {
            if (! in_array($model, self::MODELS, true)) {
                throw new \DomainException('Choose B2B (customers), B2C (partners) or B2G (government).');
            }
            $modelKey = $model;
        }

        return ['product' => $productKey, 'model' => $modelKey];
    }

    /** Apply a scope to a query on a table that has product_key and customer_model columns. */
    public static function apply($query, array $scope, string $table = 'gtm_accounts')
    {
        if ($scope['product'] !== null) {
            $query->where("{$table}.product_key", $scope['product']);
        }
        if ($scope['model'] !== null) {
            $query->where("{$table}.customer_model", $scope['model']);
        }

        return $query;
    }
}
