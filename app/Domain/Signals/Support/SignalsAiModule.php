<?php

namespace App\Domain\Signals\Support;

use App\Domain\AI\Configuration\AiConfigurationResolver;

/**
 * Which AI module's configuration Signals should call, for one organisation.
 *
 * Signals has its own module (`signals`), so an administrator can give it a provider and
 * model of its own under AI & Intelligence → AI Providers. Before that module existed,
 * Signals borrowed `analytics_ai`, and an organisation may already have a configuration
 * saved there. To keep that working exactly as before, the order is:
 *
 *   1. a configuration saved for `signals` (this organisation's, then the platform's),
 *   2. else one saved for the legacy `analytics_ai`,
 *   3. else `signals` — which then resolves to the deployment default, as every unconfigured
 *      module does.
 *
 * Only a row saved for the module counts (`module` / `module_platform`); the shared pool and
 * env fallbacks are the same for both names, so they cannot tell the two apart.
 */
final class SignalsAiModule
{
    private const SAVED = ['module', 'module_platform'];

    public function __construct(private readonly AiConfigurationResolver $configuration)
    {
    }

    public function key(int|string|null $tenantId): string
    {
        $preferred = (string) config('signals.ai_module', 'signals');
        $legacy = (string) config('signals.ai_module_legacy', 'analytics_ai');

        if ($this->hasSavedRow($preferred, $tenantId)) {
            return $preferred;
        }

        if ($legacy !== '' && $legacy !== $preferred && $this->hasSavedRow($legacy, $tenantId)) {
            return $legacy;
        }

        return $preferred;
    }

    private function hasSavedRow(string $module, int|string|null $tenantId): bool
    {
        return in_array($this->configuration->resolve($module, $tenantId)->source, self::SAVED, true);
    }
}
