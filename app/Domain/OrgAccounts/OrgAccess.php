<?php

namespace App\Domain\OrgAccounts;

use App\Support\MenuRight;

/**
 * Who may do what through Organisation 360. The screen's own menu right (checked by the route) says who may use the screen; these checks say who may
 * touch the records it writes THROUGH, so the screen is never a way around the rights the CRM already has:
 *
 *   - creating a CRM organisation needs the screen's add right AND the add right of the CRM Organisations screen. routes/api.php lets a role that holds
 *     the right on EITHER of the Marketing or Support organisation menu rows use the CRM store route (`platformright:{$orgLink}|{$orgLinkSupport},add`),
 *     so this accepts exactly the same two rows. There is no administrator bypass: a profile needs a rights row.
 */
final class OrgAccess
{
    public const SCREEN_LINK = '/module/crm/organisation-360';

    /** The menu rows the CRM organisation store route accepts (routes/api.php: `$orgLink` and `$orgLinkSupport`). */
    private const CRM_ORGANIZATION_LINKS = ['/module/crm/marketing/organizations', '/module/crm/support/organizations'];

    /** The screen's own right; the route checks it too, this tells the screen which buttons to show. @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function canUseScreen(array $identity, string $action): bool
    {
        return self::can($identity, self::SCREEN_LINK, $action);
    }

    /** The CRM's own add right for an organisation, the one CrmOrganizationController::store sits behind. @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function canAddCrmOrganization(array $identity): bool
    {
        foreach (self::CRM_ORGANIZATION_LINKS as $link) {
            if (self::can($identity, $link, 'add')) {
                return true;
            }
        }

        return false;
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    private static function can(array $identity, string $link, string $action): bool
    {
        $menuId = MenuRight::idForAccessLink($link);

        return $menuId !== null && MenuRight::can((int) ($identity['user']->user_profile_id ?? 0), $menuId, $identity['sub_institute_id'], $action);
    }
}
