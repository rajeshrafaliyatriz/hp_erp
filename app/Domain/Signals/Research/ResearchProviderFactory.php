<?php

namespace App\Domain\Signals\Research;

/**
 * Picks the research provider from config. Only drivers listed in DRIVERS exist;
 * anything else - including the default `none` - yields a provider that reports
 * itself as not configured, so generation continues on internal data alone.
 *
 * To add a real provider: implement ResearchProvider, register it below, and set
 * SIGNALS_RESEARCH_ENABLED=true and SIGNALS_RESEARCH_DRIVER=<key>.
 */
class ResearchProviderFactory
{
    /** @var array<string, class-string<ResearchProvider>> */
    private const DRIVERS = [];

    public static function make(): ResearchProvider
    {
        if (! config('signals.research.enabled')) {
            return new NullResearchProvider();
        }

        $class = self::DRIVERS[(string) config('signals.research.driver')] ?? null;

        return $class ? app($class) : new NullResearchProvider();
    }
}
