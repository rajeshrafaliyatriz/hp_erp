<?php

namespace App\Domain\Ask\Tools;

use App\Domain\AgentGateway\Rejected;
use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRights;
use App\Domain\Ask\AskSignals;
use App\Domain\Ask\ToolResult;
use App\Domain\Signals\Products\ProductCatalog;

/**
 * list_signals {product_key?, status?, since?, until?}: market signals, newest first, at most 10 (with how many matched). `since` and `until` bound "the date
 * the signal was last decided on, or found when nobody has decided on it" (the contract names `since` only; "last month" cannot be bounded without `until`).
 * A dismissed signal carries the reason a person gave, or says none was recorded.
 */
final class ListSignalsTool extends AskTool
{
    public function name(): string
    {
        return 'list_signals';
    }

    public function describe(): string
    {
        return 'list_signals {"product_key": "a product key from the list you were given" (optional), "status": "New" | "Reviewed" | "Follow-up" | "Dismissed" (optional), '
            . '"since": "YYYY-MM-DD" (optional), "until": "YYYY-MM-DD" (optional)}. Lists market signals, newest first, at most 10, with how many matched. '
            . 'since and until bound the date a signal was last decided on, or found if nobody has decided on it. A dismissed signal carries the reason a person gave.';
    }

    protected function allowed(): array
    {
        return ['product_key', 'status', 'since', 'until'];
    }

    protected function normalise(AskCaller $caller, array $args): array
    {
        $product = null;
        if (isset($args['product_key']) && $args['product_key'] !== '') {
            $given = is_string($args['product_key']) ? $args['product_key'] : '';
            $product = ProductCatalog::normalise($given, $caller->tenantId);
            if ($product === null) {
                $known = implode(', ', array_keys(ProductCatalog::definitionsFor($caller->tenantId)));
                throw new Rejected('unknown_product', "product_key must be one of: {$known}.");
            }
        }
        $status = null;
        if (isset($args['status']) && $args['status'] !== '') {
            $status = self::status($args['status']) ?? throw new Rejected('bad_status', 'status must be New, Reviewed, Follow-up or Dismissed.');
        }
        $since = self::date($args['since'] ?? null, 'since');
        $until = self::date($args['until'] ?? null, 'until');
        if ($since !== null && $until !== null && $since > $until) {
            throw new Rejected('bad_date_range', 'since is after until.');
        }

        return ['product_key' => $product, 'status' => $status, 'since' => $since, 'until' => $until];
    }

    public function run(AskCaller $caller, array $args): ToolResult
    {
        if (! AskRights::canReadSignals($caller)) {
            return new ToolResult($this->name(), $args, ToolResult::NOT_PERMITTED, [], [AskRights::plural('signal')]);
        }
        $found = AskSignals::list($caller, $args['product_key'], $args['status'], $args['since'], $args['until'], (int) config('ask.max_signal_rows', 10));

        $said = 'signals';
        if ($args['status'] !== null) {
            $said = strtolower($args['status']) . ' signals';
        }
        if ($args['product_key'] !== null) {
            $said .= ' for ' . (ProductCatalog::definitionsFor($caller->tenantId)[$args['product_key']]['label'] ?? $args['product_key']);
        }
        if ($args['since'] !== null || $args['until'] !== null) {
            $said .= ' between ' . ($args['since'] ?? 'the start') . ' and ' . ($args['until'] ?? 'today');
        }

        return new ToolResult($this->name(), $args, ToolResult::OK, $found['rows'], [], $found['total'], 'in ' . $said);
    }

    private static function status(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = str_replace(['-', '_', ' '], '', strtolower(trim($value)));

        return match ($v) {
            'new' => 'New',
            'reviewed' => 'Reviewed',
            'followup', 'contacted' => 'Follow-up',
            'dismissed' => 'Dismissed',
            default => null,
        };
    }

    /** @throws Rejected */
    private static function date(mixed $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new Rejected('bad_date', "{$name} must be a real date written YYYY-MM-DD.");
        }

        return $value;
    }
}
