<?php

namespace App\Domain\Crm\Leads;

use Illuminate\Support\Facades\DB;

/**
 * "Transfer related record to" of the Convert Lead pop-up (S19): MOVES what hangs on a lead to the organization, the person or the opportunity the conversion made or attached to.
 * Called inside the conversion's own transaction, so the conversion and the move stand or fall together.
 *
 *   comments    crm_lead_comments rows become rows of crm_organization_comments / crm_contact_comments / crm_opportunity_comments (author, text and timestamps kept); the lead's copy is deleted
 *   e-mails     crm_email_links          linked_type LEAD -> ORGANIZATION | CONTACT | OPPORTUNITY, linked_id -> the target
 *   documents   document_library_links   the same
 *   events      task_management_calendar_events (live ones)   the same
 *
 * The lead's follow-ups (crm_lead_followups) stay on the lead. The cost is a fixed handful of statements (one INSERT..SELECT and one DELETE for the comments, one UPDATE per link table), never one per
 * record: the development database is remote. A link the target already has (an existing organization or person the e-mail or document is already linked to) is dropped from the lead rather than
 * moved, because (item, linked type, linked id) is unique; it counts as moved, since the target carries it.
 */
final class LeadRelatedTransfer
{
    /** @var array<string, array{type: string, comments: string, key: string}> */
    private const TARGETS = [
        'organization' => ['type' => 'ORGANIZATION', 'comments' => 'crm_organization_comments', 'key' => 'organization_id'],
        'contact' => ['type' => 'CONTACT', 'comments' => 'crm_contact_comments', 'key' => 'contact_id'],
        'opportunity' => ['type' => 'OPPORTUNITY', 'comments' => 'crm_opportunity_comments', 'key' => 'opportunity_id'],
    ];

    /**
     * @param  string  $to  organization | contact | opportunity
     * @param  bool  $fresh  the target was created by this conversion, so it has no links of its own yet (the duplicate check is skipped)
     * @return array{comments: int, emails: int, documents: int, events: int}
     */
    public static function move(int $tenant, int $leadId, string $to, int $targetId, bool $fresh): array
    {
        $target = self::TARGETS[$to];

        $comments = DB::table($target['comments'])->insertUsing(
            [$target['key'], 'sub_institute_id', 'comment', 'created_by', 'created_at', 'updated_at'],
            DB::table('crm_lead_comments')->where('lead_id', $leadId)->where('sub_institute_id', $tenant)->orderBy('id')
                ->selectRaw('? as target_id, sub_institute_id, comment, created_by, created_at, updated_at', [$targetId]),
        );
        if ($comments > 0) {
            DB::table('crm_lead_comments')->where('lead_id', $leadId)->where('sub_institute_id', $tenant)->delete();
        }

        return [
            'comments' => (int) $comments,
            'emails' => self::relink('crm_email_links', 'email_id', $tenant, $leadId, $target['type'], $targetId, $fresh),
            'documents' => self::relink('document_library_links', 'document_id', $tenant, $leadId, $target['type'], $targetId, $fresh),
            'events' => (int) DB::table('task_management_calendar_events')->where('sub_institute_id', $tenant)->where('linked_type', 'LEAD')->where('linked_id', $leadId)->whereNull('deleted_at')
                ->update(['linked_type' => $target['type'], 'linked_id' => $targetId, 'updated_at' => now()]),
        ];
    }

    /** Re-points the lead's rows of a link table to the target; returns how many of the lead's rows the target now carries. */
    private static function relink(string $table, string $itemKey, int $tenant, int $leadId, string $type, int $targetId, bool $fresh): int
    {
        $lead = fn () => DB::table($table)->where('sub_institute_id', $tenant)->where('linked_type', 'LEAD')->where('linked_id', $leadId);

        $dropped = 0;
        if (! $fresh) {
            $already = DB::table($table)->where('sub_institute_id', $tenant)->where('linked_type', $type)->where('linked_id', $targetId)->pluck($itemKey)->all();
            if ($already !== []) {
                $dropped = (int) $lead()->whereIn($itemKey, $already)->delete();
            }
        }

        return $dropped + (int) $lead()->update(['linked_type' => $type, 'linked_id' => $targetId]);
    }
}
