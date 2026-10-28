<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use App\Services\Events\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Generic "who changed what, when" history for any CRM entity, built on the
 * EXISTING g2g_event/EventRecorder/g2g_audit_log pipeline - no 17th bespoke
 * history table. `AuditLogProjector::handles()` already returns true
 * unconditionally, so the event types this trait invents (one per host, e.g.
 * `crm.campaign.updated`/`crm.campaign.deleted`) need zero registration to
 * land in `g2g_audit_log`, which already has `entity_type`/`entity_id`
 * indexed.
 *
 * Reusable across Campaign/Quote/Product/Vendor/SalesOrder/PurchaseOrder/
 * Invoice/Faq/ServiceContract/Asset/PriceBook (and, via a splice-spec, the 5
 * hot controllers this phase shares with sibling agents) without 11+
 * near-duplicate traits - the host supplies only `auditEntityType()`, plus
 * `resolveApiIdentity()` (from `ResolvesApiIdentity`, already `use`d by
 * every CRM controller) and an `ownRow(int $tenantId, int $id): ?object`
 * lookup. Every host this trait is wired into already has `ownRow()` under
 * that exact name EXCEPT `CrmLeadController`, which fetches rows inline
 * instead and needs that one extra private method added alongside this
 * trait's own `use` line - see its splice-spec.
 *
 * `recordCrmAudit()` must be called AFTER the host's own write commits,
 * never wrapped in a transaction here - same convention
 * `CompetencyDefinitionController`'s own `EventRecorder` call site
 * documents ("Graph projection needs to hear about this - after the
 * transaction commits, not inside it"): the event is a claim about what the
 * rows now look like, made once that is true, not a participant in making
 * it true.
 */
trait HasCrmAuditTrail
{
    /**
     * Bookkeeping columns every update() call site stamps on literally every
     * write, whether or not anything a human cares about changed - excluded
     * from the diff so they don't drown real changes in noise. actor_id is
     * already on the event itself, so updated_by here is pure redundancy.
     *
     * `deleted_at`/`deleted_by` are deliberately NOT in this list: destroy()
     * diffs on exactly those two columns, and filtering them out would make
     * a deletion's own audit entry vanish along with them.
     *
     * @var array<int, string>
     */
    private const IGNORED_DIFF_COLUMNS = ['updated_at', 'updated_by'];

    /**
     * Generic key-by-key diff. For every key present in $new, if its value
     * differs from $old[$key] (absent in $old reads as null), the result
     * gets one {field, oldValue, newValue} entry. Deliberately NOT
     * `TaskAuditService::taskChanged()`'s hand-picked-field style - this has
     * to work for any entity's arbitrary column set sight unseen, so it
     * walks $new's own keys rather than naming any of them.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<int, array{field: string, oldValue: mixed, newValue: mixed}>
     */
    protected function diffRow(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $key => $value) {
            $oldValue = $old[$key] ?? null;

            if ($value !== $oldValue) {
                $changes[] = ['field' => $key, 'oldValue' => $oldValue, 'newValue' => $value];
            }
        }

        return $changes;
    }

    /**
     * Diffs $old/$new (bookkeeping columns stripped first, see
     * IGNORED_DIFF_COLUMNS) and, only if anything real changed, appends one
     * event via EventRecorder - which AuditLogProjector::project() turns
     * into a g2g_audit_log row with zero extra wiring, same as every other
     * event type in this codebase already gets.
     *
     * Call it with the row exactly as it stood before the write ($old -
     * typically the controller's own pre-fetched $existing, cast to array)
     * and the row as it now stands after the write ($new - for a plain
     * header update, `array_merge((array) $existing, $data)` is enough:
     * every untouched column round-trips unchanged via the merge, and every
     * column the request actually touched shows its new value from $data).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function recordCrmAudit(
        string $type,
        int $tenantId,
        string $entityType,
        int $entityId,
        int $actorId,
        array $old,
        array $new,
    ): void {
        $ignored = array_flip(self::IGNORED_DIFF_COLUMNS);
        $changes = $this->diffRow(array_diff_key($old, $ignored), array_diff_key($new, $ignored));

        if ($changes === []) {
            return;
        }

        app(EventRecorder::class)->record($type, $tenantId, $entityType, $entityId, $actorId, ['changes' => $changes]);
    }

    /**
     * GET .../{id}/history - every g2g_audit_log row for this one entity,
     * newest first, actor name denormalized via a plain tbluser leftJoin
     * (same convention every CRM controller's own baseQuery() already uses
     * for assigned_to_name/contact_name/etc.). The host supplies
     * `auditEntityType()` (what g2g_audit_log.entity_type to filter on) and
     * must have `ownRow()`/`resolveApiIdentity()` - the second always comes
     * from `ResolvesApiIdentity`, already `use`d by every CRM controller;
     * the first is already present on every host under that exact name
     * EXCEPT `CrmLeadController`, which needs it added (see its
     * splice-spec) or this action fatals on first hit.
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        $rows = DB::table('g2g_audit_log as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.actor_id')
            ->where('a.entity_type', $this->auditEntityType())
            ->where('a.entity_id', $id)
            ->where('a.sub_institute_id', $identity['sub_institute_id'])
            ->orderByDesc('a.occurred_at')
            ->orderByDesc('a.id')
            ->select(
                'a.id', 'a.type', 'a.detail', 'a.actor_id', 'a.occurred_at',
                DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as actor_name"),
            )
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'History.',
            'data' => $rows->map(fn ($row) => $this->crmAuditEntryResource($row))->all(),
        ]);
    }

    /**
     * The g2g_audit_log.entity_type this host's rows are filed under (e.g.
     * 'crm_campaigns' - the host's own table name is the convention every
     * implementation in this phase uses). Never read from the request.
     */
    abstract protected function auditEntityType(): string;

    /** @return array<string, mixed> */
    private function crmAuditEntryResource(object $row): array
    {
        $detail = json_decode((string) ($row->detail ?? '{}'), true) ?? [];
        $changes = is_array($detail['changes'] ?? null) ? $detail['changes'] : [];

        return [
            'id' => (string) $row->id,
            'type' => $row->type,
            'changes' => array_map(fn ($change) => [
                'field' => $change['field'] ?? null,
                'oldValue' => $change['oldValue'] ?? null,
                'newValue' => $change['newValue'] ?? null,
            ], $changes),
            'actorId' => $row->actor_id ? (string) $row->actor_id : null,
            'actorName' => ($row->actor_name ?? '') !== '' ? $row->actor_name : null,
            'occurredAt' => $row->occurred_at,
        ];
    }
}
