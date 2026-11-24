<?php

namespace App\Domain\Intelligence\Access;

use App\Support\MenuRight;

/**
 * The production RightsChecker: the application's existing menu-rights rule (`tblgroupwise_rights_g2g`, individual DENY > group DENY > ... > DENY), with the
 * menu row found by `access_link` (the ids differ between hosts). No administrator bypass: a profile needs a rights row, exactly as everywhere else.
 * Nothing new is stored or granted: Intelligence reuses the rights that already protect the screen an insight links to.
 */
final class MenuRightsChecker implements RightsChecker
{
    public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool
    {
        if ($profileId <= 0) {
            return false;
        }
        $menuId = MenuRight::idForAccessLink($accessLink);

        return $menuId !== null && MenuRight::can($profileId, $menuId, $tenantId, $action);
    }
}
