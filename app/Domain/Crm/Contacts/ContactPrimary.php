<?php

namespace App\Domain\Crm\Contacts;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The "Primary" star of a company's people (S18, contract section 2): at most ONE live contact of a company carries crm_contacts.is_primary = 1. MariaDB 10.1 has no partial unique index,
 * so the rule is kept here, in one place every writer goes through (the primary endpoints, create/update with isPrimary, delete, move, merge, the organisation merge, the import).
 *
 * The invariant: a primary has a company; a company has at most one primary among its live contacts. A soft-deleted contact never keeps the flag (it is cleared when the contact is deleted,
 * moved to another company or merged away), so restoring one from the Recycle Bin can never bring a second primary back.
 */
final class ContactPrimary
{
    public const NEEDS_COMPANY = 'A person needs a company before it can be the primary contact.';

    /**
     * Makes the contact the primary of its company and clears the flag on every other contact of that company, in ONE transaction. The caller has checked that the contact is a live row of this
     * tenant and has a company.
     *
     * @return list<int> the ids of the contacts that LOST the flag
     */
    public static function make(int $tenantId, int $contactId, int $organizationId): array
    {
        return DB::transaction(function () use ($tenantId, $contactId, $organizationId): array {
            $others = DB::table('crm_contacts')
                ->where('sub_institute_id', $tenantId)->where('organization_id', $organizationId)
                ->where('id', '!=', $contactId)->where('is_primary', 1)
                ->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($others !== []) {
                DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->whereIn('id', $others)->update(['is_primary' => 0, 'updated_at' => now()]);
            }
            DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('id', $contactId)->update(['is_primary' => 1, 'updated_at' => now()]);

            return $others;
        });
    }

    /** Clears the flag of one contact. Returns whether it was set. */
    public static function clear(int $tenantId, int $contactId): bool
    {
        return DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('id', $contactId)->where('is_primary', 1)
            ->update(['is_primary' => 0, 'updated_at' => now()]) > 0;
    }

    /**
     * After two companies were merged: the survivor company may now hold two primaries (one from each). Keeps $keepId when it is still one, else the oldest, and clears the rest.
     *
     * @return list<int> the ids that lost the flag
     */
    public static function enforceSingle(int $tenantId, int $organizationId, ?int $keepId = null): array
    {
        $primaries = DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('organization_id', $organizationId)->whereNull('deleted_at')
            ->where('is_primary', 1)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($primaries) < 2) {
            return [];
        }
        $keep = $keepId !== null && in_array($keepId, $primaries, true) ? $keepId : $primaries[0];
        $lose = array_values(array_diff($primaries, [$keep]));
        DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->whereIn('id', $lose)->update(['is_primary' => 0, 'updated_at' => now()]);

        return $lose;
    }

    /**
     * A contact merge, run for one removed contact AFTER the generic field supplement: the survivor keeps its own flag; it takes the removed contact's flag only when it has none, has a company,
     * and that company has no primary of its own. The removed contact never keeps the flag (it is soft-deleted right after).
     */
    public static function afterMerge(int $tenantId, int $fromId, int $toId): void
    {
        try {
            $from = DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('id', $fromId)->first(['id', 'is_primary']);
        } catch (QueryException) {
            return;   // the S18 migration has not run on this host: there is no star to carry over
        }
        if ($from === null || (int) ($from->is_primary ?? 0) !== 1) {
            return;
        }
        self::clear($tenantId, $fromId);

        $to = DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('id', $toId)->first(['id', 'organization_id', 'is_primary']);
        if ($to === null || $to->organization_id === null || (int) $to->is_primary === 1) {
            return;
        }
        $companyHasPrimary = DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('organization_id', $to->organization_id)
            ->whereNull('deleted_at')->where('id', '!=', $fromId)->where('is_primary', 1)->exists();
        if (! $companyHasPrimary) {
            self::make($tenantId, $toId, (int) $to->organization_id);
        }
    }
}
