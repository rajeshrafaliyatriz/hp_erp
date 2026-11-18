<?php

namespace App\Domain\AgentGateway;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the agent's history attached to the organisation it is about when two Organisation 360 identities become one (slice 7, thin).
 *
 * A run and a proposal are ABOUT an identity (`g2g_agent_runs.subject_id` / `g2g_agent_proposals.subject_id` = `g2g_org_accounts.id`). When two identities
 * are merged, the older survives and the other is soft-deleted (OrgLinker::merge, OrganisationMerger::identity). Left alone, the removed identity's runs
 * would sit on a deleted row, and above all its PENDING proposals would be invisible and impossible to accept (the panel lists by the survivor's id and
 * the applier finds no subject). So they follow it:
 *
 *   - every run moves to the survivor (a run has no unique key);
 *   - a proposal moves too, unless the survivor already holds the same proposal (same action and payload hash: the unique key would refuse the move).
 *     Such a duplicate is left where it is as history, and when it was still waiting it is closed as `dismissed` with a note saying why, so it is not
 *     counted as work for a person on an organisation that no longer exists.
 *
 * It runs inside the caller's transaction (the merge). It must NEVER make a merge fail: the merge is the important operation and this is its
 * bookkeeping, so a problem here is logged and the merge goes on. A host without the agent tables (the live database before the migrations run) is a no-op.
 * Scoped to the tenant throughout; calling it again changes nothing.
 */
final class AgentSubjects
{
    public const TYPE = 'org_account';

    public const MERGED_NOTE = 'The organisation was merged into another one that already has this proposal.';

    /** @return array{runs: int, proposals: int, superseded: int} rows moved to the survivor; proposals moved; waiting duplicates closed instead of moved */
    public static function repoint(int $tenantId, int $fromId, int $toId): array
    {
        $none = ['runs' => 0, 'proposals' => 0, 'superseded' => 0];
        if ($fromId === $toId) {
            return $none;
        }

        try {
            if (! Schema::hasTable('g2g_agent_runs') || ! Schema::hasTable('g2g_agent_proposals')) {
                return $none;
            }

            return DB::transaction(function () use ($tenantId, $fromId, $toId): array {
                $now = now();
                $runs = DB::table('g2g_agent_runs')->where('sub_institute_id', $tenantId)->where('subject_type', self::TYPE)->where('subject_id', $fromId)
                    ->update(['subject_id' => $toId, 'updated_at' => $now]);

                $base = fn () => DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->where('subject_type', self::TYPE);
                $held = [];
                foreach ($base()->where('subject_id', $toId)->get(['action', 'payload_hash']) as $row) {
                    $held[$row->action . '|' . $row->payload_hash] = true;
                }

                $move = [];
                $close = [];
                foreach ($base()->where('subject_id', $fromId)->get(['id', 'action', 'payload_hash', 'status']) as $row) {
                    if (! isset($held[$row->action . '|' . $row->payload_hash])) {
                        $move[] = (int) $row->id;
                    } elseif ($row->status === 'pending') {
                        $close[] = (int) $row->id;
                    }
                }

                $moved = $move === [] ? 0 : $base()->whereIn('id', $move)->update(['subject_id' => $toId, 'updated_at' => $now]);
                $superseded = $close === [] ? 0 : $base()->whereIn('id', $close)->where('status', 'pending')->update([
                    'status' => 'dismissed', 'decision_note' => self::MERGED_NOTE, 'decided_at' => $now, 'updated_at' => $now,
                ]);

                return ['runs' => (int) $runs, 'proposals' => (int) $moved, 'superseded' => (int) $superseded];
            });
        } catch (\Throwable $e) {
            Log::warning('agent.subjects_not_repointed', ['tenant' => $tenantId, 'from' => $fromId, 'to' => $toId, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);

            return $none;
        }
    }
}
