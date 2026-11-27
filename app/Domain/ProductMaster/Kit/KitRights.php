<?php

namespace App\Domain\ProductMaster\Kit;

use App\Support\MenuRight;

/**
 * Who may do what in a Product's Sales Kit. There is no rule in code: the rights are the rows of the "Sales Kit" menu row (access_link `/portfolio/sales-kit`) in
 * tblgroupwise_rights_g2g, set in Role and Permissions. A profile with no row on it has no right, an administrator included. The routes ask for the same rights with
 * `platformright:/portfolio/sales-kit,<action>`; this class only tells the screen which buttons to show (`can` in the kit answer and in /api/product-master/meta).
 */
final class KitRights
{
    public const LINK = '/portfolio/sales-kit';

    public const ACTIONS = ['view', 'add', 'edit', 'delete'];

    /**
     * Whether the caller holds the right on the Sales Kit row. False when the row does not exist on this host or the kit's table is not there yet.
     *
     * @param array{user:object, user_id:int, sub_institute_id:int} $identity
     */
    public static function can(array $identity, string $action): bool
    {
        if (! ProductKit::ready()) {
            return false;
        }
        $menuId = MenuRight::idForAccessLink(self::LINK);

        return $menuId !== null && MenuRight::can((int) ($identity['user']->user_profile_id ?? 0), $menuId, $identity['sub_institute_id'], $action);
    }

    /**
     * @param array{user:object, user_id:int, sub_institute_id:int} $identity
     * @return array{view: bool, add: bool, edit: bool, delete: bool}
     */
    public static function all(array $identity): array
    {
        $none = ['view' => false, 'add' => false, 'edit' => false, 'delete' => false];
        if (! ProductKit::ready()) {
            return $none;
        }
        $menuId = MenuRight::idForAccessLink(self::LINK);
        if ($menuId === null) {
            return $none;
        }
        $profileId = (int) ($identity['user']->user_profile_id ?? 0);

        return [
            'view' => MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'view'),
            'add' => MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'add'),
            'edit' => MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'edit'),
            'delete' => MenuRight::can($profileId, $menuId, $identity['sub_institute_id'], 'delete'),
        ];
    }
}
