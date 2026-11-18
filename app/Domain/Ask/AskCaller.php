<?php

namespace App\Domain\Ask;

use App\Support\MenuRight;

/**
 * Who is asking (slice 8, Ask): the organisation and the person, taken from the token by the controller and never from the request. Every lookup the
 * tools make carries `tenantId`, and every right they check is this person's profile's.
 */
final class AskCaller
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $profileId,
    ) {
    }

    /** @param array{user: object, user_id: int, sub_institute_id: int} $identity as ResolvesApiIdentity answers it */
    public static function fromIdentity(array $identity): self
    {
        return new self((int) $identity['sub_institute_id'], (int) $identity['user_id'], (int) ($identity['user']->user_profile_id ?? 0));
    }

    /**
     * Whether the caller's profile holds `$action` on ANY of the given menu rows (found by access_link, never by a numeric id: the ids differ between hosts).
     * No administrator bypass: a profile needs a rights row, the same rule MenuRight applies everywhere.
     *
     * @param  list<string>  $links
     */
    public function can(array $links, string $action = 'view'): bool
    {
        foreach ($links as $link) {
            $menuId = MenuRight::idForAccessLink($link);
            if ($menuId !== null && MenuRight::can($this->profileId, $menuId, $this->tenantId, $action)) {
                return true;
            }
        }

        return false;
    }
}
