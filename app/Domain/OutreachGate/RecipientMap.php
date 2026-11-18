<?php

namespace App\Domain\OutreachGate;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a draft's recipient to the records consent is read on. Read only, every lookup carries the organisation, a removed record is not a record, and nothing that
 * cannot be mapped is guessed: it comes back `unmapped` with a plain reason.
 *
 *   GTM message  the outreach contact (gtm_contacts) and its address; the CRM records (contact, lead, organisation) of the same organisation that carry the SAME
 *                address are the ones consent is read on. With none of them, config('outreach_gate.unmapped_gtm_recipient') decides: 'fail' (default) or 'gtm_only'.
 *   CRM email    the record the email is linked to: a lead, a contact or an organisation directly; an opportunity through its organisation; a ticket through its
 *                contact, then its organisation (the same resolution CrmOutreachMailer::recipientBlocked() and the unsubscribe page use, but scoped to the organisation).
 *
 * One instance per evaluation: it remembers its answers so the four recipient checks cost one lookup.
 */
final class RecipientMap
{
    private const CRM_TABLES = ['contact' => 'crm_contacts', 'lead' => 'crm_leads', 'organization' => 'crm_organizations'];

    /** @var array<string, Recipient> */
    private array $memo = [];

    public function for(Draft $draft): Recipient
    {
        $key = "{$draft->table}:{$draft->id}:{$draft->tenant}";

        return $this->memo[$key] ??= $draft->table === Draft::GTM ? $this->gtm($draft) : $this->crm($draft);
    }

    private function gtm(Draft $draft): Recipient
    {
        $contact = $draft->gtmContactId === null || $draft->tenant <= 0 || ! Schema::hasTable('gtm_contacts') ? null
            : DB::table('gtm_contacts')->where('id', $draft->gtmContactId)->where('sub_institute_id', $draft->tenant)->whereNull('deleted_at')->first();
        if ($contact === null) {
            return Recipient::unmapped('The outreach contact does not exist for this organisation or was removed.');
        }
        $email = strtolower(trim((string) $contact->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Recipient::unmapped('The outreach contact has no valid email address.');
        }

        $entities = $this->crmRecordsWithAddress($draft->tenant, $email);
        if ($entities !== []) {
            return new Recipient(true, $email, $entities, 'Outreach contact #' . $contact->id . ' matches ' . count($entities) . ' CRM record' . (count($entities) === 1 ? '' : 's') . ' by address.');
        }
        if (config('outreach_gate.unmapped_gtm_recipient') === 'gtm_only') {
            return new Recipient(true, $email, [], 'Outreach contact #' . $contact->id . ' has no CRM record; consent rests on its outreach status and the suppression list.', true);
        }

        return Recipient::unmapped('No CRM contact, lead or organisation of this organisation has this address, so its consent cannot be read. Add the person to the CRM first.', $email);
    }

    private function crm(Draft $draft): Recipient
    {
        $email = strtolower(trim($draft->address));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Recipient::unmapped('The email has no valid recipient address.');
        }
        $entity = $this->linkedRecord($draft->tenant, strtoupper((string) $draft->linkedType), (int) $draft->linkedId);
        if ($entity === null) {
            return Recipient::unmapped($this->unknownLink((string) $draft->linkedType), $email);
        }

        return new Recipient(true, $email, [$entity], 'The email is linked to ' . $entity[0] . ' #' . $entity[1] . '.');
    }

    private function unknownLink(string $type): string
    {
        return in_array(strtoupper($type), ['LEAD', 'CONTACT', 'ORGANIZATION', 'OPPORTUNITY', 'TICKET'], true)
            ? 'The record this email is linked to has no organisation or contact of this organisation to read consent on.'
            : "The email is linked to a kind of record the gate does not know ({$type}).";
    }

    /** @return array{0: string, 1: int}|null the CRM record consent is read on, or null when it cannot be found */
    private function linkedRecord(int $tenant, string $type, int $id): ?array
    {
        if ($tenant <= 0 || $id <= 0) {
            return null;
        }

        return match ($type) {
            'LEAD' => ['lead', $id],
            'CONTACT' => ['contact', $id],
            'ORGANIZATION' => ['organization', $id],
            'OPPORTUNITY' => $this->parent($tenant, 'crm_opportunities', $id, ['organization_id' => 'organization']),
            'TICKET' => $this->parent($tenant, 'crm_tickets', $id, ['contact_id' => 'contact', 'organization_id' => 'organization']),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $columns  own column => the kind of record it points at, in priority order
     * @return array{0: string, 1: int}|null
     */
    private function parent(int $tenant, string $table, int $id, array $columns): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->first();
        if ($row === null) {
            return null;
        }
        foreach ($columns as $column => $type) {
            if (! empty($row->{$column})) {
                return [$type, (int) $row->{$column}];
            }
        }

        return null;
    }

    /** @return list<array{0: string, 1: int}> */
    private function crmRecordsWithAddress(int $tenant, string $email): array
    {
        $found = [];
        foreach (self::CRM_TABLES as $type => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->limit(20)->pluck('id') as $id) {
                $found[] = [$type, (int) $id];
            }
        }

        return $found;
    }
}
