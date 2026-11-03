<?php

namespace App\Domain\ProductMaster;

use App\Support\MenuRight;
use App\Support\RoleKey;

/**
 * Who may write what through the Product Master. The Products screen's menu right (checked by the route) says who may use the screen; these checks say who
 * may touch the records it writes THROUGH, so the Product Master is never a way around the rights those screens already have:
 *
 *   - a product (top level) can be created and archived only by an administrator;
 *   - the Product Portfolio part (an offer) needs what the Portfolio itself needs: the twin of ModuleGate::signals('/portfolio', 'add', 'write'),
 *     which is the administrator or HR roles while signals.permissions.use_menu_rights is off, and the menu right on /portfolio once it is on;
 *   - the CRM catalogue part needs the add or edit right on Products or Services, the same rows CrmProductController checks per item type.
 */
final class ProductAccess
{
    private const CATALOGUE_LINKS = ['product' => '/module/crm/sales/products', 'service' => '/module/crm/sales/services'];

    /** The Product Master has no screen or menu row of its own: it is part of the Products screen, so its rights are that row's. */
    private const SCREEN_LINK = '/module/crm/sales/products';

    /** The Products screen's right (the route checks it too); used to tell the screen which buttons to show. @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function canUseScreen(array $identity, string $action): bool
    {
        return self::can($identity, self::SCREEN_LINK, $action);
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function isAdministrator(array $identity): bool
    {
        return RoleKey::forProfileId(self::profileId($identity)) === 'administrator';
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function canWriteOffers(array $identity, string $action = 'add'): bool
    {
        if (! config('signals.permissions.use_menu_rights')) {
            return in_array(RoleKey::forProfileId(self::profileId($identity)), ['administrator', 'hr_manager', 'hr_executive'], true);
        }

        return self::can($identity, '/portfolio', $action);
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public static function canWriteCatalogue(array $identity, string $itemType, string $action): bool
    {
        $link = self::CATALOGUE_LINKS[$itemType] ?? null;

        return $link !== null && self::can($identity, $link, $action);
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    private static function can(array $identity, string $link, string $action): bool
    {
        $menuId = MenuRight::idForAccessLink($link);

        return $menuId !== null && MenuRight::can(self::profileId($identity), $menuId, $identity['sub_institute_id'], $action);
    }

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    private static function profileId(array $identity): int
    {
        return (int) ($identity['user']->user_profile_id ?? 0);
    }
}
