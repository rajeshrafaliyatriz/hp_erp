<?php

namespace App\Domain\Signals\Products;

/**
 * Whether the five capabilities ProductSignalRules talks about can be tied to the organisation's stored product parts. Pure diagnostics: it never
 * changes a qualification decision.
 *
 * The mapping comes only from config/signals_capability_map.php. When it is empty or not validated, every capability is reported `unmapped` with the
 * reason, and callers keep the existing behaviour. A mapped offer id that the organisation's catalogue does not hold is reported too (`stale`).
 */
final class CapabilityMapping
{
    public const UNMAPPED = 'unmapped';
    public const MAPPED = 'mapped';
    public const STALE = 'stale';

    /**
     * @param  list<string>  $capabilities  what ProductSignalRules matched for a piece of evidence
     * @param  array<string, mixed>  $parts  the product context's parts, keyed by offer id
     * @param  array{validated?: bool, map?: array<string, list<string>>}  $config
     * @return array{status: string, validated: bool, capabilities: array<string, array{status: string, offers: list<string>}>, note: string}
     */
    public static function explain(array $capabilities, array $parts, array $config): array
    {
        $validated = (bool) ($config['validated'] ?? false);
        $map = (array) ($config['map'] ?? []);
        $rows = [];
        foreach ($capabilities as $cap) {
            $offers = array_values(array_map('strval', (array) ($map[$cap] ?? [])));
            if ($offers === [] || ! $validated) {
                $rows[$cap] = ['status' => self::UNMAPPED, 'offers' => []];

                continue;
            }
            $known = array_values(array_filter($offers, fn ($o) => isset($parts[$o])));
            $rows[$cap] = ['status' => $known === [] ? self::STALE : self::MAPPED, 'offers' => $known];
        }
        $statuses = array_column($rows, 'status');
        $status = $rows === [] || in_array(self::UNMAPPED, $statuses, true) ? self::UNMAPPED : (in_array(self::STALE, $statuses, true) ? self::STALE : self::MAPPED);

        return [
            'status' => $status, 'validated' => $validated, 'capabilities' => $rows,
            'note' => $status === self::MAPPED
                ? 'Capabilities are tied to stored offers by a validated mapping.'
                : 'No validated capability-to-offer mapping: the capability note is an annotation and does not decide qualification (see config/signals_capability_map.php).',
        ];
    }

    /** Can the capability annotation be allowed to influence a decision? Only with a validated mapping that covers every capability named. */
    public static function usableForDecisions(array $explained): bool
    {
        return ($explained['status'] ?? null) === self::MAPPED && ($explained['validated'] ?? false) === true;
    }
}
