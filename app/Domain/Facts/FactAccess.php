<?php

namespace App\Domain\Facts;

use App\Support\MenuRight;

/**
 * Who may read and decide facts (slice 4). There is NO menu row of its own (the owner's rule of 3 Nov 2026: no duplicate screens): the facts of a record belong
 * to the screen of that record, so the right is that screen's. The routes enforce it with the `platformright:` middleware; the controller re-checks it for the
 * routes that cannot know a record's kind before loading it (the queue, accept, dismiss).
 *
 *   contact        `/module/crm/marketing/contacts` OR `/module/crm/support/contacts` (the CRM's own contact routes accept either row)
 *   organization   `/module/crm/marketing/organizations` OR `/module/crm/support/organizations`
 *   lead           `/module/crm/marketing/leads`
 *   view reads a record's facts, its consent and the queue; edit accepts or dismisses.
 *
 * There is no administrator bypass: a profile needs a rights row, the same rule MenuRight applies everywhere.
 */
final class FactAccess
{
    public const LINKS = [
        'contact' => ['/module/crm/marketing/contacts', '/module/crm/support/contacts'],
        'organization' => ['/module/crm/marketing/organizations', '/module/crm/support/organizations'],
        'lead' => ['/module/crm/marketing/leads'],
    ];

    /** The route middleware for one kind of record: `platformright:<row>|<row>,<action>`. */
    public static function middleware(string $type, string $action): string
    {
        return 'platformright:' . implode('|', self::LINKS[$type]) . ',' . $action;
    }

    /** The route middleware for a route that serves every kind: the caller needs the right on at least one of them (the controller then narrows it). */
    public static function middlewareAny(string $action): string
    {
        return 'platformright:' . implode('|', array_merge(...array_values(self::LINKS))) . ',' . $action;
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity */
    public static function can(array $identity, string $type, string $action): bool
    {
        foreach (self::LINKS[$type] ?? [] as $link) {
            $menuId = MenuRight::idForAccessLink($link);
            if ($menuId !== null && MenuRight::can((int) ($identity['user']->user_profile_id ?? 0), $menuId, $identity['sub_institute_id'], $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The kinds of record the caller holds this right on.
     *
     * @param  array{user: object, user_id: int, sub_institute_id: int}  $identity
     * @return list<string>
     */
    public static function types(array $identity, string $action): array
    {
        return array_values(array_filter(FactEntities::TYPES, fn ($type) => self::can($identity, $type, $action)));
    }
}
