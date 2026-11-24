<?php

namespace App\Domain\Signals\Sources;

/**
 * The government and tender source register (config/signals_gov_sources.php), read as data. Pure: no network, no database, nothing fetched.
 *
 * It answers "which government sources may a Government (B2G) run read?". Only B2G runs of the non-school products use it: a school product, or a
 * business (B2B) or partner (B2C) run, gets nothing from here. Sources that need a feed are listed so they can be arranged, but are never offered
 * for reading.
 */
final class GovSourceRegistry
{
    public const USABLE = 'usable';

    public const REVIEW = 'needs_review';

    public const FEED = 'needs_feed';

    /** Products whose own sources are in the education inventory, not here. */
    private const SCHOOL_PRODUCTS = ['scholarclone', 'scholar'];

    /** @param array<string, mixed>|null $config injected by tests */
    public function __construct(private readonly ?array $config = null) {}

    /** @return list<array{id: string, name: string, url: string, group: string, status: string, note: string}> */
    public function all(): array
    {
        $cfg = $this->config ?? (array) config('signals_gov_sources', []);
        $rows = [];
        foreach ((array) ($cfg['sources'] ?? []) as $r) {
            [$id, $name, $url, $group, $status, $note] = array_pad((array) $r, 6, '');
            if ($id === '' || ! preg_match('#^https://#i', (string) $url)) {
                continue;   // a row without an id or a secure address is not a source
            }
            $rows[] = ['id' => (string) $id, 'name' => (string) $name, 'url' => (string) $url, 'group' => (string) $group, 'status' => (string) $status, 'note' => (string) $note];
        }

        return $rows;
    }

    /**
     * What a run may read directly. Government (B2G) runs of a non-school product get the `usable` sources; anything else gets none.
     *
     * @return list<array<string, string>>
     */
    public function readableFor(string $productKey, string $customerModel): array
    {
        if (strtolower($customerModel) !== 'b2g' || in_array(strtolower($productKey), self::SCHOOL_PRODUCTS, true)) {
            return [];
        }

        return array_values(array_filter($this->all(), fn ($r) => $r['status'] === self::USABLE));
    }

    /** Sources that cannot be read today and need a feed, an export or manual entry. @return list<array<string, string>> */
    public function needingFeed(): array
    {
        return array_values(array_filter($this->all(), fn ($r) => $r['status'] === self::FEED));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $c = [];
        foreach ($this->all() as $r) {
            $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
        }

        return $c;
    }
}
