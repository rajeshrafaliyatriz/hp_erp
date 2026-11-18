<?php

namespace App\Domain\Crm\Records;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;

/**
 * Adds a note to a CRM record: the one write path for "a comment on a lead, contact, organisation or opportunity" that does not come from a person
 * typing in the screen (slice 7 of the agentic CRM, Docs/agentic-crm/slices/S07-agent-gateway-thin.md).
 *
 * The four comment tables already exist and the HTTP trait that fills them (HasCrmComments) stays exactly as it is; this class does the same insert for
 * a caller that is not a request, with the checks a request would have done for it:
 *
 *   - the record must exist, be live (not soft-deleted) and belong to the caller's organisation: otherwise RecordNotFound, the same answer for "never
 *     existed" and "somebody else's";
 *   - `created_by` is the person the write is made for, never null for an agent (a system note carries no author: LeadActivity::note does that);
 *   - `created_via` says who wrote it ('agent', 'automation'; NULL = a person), written only where the table has the column, so the code can ship
 *     before every host has the migration.
 *
 * It does NOT check the actor's rights: the caller (the agent gateway, an HTTP controller behind its middleware) has already decided that.
 */
final class RecordNote
{
    /** @var array<string, array{0: string, 1: string, 2: string}> entity type => [comment table, its foreign key, the record's own table] */
    private const ENTITIES = [
        'lead' => ['crm_lead_comments', 'lead_id', 'crm_leads'],
        'contact' => ['crm_contact_comments', 'contact_id', 'crm_contacts'],
        'organization' => ['crm_organization_comments', 'organization_id', 'crm_organizations'],
        'opportunity' => ['crm_opportunity_comments', 'opportunity_id', 'crm_opportunities'],
    ];

    public const MAX_LENGTH = 1980;

    /** @return list<string> */
    public static function entityTypes(): array
    {
        return array_keys(self::ENTITIES);
    }

    /**
     * @param  string  $entityType  lead | contact | organization | opportunity
     * @param  string|null  $via  'agent' | 'automation' | null (a person)
     * @return int the comment id
     *
     * @throws RecordNotFound
     */
    public function record(int $tenantId, string $entityType, int $entityId, ?int $actorId, string $text, ?string $via = null): int
    {
        [$table, $foreignKey, $recordTable] = self::ENTITIES[$entityType] ?? throw new \InvalidArgumentException("'{$entityType}' has no comments.");

        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A note cannot be empty.');
        }

        $exists = DB::table($recordTable)->where('id', $entityId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            throw new RecordNotFound("The {$entityType} was not found.");
        }

        $row = [
            $foreignKey => $entityId, 'sub_institute_id' => $tenantId, 'comment' => mb_substr($text, 0, self::MAX_LENGTH),
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ];
        if ($via !== null && in_array('created_via', array_map('strtolower', ColumnExists::all($table)), true)) {
            $row['created_via'] = $via;
        }

        return (int) DB::table($table)->insertGetId($row);
    }
}
