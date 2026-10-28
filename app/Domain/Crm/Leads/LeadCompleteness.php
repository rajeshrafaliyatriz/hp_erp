<?php

namespace App\Domain\Crm\Leads;

/**
 * What a lead still needs before it can be contacted properly. ONE definition, used by the workflow facts
 * (lead.missing_fields / lead.has_contact_name ...) and by the "details completed" announcement, so the two can never disagree.
 *
 * "A real contact name" means a first name and a last name that is not just the company name (a lead promoted from a Signal
 * starts with the company name as its last name and no first name).
 */
final class LeadCompleteness
{
    /** @param object|array<string, mixed> $lead @return list<string> what is missing, in a fixed order */
    public static function missing(object|array $lead): array
    {
        $l = (object) $lead;
        $company = strtolower(trim((string) ($l->company ?? '')));
        $last = strtolower(trim((string) ($l->last_name ?? '')));
        $out = [];
        if (! (trim((string) ($l->first_name ?? '')) !== '' && $last !== '' && $last !== $company)) {
            $out[] = 'a real contact name';
        }
        if (trim((string) ($l->email ?? '')) === '') {
            $out[] = 'an email address';
        }
        if (trim((string) ($l->phone ?? '')) === '' && trim((string) ($l->mobile ?? '')) === '') {
            $out[] = 'a phone number';
        }
        if ($company === '') {
            $out[] = 'a company';
        }

        return $out;
    }

    /** @param object|array<string, mixed> $lead */
    public static function hasRealName(object|array $lead): bool
    {
        return ! in_array('a real contact name', self::missing($lead), true);
    }
}
