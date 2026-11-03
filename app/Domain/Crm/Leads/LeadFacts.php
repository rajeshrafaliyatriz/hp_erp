<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Facts\FactEntities;
use App\Domain\Facts\FactRepoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What the facts ledger knows about a lead follows it when it becomes a contact (slice 6, acceptance 4; the ledger is slice 4's).
 *
 * The evidence a researcher gathered about a person (their phone, their LinkedIn address, their job title, with its score and its sources) belongs to the
 * contact the lead turned into: left on a lead that is now an archive, it would be invisible from the record people actually work in. Consent follows by
 * its own route (ConsentRecorder::carryOver, called by the converter beside this).
 *
 * FactRepoint knows how facts of ONE kind of record move between two records of that kind (twins, a dismissal that outranks a waiting suggestion, the chain
 * of values a field supersedes, the evidence), but it has no way to change the kind of record. So the move is done in two steps, in ONE transaction:
 *   1. the lead's facts that APPLY to a contact are re-labelled as facts of a contact whose id cannot belong to any real contact (SENTINEL + the lead's id,
 *      far beyond any auto-increment id), which can collide with nothing;
 *   2. FactRepoint::move() moves them from that stand-in to the real contact, with every rule it has.
 * A method on FactRepoint that crosses kinds would remove step 1; slice 4's files are read-only for this slice, so it is a request in the report.
 *
 * A field that does not apply to a contact (a website belongs to an organisation or a lead, not a person) stays on the lead, which is kept as the
 * archive of what was known when it was a lead.
 */
final class LeadFacts
{
    /** Added to a lead's id to make the stand-in contact id of step 1. */
    private const SENTINEL = 4_000_000_000_000;

    /** @return array{moved: int, dropped: int, dismissals_kept: int, superseded: int} FactRepoint's counts for what went to the contact */
    public static function followToContact(int $tenantId, int $leadId, int $contactId): array
    {
        $none = ['moved' => 0, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0];
        if ($tenantId <= 0 || $leadId <= 0 || $contactId <= 0 || ! Schema::hasTable('g2g_entity_facts')) {
            return $none;
        }

        $fields = array_values(array_filter(array_keys((array) config('facts.fields')), fn ($field) => FactEntities::applies('contact', (string) $field)));
        if ($fields === []) {
            return $none;
        }

        return DB::transaction(function () use ($tenantId, $leadId, $contactId, $fields, $none) {
            $standIn = self::SENTINEL + $leadId;
            $retagged = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('entity_type', 'lead')->where('entity_id', $leadId)
                ->whereIn('field', $fields)->update(['entity_type' => 'contact', 'entity_id' => $standIn, 'updated_at' => now()]);
            if ($retagged === 0) {
                return $none;
            }

            return (new FactRepoint)->move($tenantId, 'contact', $standIn, $contactId);
        });
    }

    /** The same, and never throws: a conversion must not fail because the ledger could not be moved (the failure is logged). */
    public static function followToContactSafely(int $tenantId, int $leadId, int $contactId): int
    {
        try {
            $result = self::followToContact($tenantId, $leadId, $contactId);

            return $result['moved'];
        } catch (\Throwable $e) {
            Log::warning('The facts of a converted lead could not be moved to its contact', ['lead' => $leadId, 'contact' => $contactId, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
