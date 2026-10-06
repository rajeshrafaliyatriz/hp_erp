<?php

namespace App\Domain\AI\Configuration;

use App\Domain\AI\Support\ProviderKeyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One answer to "what should this module call", for every AI module.
 *
 * THE PRECEDENCE, AND WHY IT IS THIS ORDER
 *
 * Six rules, narrowest first. Each step is strictly more specific than the one
 * below it, so the most deliberate thing anyone has said always wins:
 *
 *   1. `module`          — this organisation saved a row for this module. The most
 *                          specific statement anyone can make, so nothing overrides it.
 *   2. `module_platform` — the platform saved a row for this module, for everyone.
 *   3. `pool`            — this organisation's key for the configured driver.
 *   4. `pool_platform`   — the platform's key for the configured driver.
 *   5. `env`             — the driver's key from `config/ai.php`. **This is where every
 *                          module on this deployment resolves today**, which is why
 *                          nothing changes until somebody saves a row.
 *   6. `config`          — no key at all: provider and model resolved, credential
 *                          missing. Returned rather than thrown so the caller can say
 *                          "not configured" instead of "something failed".
 *
 * Steps 3-6 are not reimplemented here — they are `ProviderKeyResolver`, called. This
 * class wraps it and adds the module dimension in front, so a caller with no module
 * gets byte-identical behaviour and the two cannot drift apart later.
 *
 * WHICH PROVIDER WHEN NOBODY SAID
 *
 * Only a module row may name a provider. With no module row the provider is
 * `config('ai.provider.driver')` — the same value the existing callers use to pick a
 * client — and the credential is then looked up *for that provider*. The inverse,
 * taking the newest key and inferring the provider from it, would silently move an
 * unconfigured module onto a provider it is not running on.
 *
 * NOTHING HERE READS REQUEST INPUT
 *
 * `$subInstituteId` comes from the caller's `AiRequestScope`, which is derived from
 * their Sanctum token. This class never reads the request, so no caller can resolve
 * another organisation's credential by naming it.
 */
final class AiConfigurationResolver
{
    public function __construct(
        private readonly ProviderCatalog $providers,
        private readonly ModelCatalog $models,
        private readonly AiModuleRegistry $modules,
        private readonly ProviderKeyResolver $keys,
        private readonly ModuleModelBindings $bindings,
        private readonly \App\Domain\AI\Support\SchemaCache $schema,
    ) {
    }

    /**
     * Resolve the provider, model and credential for one module.
     *
     * @param  string|null  $moduleKey      A key from `AiModuleRegistry`, or null for the
     *                                      unbound pool behaviour every legacy caller has.
     * @param  string|null  $productModule  An `ai_modules` key (`lms_course_builder`, ...)
     *                                      when the call is made from inside one module's
     *                                      screen. Step 0 below — that module's own AI Stack
     *                                      Models choice — applies only when this is given,
     *                                      so every caller that omits it resolves exactly
     *                                      as it did before module bindings existed.
     */
    public function resolve(
        ?string $moduleKey,
        int|string|null $subInstituteId = null,
        ?string $productModule = null
    ): ResolvedAiConfiguration {
        // Step 0. This product module's own choice for this capability, saved from its
        // AI Stack Models tab. Institute row first, then platform.
        if ($productModule !== null && $moduleKey !== null) {
            $binding = $this->bindings->find($productModule, $moduleKey, $subInstituteId);

            if ($binding !== null && $this->providers->exists((string) $binding->provider)) {
                return $this->fromBinding($binding, $subInstituteId);
            }
        }

        $moduleKey = $moduleKey !== null && $this->modules->exists($moduleKey) ? $moduleKey : null;

        // Steps 1-2. Only a module row may choose the provider, because only a module
        // row was saved by someone who meant to choose one.
        $row = $this->findModuleRow($moduleKey, $subInstituteId);

        if ($row !== null) {
            $provider = $this->providerFromRow($row);

            return new ResolvedAiConfiguration(
                provider: $provider,
                model: $this->modelFor($provider, $row->model ?? null, $subInstituteId),
                apiKey: trim((string) $row->api_key),
                source: $row->source,
                keyId: $row->id ?? null,
                scope: ($row->sub_institute_id ?? null) === null ? 'platform' : 'institute',
                maxOutputTokens: $this->maxTokens($row),
                alternates: $this->failoverSiblings($provider, $row->sub_institute_id ?? null, [$row->id ?? null], $moduleKey),
            );
        }

        // Steps 3-6. Nothing was saved for this module, so the provider is the
        // configured driver and the credential is that driver's own — which is
        // precisely `ProviderKeyResolver`, delegated to rather than reimplemented.
        $provider = $this->defaultProvider();

        $alternates = [];

        if ($this->failoverEnabled($provider)) {
            // Health-aware failover list: first non-empty tier (organisation → platform
            // → env), newest first. The head is the primary exactly as `resolve()` used
            // to pick it, so every non-failover caller sees the same credential.
            $candidates = $this->keys->candidates(
                $this->providers->apiType($provider),
                $subInstituteId,
                $this->providers->envKeys($provider),
                $moduleKey,
            );
            $key = $candidates[0] ?? null;
            $alternates = array_slice($candidates, 1);
        } else {
            $key = $this->keys->resolve(
                $this->providers->apiType($provider),
                $subInstituteId,
                $this->providers->envKey($provider),
            );
        }

        return new ResolvedAiConfiguration(
            provider: $provider,
            model: $this->modelFor($provider, null, $subInstituteId),
            apiKey: $key['api_key'] ?? null,
            source: $this->sourceFor($key),
            keyId: $key['id'] ?? null,
            scope: $key['scope'] ?? 'config',
            maxOutputTokens: $this->poolMaxTokens($key, $provider),
            alternates: $alternates,
        );
    }

    /** Whether quota/invalid-key failures for this provider rotate across credentials. */
    public function failoverEnabled(string $provider): bool
    {
        return in_array($provider, (array) config('ai.failover.providers', ['gemini']), true);
    }

    /**
     * Other active credentials of the same owner, for failover after a module-specific
     * or bound credential. Empty when failover is off for the provider.
     *
     * @param  list<int|string|null>  $excludeIds
     * @return list<array{api_key:string, api_limit:int|null, id:int|string|null, scope:string}>
     */
    private function failoverSiblings(string $provider, int|string|null $owner, array $excludeIds, ?string $module = null): array
    {
        if (! $this->failoverEnabled($provider)) {
            return [];
        }

        return $this->keys->siblings($this->providers->apiType($provider), $owner, array_filter($excludeIds, fn ($id) => $id !== null), $module);
    }

    /**
     * A module binding turned into a resolution.
     *
     * The binding chooses the provider and model. The credential is the one it names when
     * it names one this institute can see; otherwise that provider's own pool/env key —
     * the same lookup an unbound call makes, so a binding never invents a credential.
     */
    private function fromBinding(object $binding, int|string|null $subInstituteId): ResolvedAiConfiguration
    {
        $provider = (string) $binding->provider;
        $apiKey = null;
        $keyId = null;
        $scope = ($binding->sub_institute_id ?? null) === null ? 'platform' : 'institute';
        $limit = null;
        $alternates = [];

        if (($binding->api_key_id ?? null) !== null) {
            try {
                $row = DB::table('ai_api_keys')
                    ->where('id', (int) $binding->api_key_id)
                    ->where('status', 1)
                    ->where(fn ($q) => $q->whereNull('sub_institute_id')->orWhere('sub_institute_id', $subInstituteId))
                    ->first();
            } catch (Throwable) {
                $row = null;
            }

            if ($row !== null && ! empty($row->api_key) && trim((string) $row->api_key) !== '-') {
                $apiKey = trim((string) $row->api_key);
                $keyId = (int) $row->id;
                $limit = $row->api_limit ?? null;
                $alternates = $this->failoverSiblings($provider, $row->sub_institute_id ?? null, [$row->id]);
            }
        }

        if ($apiKey === null) {
            if ($this->failoverEnabled($provider)) {
                $candidates = $this->keys->candidates($this->providers->apiType($provider), $subInstituteId, $this->providers->envKeys($provider));
                $key = $candidates[0] ?? null;
                $alternates = array_slice($candidates, 1);
            } else {
                $key = $this->keys->resolve(
                    $this->providers->apiType($provider),
                    $subInstituteId,
                    $this->providers->envKey($provider),
                );
            }
            $apiKey = $key['api_key'] ?? null;
            $keyId = $key['id'] ?? null;
            $limit = $key['api_limit'] ?? null;
        }

        $max = $binding->max_output_tokens ?? null;

        if (is_numeric($max) && (int) $max > 0) {
            $maxTokens = (int) $max;
        } elseif (is_numeric($limit) && (int) $limit > 0) {
            $maxTokens = (int) $limit;
        } else {
            $maxTokens = ((int) config("ai.provider.{$provider}.max_output_tokens")) ?: null;
        }

        return new ResolvedAiConfiguration(
            provider: $provider,
            model: $this->modelFor($provider, $binding->model ?? null, $subInstituteId),
            apiKey: $apiKey,
            source: (string) ($binding->source ?? 'module_binding'),
            keyId: $keyId,
            scope: $scope,
            maxOutputTokens: $maxTokens,
            alternates: $alternates,
        );
    }

    /**
     * How a pool credential was found, in this class's vocabulary.
     *
     * `ProviderKeyResolver` reports `institute`, `platform` or `env`; the overview
     * screen and the logs speak in precedence steps, so they are mapped here rather
     * than leaking two vocabularies for one fact.
     *
     * @param  array<string, mixed>|null  $key
     */
    private function sourceFor(?array $key): string
    {
        return match ($key['scope'] ?? null) {
            'institute' => 'pool',
            'platform' => 'pool_platform',
            'env' => 'env',
            default => 'config',
        };
    }

    /**
     * The pool row's own ceiling, else the provider's configured one.
     *
     * @param  array<string, mixed>|null  $key
     */
    private function poolMaxTokens(?array $key, string $provider): ?int
    {
        $limit = $key['api_limit'] ?? null;

        if (is_numeric($limit) && (int) $limit > 0) {
            return (int) $limit;
        }

        return ((int) config("ai.provider.{$provider}.max_output_tokens")) ?: null;
    }

    /**
     * What every module resolves to right now, for the admin screen's list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(int|string|null $subInstituteId = null): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            $config = $this->resolve($module['key'], $subInstituteId);

            $out[] = [
                'module' => $module['key'],
                'module_label' => $module['label'],
                'description' => $module['description'],
                'wired' => $module['wired'],
                'provider' => $config->provider,
                'provider_label' => $this->providers->label($config->provider),
                'model' => $config->model,
                'source' => $config->source,
                'scope' => $config->scope,
                'key_id' => $config->keyId,
                'has_key' => $config->hasKey(),
                'driveable' => $this->providers->isDriveable($config->provider),
            ];
        }

        return $out;
    }

    /**
     * The module's own row — this organisation's first, then the platform's.
     *
     * Two ordered queries rather than one clever one: the precedence is the part of
     * this class most likely to be read in an incident, and two named steps are worth
     * more then than a saved round trip.
     */
    private function findModuleRow(?string $moduleKey, int|string|null $subInstituteId): ?object
    {
        if ($moduleKey === null || ! $this->schema->hasTable('ai_api_keys') || ! $this->schema->hasColumn('ai_api_keys', 'ai_module')) {
            return null;
        }

        $institute = $subInstituteId === null ? null : trim((string) $subInstituteId);
        $institute = $institute === '' ? null : $institute;

        $attempts = [];

        if ($institute !== null) {
            $attempts[] = ['module', fn ($q) => $q->where('ai_module', $moduleKey)->where('sub_institute_id', $institute)];
        }

        $attempts[] = ['module_platform', fn ($q) => $q->where('ai_module', $moduleKey)->whereNull('sub_institute_id')];

        foreach ($attempts as [$source, $filter]) {
            try {
                $query = DB::table('ai_api_keys')->where('status', 1);
                $filter($query);

                // Newest active row wins, matching ProviderKeyResolver: the convention
                // a rotated key expects, and what stops a dead older key being picked.
                $row = $query->orderByDesc('id')->first();
            } catch (Throwable) {
                // A key-table outage falls through to the env fallback rather than
                // failing the call outright.
                return null;
            }

            if ($row === null || empty($row->api_key) || trim((string) $row->api_key) === '-') {
                continue;
            }

            $row->source = $source;

            return $row;
        }

        return null;
    }

    /**
     * Which provider a credential row belongs to.
     *
     * A row saved by the admin screen carries a provider key in `api_type`. Rows that
     * predate it carry whatever convention was current when they were written, so
     * each catalogue entry's declared `api_type` is matched before falling back to
     * the active driver.
     */
    private function providerFromRow(object $row): string
    {
        $apiType = trim((string) ($row->api_type ?? ''));

        if ($apiType === '') {
            return $this->defaultProvider();
        }

        if ($this->providers->exists($apiType)) {
            return $apiType;
        }

        foreach ($this->providers->keys() as $provider) {
            if (strcasecmp($this->providers->apiType($provider), $apiType) === 0) {
                return $provider;
            }
        }

        return $this->defaultProvider();
    }

    /** The saved model, else the catalogue's first for this provider, else config's. */
    private function modelFor(string $provider, ?string $saved, int|string|null $subInstituteId): ?string
    {
        $saved = trim((string) $saved);

        if ($saved !== '') {
            return $saved;
        }

        return $this->models->defaultFor($provider, $subInstituteId)
            ?? $this->providers->defaultModel($provider);
    }

    /**
     * `api_limit` doubles as the per-key output ceiling, which is the meaning the
     * column already carries, so it keeps it here rather than gaining a column.
     */
    private function maxTokens(object $row): ?int
    {
        $limit = $row->api_limit ?? null;

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    private function defaultProvider(): string
    {
        $driver = trim((string) config('ai.provider.driver', 'gemini'));

        return $this->providers->exists($driver) ? $driver : 'gemini';
    }
}
