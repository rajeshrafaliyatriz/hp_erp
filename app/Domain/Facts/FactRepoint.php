<?php

namespace App\Domain\Facts;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the facts of a record that is merged away pointing at the record that survives (slice 4). Merging two contacts calls it from CrmContactController::merge.
 * (Merging two organisations moves the same rows through OrganisationReferences, the one reviewed list the organisation merge walks; an organisation merge that
 * also wants the rules below calls this class from OrganisationMerger.)
 *
 * The unique key of the ledger is (organisation, kind, record, field, value hash), so a fact the survivor already holds in the same words cannot simply be moved:
 *   - a fact the survivor does NOT hold moves, status and evidence intact;
 *   - a fact the survivor holds too is dropped with its evidence, and the survivor's row stands, EXCEPT that a dismissal outranks a waiting suggestion: if a
 *     person dismissed the value on the removed record and it is still waiting on the survivor, the survivor's row becomes dismissed, so a value a person
 *     refused is never offered again just because two records were merged;
 *   - a pointer to a dropped row (`supersedes_id`) is moved to the row that replaced it;
 *   - a field with one current value that now has two accepted facts keeps the newer and supersedes the older, so the chain stays a chain.
 * Everything is scoped to the organisation. Idempotent: a second call finds nothing to move.
 */
final class FactRepoint
{
    /** @return array{moved: int, dropped: int, dismissals_kept: int, superseded: int} */
    public function move(int $tenantId, string $entityType, int $fromId, int $toId): array
    {
        $result = ['moved' => 0, 'dropped' => 0, 'dismissals_kept' => 0, 'superseded' => 0];
        if (! FactEntities::isType($entityType) || $fromId === $toId || $tenantId <= 0) {
            return $result;
        }

        DB::transaction(function () use ($tenantId, $entityType, $fromId, $toId, &$result) {
            $facts = DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('entity_type', $entityType);
            foreach ((clone $facts)->where('entity_id', $fromId)->orderBy('id')->get() as $fact) {
                $same = (clone $facts)->where('entity_id', $toId)->where('field', $fact->field)->where('value_hash', $fact->value_hash)->first();
                if ($same === null) {
                    DB::table('g2g_entity_facts')->where('id', $fact->id)->update(['entity_id' => $toId, 'updated_at' => now()]);
                    $result['moved']++;

                    continue;
                }
                if ($fact->status === 'dismissed' && $same->status === 'proposed') {
                    DB::table('g2g_entity_facts')->where('id', $same->id)->update(['status' => 'dismissed', 'decided_by' => $fact->decided_by, 'decided_at' => $fact->decided_at,
                        'decision_note' => $fact->decision_note, 'updated_at' => now()]);
                    $result['dismissals_kept']++;
                }
                DB::table('g2g_entity_facts')->where('sub_institute_id', $tenantId)->where('supersedes_id', $fact->id)->update(['supersedes_id' => $same->id]);
                DB::table('g2g_entity_fact_evidence')->where('sub_institute_id', $tenantId)->where('fact_id', $fact->id)->delete();
                DB::table('g2g_entity_facts')->where('id', $fact->id)->delete();
                $result['dropped']++;
            }

            foreach (array_keys(array_filter((array) config('facts.fields'), fn ($f) => ! empty($f['one']))) as $field) {
                $applied = (clone $facts)->where('entity_id', $toId)->where('field', $field)->where('status', 'applied')->orderByDesc('decided_at')->orderByDesc('id')->get();
                for ($i = 1; $i < $applied->count(); $i++) {
                    DB::table('g2g_entity_facts')->where('id', $applied[$i]->id)->update(['status' => 'superseded', 'updated_at' => now()]);
                    $result['superseded']++;
                }
                if ($applied->count() > 1 && $applied[0]->supersedes_id === null) {
                    DB::table('g2g_entity_facts')->where('id', $applied[0]->id)->update(['supersedes_id' => $applied[1]->id]);
                }
            }
        });

        return $result;
    }
}
