<?php

namespace App\Domain\OutreachGate;

use App\Support\MenuRight;
use App\Support\RoleKey;

/**
 * Who may preview the outreach checks of a draft, and who may read and write the approved claims (slice 10). There is NO menu row of its own (the owner's rule of
 * 3 Nov 2026: no duplicate screens): the preview belongs to the screens the drafts are on, and the claims to the Products screen.
 *
 *   preview a CRM email    VIEW on the Outreach Approvals row `/module/crm/marketing/outreach-approvals`
 *   preview a GTM message  VIEW on the GTM outreach row `/gtm/outreach` when GTM_USE_MENU_RIGHTS is on; otherwise the same administrator / executive profiles that
 *                          the GTM outreach routes themselves accept (ModuleGate::gtm: `profile:admin,executive`)
 *   the claims             `/module/crm/sales/products`: view lists, add proposes, edit approves or retires
 *
 * The ROUTE lets in a caller who has either preview right (like `anyaccess`), and the controller then asks for the right of the TABLE the draft is in, so a person who
 * may see CRM emails cannot read the checks (which quote the draft's text) of a GTM message, and the other way round. There is no administrator bypass on the claims.
 */
final class OutreachGateAccess
{
    public const APPROVALS_LINK = '/module/crm/marketing/outreach-approvals';

    public const GTM_LINK = '/gtm/outreach';

    public const PRODUCTS_LINK = '/module/crm/sales/products';

    /** The route middleware of the preview: either screen's view right (the same `anyaccess` gate other two-screen writes use). */
    public static function previewMiddleware(): string
    {
        return 'anyaccess:' . self::APPROVALS_LINK . '@view,' . (config('gtm.permissions.use_menu_rights') ? self::GTM_LINK . '@view' : 'admin+executive');
    }

    public static function claimsMiddleware(string $action): string
    {
        return 'platformright:' . self::PRODUCTS_LINK . ',' . $action;
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public static function canPreview(array $identity, string $table): bool
    {
        $user = $identity['user'];
        $tenant = $identity['sub_institute_id'];

        if ($table === Draft::GTM && ! config('gtm.permissions.use_menu_rights')) {
            return RoleKey::satisfies(RoleKey::forUser($user), ['admin', 'executive']);
        }

        $link = $table === Draft::GTM ? self::GTM_LINK : self::APPROVALS_LINK;
        $menuId = MenuRight::idForAccessLink($link);

        return $menuId !== null && MenuRight::can((int) ($user->user_profile_id ?? 0), $menuId, $tenant, 'view');
    }
}
