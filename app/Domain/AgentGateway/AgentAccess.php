<?php

namespace App\Domain\AgentGateway;

use App\Support\MenuRight;
use App\Support\RoleKey;

/**
 * Who may do what with the Organisation agent (slice 7, thin). The routes enforce these with the `platformright:` middleware; this class tells the
 * screen which buttons to show (the server still enforces every one of them).
 *
 *   run, accept, dismiss      edit on CRM Organizations (the Marketing or the Support row: the CRM's own organisation routes accept either)
 *   read an organisation's    view on CRM Organizations
 *   agent state
 *   the runs list and detail  view on Agent Run History
 *   the waiting queue (list)  view on Agent Run History (a decision on a queued proposal is accept / dismiss: edit on Organizations, as above)
 *   the autonomy settings     view on Agent Run History to read; to CHANGE a level or the kill switch: edit on Agent Run History AND an administrator
 *                             (canChangeAutonomy: the controller checks both, because a role such as HR can hold the edit right on that row)
 *
 * There is no administrator bypass: a profile needs a rights row, the same rule MenuRight applies everywhere.
 */
final class AgentAccess
{
    public const ORGANIZATIONS = ['/module/crm/marketing/organizations', '/module/crm/support/organizations'];

    public const RUN_HISTORY = '/module/crm/marketing/agent-runs';

    /** The `platformright:` argument for the organisation rights, e.g. `platformright:{ORG_RIGHT},edit`. */
    public const ORG_RIGHT = '/module/crm/marketing/organizations|/module/crm/support/organizations';

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public static function canOnOrganizations(array $identity, string $action): bool
    {
        foreach (self::ORGANIZATIONS as $link) {
            if (self::can($identity, $link, $action)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public static function canViewRunHistory(array $identity): bool
    {
        return self::can($identity, self::RUN_HISTORY, 'view');
    }

    /**
     * An administrator of the organisation, as the platform decides it: the profile's role resolved through RoleKey (the stable `role_key`, else the
     * exact legacy profile names), the way ProductAccess and the route middleware do it. NEVER a raw read of `role_key`: it is NULL on most profiles of
     * the app host, so a raw read would refuse the real administrator, and never the profile's display name, which an organisation can edit.
     *
     * @param array{user: object, user_id: int, sub_institute_id: int} $identity
     */
    public static function isAdministrator(array $identity): bool
    {
        return RoleKey::forProfileId((int) ($identity['user']->user_profile_id ?? 0)) === 'administrator';
    }

    /**
     * Whether the caller may change an autonomy level or the kill switch: the EDIT right on Agent Run History (the route checks it too) AND being an
     * administrator. The first alone would let any role that holds the edit right set how much the agent may do alone (HR holds it on tenant 6).
     *
     * @param array{user: object, user_id: int, sub_institute_id: int} $identity
     */
    public static function canChangeAutonomy(array $identity): bool
    {
        return self::can($identity, self::RUN_HISTORY, 'edit') && self::isAdministrator($identity);
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private static function can(array $identity, string $link, string $action): bool
    {
        $menuId = MenuRight::idForAccessLink($link);

        return $menuId !== null && MenuRight::can((int) ($identity['user']->user_profile_id ?? 0), $menuId, $identity['sub_institute_id'], $action);
    }
}
