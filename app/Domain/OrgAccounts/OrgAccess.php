<?php

namespace App\Domain\OrgAccounts;

use App\Support\MenuRight;

/**
 * Who may do what with Organisation 360, which now lives INSIDE the Organizations screen (Account 360 tab, scope switch) and has no menu row of its own.
 * The rights are the Organizations screen's: a role that holds the right on EITHER the Marketing or the Support Organizations menu row, the same `|`
 * rule routes/api.php applies to the CRM organization routes (`platformright:{$orgLink}|{$orgLinkSupport},{action}`):
 *
 *   view -> read the 360, the list and the suggestions; edit -> change lifecycle/owner and confirm or reject a link; add -> create the CRM organisation.
 *
 * There is no administrator bypass: a profile needs a rights row. routes/org_accounts.php puts the same two links in front of every route; this class
 * tells the screen which buttons to show (GET /meta).
 */
final class OrgAccess
{
    /** The menu rows the Organizations screen is reached by, in routes/api.php's `$orgLink` and `$orgLinkSupport`. */
    public const SCREEN_LINKS = ['/module/crm/marketing/organizations', '/module/crm/support/organizations'];

    /** The route middleware for one action: `platformright:<marketing>|<support>,<action>`. */
    public static function middleware(string $action): string
    {
        return 'platformright:' . implode('|', self::SCREEN_LINKS) . ',' . $action;
    }

    /** Whether this person holds the right on the Organizations screen. @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function canUseScreen(array $identity, string $action): bool
    {
        foreach (self::SCREEN_LINKS as $link) {
            $menuId = MenuRight::idForAccessLink($link);
            if ($menuId !== null && MenuRight::can((int) ($identity['user']->user_profile_id ?? 0), $menuId, $identity['sub_institute_id'], $action)) {
                return true;
            }
        }

        return false;
    }
}
