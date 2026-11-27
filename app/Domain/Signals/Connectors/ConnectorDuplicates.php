<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;

/**
 * One connection per account per product per organisation. A second connector for the SAME Facebook Page, Instagram account, YouTube channel,
 * LinkedIn organisation or web page, under the same product, would import every post twice and show two cards for one thing, so it is refused with the
 * name of the connector that already holds it. The same account under a DIFFERENT product is a legitimate separate connector (each product reads it for
 * its own signals), and another organisation's connectors are never looked at.
 */
final class ConnectorDuplicates
{
    /** The connector that already holds this account for this product (other than $exceptId), or null. */
    public static function accountHolder(int $tenantId, string $type, string $productKey, string $externalAccountId, ?int $exceptId = null): ?DataConnector
    {
        $account = trim($externalAccountId);
        if ($account === '') {
            return null;
        }

        return DataConnector::where('sub_institute_id', $tenantId)->where('type', $type)->where('product_key', $productKey)
            ->where('external_account_id', $account)->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->orderBy('id')->first();
    }

    /** The web-page connector that already watches this page for this product, or null. Compared by host (without www), path (without a trailing slash) and query. */
    public static function pageHolder(int $tenantId, string $productKey, string $url, ?int $exceptId = null): ?DataConnector
    {
        $wanted = self::pageKey($url);
        if ($wanted === null) {
            return null;
        }
        foreach (DataConnector::where('sub_institute_id', $tenantId)->where('type', 'public_web_page')->where('product_key', $productKey)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->orderBy('id')->get(['id', 'name', 'url', 'type', 'product_key', 'sub_institute_id']) as $c) {
            if (self::pageKey((string) $c->url) === $wanted) {
                return $c;
            }
        }

        return null;
    }

    public static function pageKey(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) $parts['host']));

        return $host . rtrim((string) ($parts['path'] ?? ''), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function message(DataConnector $holder, string $what): string
    {
        return "This {$what} is already connected for this product as \"{$holder->name}\". Use that connector, or disconnect and delete it first.";
    }
}
