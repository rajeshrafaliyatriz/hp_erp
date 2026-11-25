<?php

namespace App\Domain\Signals\Support;

/**
 * The region a run is limited to when the request names none. Pure.
 *
 * India first: with nothing chosen, a run is aimed at India instead of "All Regions". Two rules keep that safe:
 *  - it is a RUN-TIME filter only. What a run stores as its own scope (`region_scope`, which is part of how a signal is told apart from another) stays
 *    what the person REQUESTED, so signals already found are not found again as new ones;
 *  - a request that names a region always wins, and a blank / 'none' setting switches the default off (All Regions, as before).
 */
final class DefaultRegion
{
    /**
     * @param  string|null  $configured  the configured default country name (signals.region.default), '' / null / 'none' = off
     * @return array{scope: ?RegionScope, source: string, label: ?string}  source: request | default | none
     */
    public static function resolve(?RegionScope $requested, ?string $configured): array
    {
        if ($requested !== null) {
            return ['scope' => $requested, 'source' => 'request', 'label' => $requested->label()];
        }
        $name = trim((string) $configured);
        if ($name === '' || in_array(strtolower($name), ['none', 'off', 'all', 'false', '0'], true)) {
            return ['scope' => null, 'source' => 'none', 'label' => null];
        }
        try {
            $scope = RegionScope::fromInput([['country' => $name]]);
        } catch (RegionInvalid) {
            return ['scope' => null, 'source' => 'none', 'label' => null];   // an unusable setting never stops a run
        }

        return $scope === null ? ['scope' => null, 'source' => 'none', 'label' => null] : ['scope' => $scope, 'source' => 'default', 'label' => $scope->label()];
    }
}
