<?php

namespace App\Domain\Intelligence\Detectors\Platform;

/**
 * THE MENU ROWS THAT GATE PLATFORM, AI GOVERNANCE AND AGENTIC INSIGHTS. Intelligence grants nothing new: a caller needs `view` on the same menu row the product's
 * own route guard asks for on the screen the insight links to (MenuRightsChecker, no administrator bypass: a profile needs a rights row, as everywhere else).
 *
 * Where each link comes from (all verified in this repository):
 *   Scheduler and Integrations   routes/platform.php `platformright:/platform-services/workflow?module={module},view`. The `{module}` template admits ANY of the
 *                                seven decentralized modules (RequirePlatformRight::DECENTRALIZED_MODULES), so the caller needs the row of at least one.
 *   Event bus                    routes/platform.php `platformright:/platform-services/event-bus,view`.
 *   AI usage and cost, policies  routes/ai.php `platformright:/ai/usage-cost,view` and `/ai/policies,view`.
 *   Agentic AI                   routes/api.php `prefix('agentic')` has NO rights middleware (a known gap), so Intelligence does not copy it: it asks for `view` on
 *                                one of the Agentic AI screens that show runs and cost (the same menu rows the frontend's access-link constants name).
 */
final class PlatformAccess
{
    /** The seven decentralized modules RequirePlatformRight accepts for a `{module}` link. */
    public const PLATFORM_MODULES = ['organization', 'hrms', 'talent', 'lms', 'competency', 'task', 'crm'];

    public const LINK_EVENT_BUS = '/platform-services/event-bus';
    public const LINK_AI_USAGE_COST = '/ai/usage-cost';
    public const LINK_AI_POLICIES = '/ai/policies';

    /** @return list<string> */
    public static function platformModuleLinks(): array
    {
        return array_map(static fn (string $m): string => "/platform-services/workflow?module={$m}", self::PLATFORM_MODULES);
    }

    /** @return list<string> */
    public static function agenticLinks(): array
    {
        return [
            '/module/agentic-ai/run-log',
            '/module/agentic-ai/analytics',
            '/module/agentic-ai/agent-dashboard',
            '/module/agentic-ai/reflection',
        ];
    }
}
