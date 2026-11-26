<?php

namespace App\Domain\Crm\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Role of a person at a company (S18): the picklist `contacts` / `contact_role`. The six stock values are seeded by `php artisan crm:seed-contact-roles`; an admin may add or switch off
 * values in the picklist screen. A contact's role must be one of the stock values or an active picklist value (so the API works on a host where the seed has not been run yet).
 */
final class ContactRoles
{
    public const MODULE = 'contacts';

    public const FIELD = 'contact_role';

    /** @var list<string> */
    public const DEFAULTS = ['Decision maker', 'Influencer', 'Billing', 'Technical', 'User', 'Other'];

    /**
     * Every value a contact's role may take: the stock values plus the active picklist rows. ONE query (none when the picklist table is missing).
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $values = self::DEFAULTS;
        if (Schema::hasTable('crm_picklist_values')) {
            $values = array_merge($values, DB::table('crm_picklist_values')->where('module', self::MODULE)->where('field_key', self::FIELD)->where('status', true)->pluck('value')->all());
        }

        return array_values(array_unique(array_map('strval', $values)));
    }
}
