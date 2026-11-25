<?php

namespace App\Domain\Intelligence\Access;

/**
 * Whether a profile holds a menu right. An interface so the production implementation (the application's own menu-rights table, via App\Support\MenuRight)
 * can be replaced by an in-memory one in tests, and so no detector reaches into the rights table itself.
 */
interface RightsChecker
{
    /** Whether the profile holds `$action` (view|add|edit|delete) on the menu row whose access_link is `$accessLink`. An unknown link is never allowed. */
    public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool;
}
