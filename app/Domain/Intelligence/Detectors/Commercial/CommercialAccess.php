<?php

namespace App\Domain\Intelligence\Detectors\Commercial;

use App\Domain\Intelligence\Access\IntelligenceCaller;

/**
 * WHO MAY SEE COMMERCIAL INSIGHTS (decision D-9 default): only a profile that ALREADY holds `view` on the screen the insight links to. Intelligence grants nothing
 * new, so it can never show a person more than the screen itself shows them. There is no sales role among the nine role keys, hence no role-based grant, with
 * ONE exception that mirrors the product's own Signals gate (App\Domain\Ask\AskRights::canReadSignals):
 *
 *   signals.permissions.use_menu_rights OFF -> administrator, hr_manager, hr_executive and reporting_manager may read signals (the route vocabulary admin|hr|manager)
 *   signals.permissions.use_menu_rights ON  -> the profile needs `view` on the /signals menu row
 *
 * The flag is a constructor argument so detectors and tests never call config(); only `fromConfig()` reads it.
 */
final class CommercialAccess
{
    /** The roles the Signals routes admit while menu rights are not switched on (RoleKey aliases admin|hr|manager). */
    public const SIGNALS_FALLBACK_ROLES = ['administrator', 'hr_manager', 'hr_executive', 'reporting_manager'];

    public const LINK_SIGNALS = '/signals';
    public const LINK_SIGNAL_REVIEW = '/signal-review';
    public const LINK_GTM_DEALS = '/gtm/deals';
    public const LINK_GTM_COMMAND_CENTER = '/gtm/command-center';
    public const LINK_GTM_CUSTOMERS = '/gtm/customers';
    public const LINK_CRM_OPPORTUNITIES = '/module/crm/sales/opportunities';
    public const LINK_CRM_INVOICES = '/module/crm/sales/invoices';

    public function __construct(private readonly bool $signalsUseMenuRights)
    {
    }

    public static function fromConfig(): self
    {
        return new self((bool) config('signals.permissions.use_menu_rights'));
    }

    public function canSignals(IntelligenceCaller $caller): bool
    {
        if ($this->signalsUseMenuRights) {
            return $caller->canAny([self::LINK_SIGNALS], 'view');
        }

        return $caller->hasRole(...self::SIGNALS_FALLBACK_ROLES);
    }

    /** @param list<string> $links */
    public function canView(IntelligenceCaller $caller, array $links): bool
    {
        return $caller->canAny($links, 'view');
    }
}
