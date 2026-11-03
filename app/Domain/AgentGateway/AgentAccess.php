<?php

namespace App\Domain\AgentGateway;

use App\Support\MenuRight;

/**
 * Who may do what with the Organisation agent (slice 7, thin). The routes enforce these with the `platformright:` middleware; this class tells the
 * screen which buttons to show (the server still enforces every one of them).
 *
 *   run, accept, dismiss      edit on CRM Organizations (the Marketing or the Support row: the CRM's own organisation routes accept either)
 *   read an organisation's    view on CRM Organizations
 *   agent state
 *   the runs list and detail  view on Agent Run History
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

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    private static function can(array $identity, string $link, string $action): bool
    {
        $menuId = MenuRight::idForAccessLink($link);

        return $menuId !== null && MenuRight::can((int) ($identity['user']->user_profile_id ?? 0), $menuId, $identity['sub_institute_id'], $action);
    }
}
