<?php

namespace App\Domain\Signals;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\ProviderCatalog;
use App\Domain\Signals\Support\SignalsAiModule;

/**
 * Answers one question for the Signals engine: can this organisation call an AI provider? (Never reveals the key.)
 * The old per-department HR signal generator that used to live here has been removed: Signals is ScholarClone buying signals only.
 */
class SignalRunner
{
    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly ProviderCatalog $providers,
    ) {
    }

    public function aiConfigured(int $tenantId): bool
    {
        try {
            $config = $this->configuration->resolve(app(SignalsAiModule::class)->key($tenantId), $tenantId);

            return $config->hasKey() && $this->providers->isDriveable($config->provider);
        } catch (\Throwable) {
            return false;
        }
    }
}
