<?php

namespace App\Domain\AI\Support;

use App\Domain\AI\Configuration\ResolvedAiConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One tenant-aware lookup for provider credentials, for every AI call site.
 *
 * Ported unchanged in behaviour from LMS K-12, because the problem it solves
 * exists here too and in a worse form: G2G's AI callers read their keys straight
 * out of `config('gemini.api_key')` and `config('deepseek.api_key')`, so every
 * organisation on the platform shares one credential and no organisation can hold
 * its own.
 *
 * THE RULE
 *
 * An organisation's own key wins; the platform key (`sub_institute_id IS NULL`) is
 * the fallback; within either, the newest active row wins, which is what a rotated
 * key expects. A caller that names no organisation gets platform keys only.
 *
 * NOTHING BREAKS THE DAY THIS LANDS
 *
 * With `ai_api_keys` empty, every lookup falls through to `$envFallback`, which is
 * the config value the caller was already using. Behaviour changes only once an
 * administrator saves a credential on the AI Providers screen — which is the
 * point: the capability exists before it is needed rather than being retrofitted
 * after an organisation asks why its key is ignored.
 */
class ProviderKeyResolver
{
    /**
     * The credential for one provider type, for one organisation.
     *
     * @param  string  $apiType  The `ai_api_keys.api_type` value, e.g. `gemini`.
     * @param  int|string|null  $subInstituteId  The signed-in organisation, or null for platform-only.
     * @param  string|null  $envFallback  Used when the pool has nothing, so a key-table
     *                                    outage degrades rather than takes AI down.
     * @return array{api_key:string, api_limit:int|null, id:int|string|null, scope:string}|null
     */
    public function resolve(string $apiType, int|string|null $subInstituteId = null, ?string $envFallback = null): ?array
    {
        $row = $this->fromPool($apiType, $subInstituteId);

        if ($row !== null) {
            return $row;
        }

        $envKey = trim((string) $envFallback, " \t\n\r\0\x0B'\"");

        if ($envKey === '') {
            return null;
        }

        return ['api_key' => $envKey, 'api_limit' => null, 'id' => null, 'scope' => 'env'];
    }

    /**
     * Every credential this organisation may use for a provider, in the order failover
     * should try them: the first non-empty tier of organisation rows → platform rows →
     * environment keys, newest row first. Tiers are never mixed, so an organisation
     * that has its own keys does not silently spend the platform's when they run out,
     * and no organisation ever sees another organisation's rows.
     *
     * `$envKeys` is the environment tier (first element is the primary env key).
     *
     * @param  list<string>  $envKeys
     * @return list<array{api_key:string, api_limit:int|null, id:int|string|null, scope:string}>
     */
    public function candidates(string $apiType, int|string|null $subInstituteId = null, array $envKeys = []): array
    {
        $institute = $subInstituteId === null ? '' : trim((string) $subInstituteId);

        if ($institute !== '') {
            $own = $this->poolRows($apiType, $institute);

            if ($own !== []) {
                return $own;
            }
        }

        $platform = $this->poolRows($apiType, null);

        if ($platform !== []) {
            return $platform;
        }

        $out = [];
        $seen = [];

        foreach ($envKeys as $envKey) {
            $envKey = trim((string) $envKey, " \t\n\r\0\x0B'\"");

            if ($envKey === '' || isset($seen[$envKey])) {
                continue;
            }

            $seen[$envKey] = true;
            $out[] = ['api_key' => $envKey, 'api_limit' => null, 'id' => null, 'scope' => 'env'];
        }

        return $out;
    }

    /**
     * Other active credentials owned by exactly the same owner (an organisation, or the
     * platform when `$owner` is null) for the provider. Used to extend a module-specific
     * or bound credential with failover siblings without crossing organisations.
     *
     * @param  list<int|string>  $excludeIds
     * @return list<array{api_key:string, api_limit:int|null, id:int|string|null, scope:string}>
     */
    public function siblings(string $apiType, int|string|null $owner, array $excludeIds = []): array
    {
        $owner = $owner === null ? null : trim((string) $owner);

        return array_values(array_filter(
            $this->poolRows($apiType, $owner === '' ? null : $owner),
            fn ($row) => ! in_array($row['id'], $excludeIds, false)
        ));
    }

    /**
     * Active, non-placeholder rows owned by exactly one owner, newest first.
     *
     * @return list<array{api_key:string, api_limit:int|null, id:int|string|null, scope:string}>
     */
    private function poolRows(string $apiType, ?string $owner): array
    {
        if (! Schema::hasTable('ai_api_keys')) {
            return [];
        }

        try {
            $query = DB::table('ai_api_keys')->where('api_type', $apiType)->where('status', 1);

            if (app(SchemaCache::class)->hasColumn('ai_api_keys', 'sub_institute_id')) {
                $owner === null ? $query->whereNull('sub_institute_id') : $query->where('sub_institute_id', $owner);
            } elseif ($owner !== null) {
                return [];
            }

            $out = [];

            foreach ($query->orderByDesc('id')->get() as $row) {
                $key = trim((string) ($row->api_key ?? ''));

                if ($key === '' || $key === '-' || ResolvedAiConfiguration::isPlaceholder($key)) {
                    continue;
                }

                $out[] = [
                    'api_key' => $key,
                    'api_limit' => isset($row->api_limit) ? (int) $row->api_limit : null,
                    'id' => $row->id ?? null,
                    'scope' => $owner === null ? 'platform' : 'institute',
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{api_key:string, api_limit:int|null, id:int|string|null, scope:string}|null */
    private function fromPool(string $apiType, int|string|null $subInstituteId): ?array
    {
        if (! Schema::hasTable('ai_api_keys')) {
            return null;
        }

        try {
            $query = DB::table('ai_api_keys')
                ->where('api_type', $apiType)
                ->where('status', 1);

            $hasTenantColumn = app(SchemaCache::class)->hasColumn('ai_api_keys', 'sub_institute_id');

            if ($hasTenantColumn) {
                $institute = $subInstituteId === null ? null : trim((string) $subInstituteId);

                if ($institute === null || $institute === '') {
                    $query->whereNull('sub_institute_id');
                } else {
                    $query->where(function ($inner) use ($institute) {
                        $inner->where('sub_institute_id', $institute)
                            ->orWhereNull('sub_institute_id');
                    });
                    // An organisation's own key beats the platform key. Ordering
                    // rather than two queries keeps it one round trip.
                    $query->orderByRaw('sub_institute_id IS NULL ASC');
                }
            }

            // Newest active row wins — the convention a rotated key expects, and what
            // stops an older dead key being picked ahead of the one that works.
            $row = $query->orderByDesc('id')->first();

            if ($row === null || empty($row->api_key) || trim((string) $row->api_key) === '-') {
                return null;
            }

            $rowInstitute = $hasTenantColumn ? ($row->sub_institute_id ?? null) : null;

            return [
                'api_key' => trim((string) $row->api_key),
                'api_limit' => isset($row->api_limit) ? (int) $row->api_limit : null,
                'id' => $row->id ?? null,
                'scope' => $rowInstitute === null || $rowInstitute === '' ? 'platform' : 'institute',
            ];
        } catch (Throwable) {
            // A key-table outage falls through to the caller's env fallback rather
            // than failing the generation outright.
            return null;
        }
    }
}
