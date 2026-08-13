<?php

namespace App\Services\Org;

use Illuminate\Support\Facades\DB;

/**
 * Cycle validation and depth bounding for the reporting line. Q-B1, A5.
 *
 * The database cannot express "this graph has no cycles" — MySQL has no recursive
 * CHECK constraint — so the guarantee lives here, and the migration says so rather
 * than letting anyone assume the schema enforces it.
 *
 * A cycle (A reports to B reports to A) makes team-scope resolution
 * non-terminating. It must be impossible to CREATE, not merely detectable
 * afterwards, because by the time it is detected an approval flow has already hung.
 */
class ReportingLineValidator
{
    /** A5: default 1 = direct reports only. Bounds traversal even if a cycle slips through. */
    public const DEFAULT_TEAM_SCOPE_DEPTH = 1;

    /** Hard ceiling on chain walks, so a corrupted graph cannot hang a request. */
    private const MAX_WALK = 64;

    /**
     * May $userId report to $managerId?
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function canAssign(int $userId, ?int $managerId): array
    {
        if ($managerId === null) {
            // Legitimate: the org head has no manager, and A5's no-manager ladder
            // covers everyone else. NULL is not an error.
            return ['ok' => true, 'reason' => null];
        }

        if ($userId === $managerId) {
            return ['ok' => false, 'reason' => 'A user cannot report to themselves.'];
        }

        // Walk UP from the proposed manager. If we reach $userId, the assignment
        // would close a loop.
        $seen = [];
        $cursor = $managerId;

        for ($i = 0; $i < self::MAX_WALK && $cursor !== null; $i++) {
            if ($cursor === $userId) {
                return ['ok' => false, 'reason' =>
                    "Assigning user {$userId} to manager {$managerId} would create a reporting cycle."];
            }
            if (isset($seen[$cursor])) {
                // A pre-existing cycle upstream. Refuse rather than extend it, and
                // say so plainly - this is data that should not exist.
                return ['ok' => false, 'reason' =>
                    "A reporting cycle already exists above user {$cursor}. Fix it before assigning."];
            }
            $seen[$cursor] = true;

            $cursor = DB::table('tbluser')->where('id', $cursor)->value('reporting_manager_id');
            $cursor = $cursor === null ? null : (int) $cursor;
        }

        if ($cursor !== null) {
            return ['ok' => false, 'reason' =>
                'Reporting chain exceeds ' . self::MAX_WALK . ' levels; refusing to walk further.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The team a manager can see, bounded by the tenant's team_scope_depth.
     *
     * Depth 1 (the default) is direct reports only. The bound is what makes this
     * terminate even against a graph that should not exist.
     *
     * @return int[] user ids, excluding the manager
     */
    public function teamOf(int $managerId, int $subInstituteId, ?int $depth = null): array
    {
        $depth ??= $this->teamScopeDepth($subInstituteId);
        $depth = max(1, min($depth, 10));

        $team = [];
        $frontier = [$managerId];
        $seen = [$managerId => true];

        for ($level = 0; $level < $depth && $frontier; $level++) {
            $next = DB::table('tbluser')
                ->whereIn('reporting_manager_id', $frontier)
                ->where('sub_institute_id', $subInstituteId)
                ->pluck('id')->all();

            $frontier = [];
            foreach ($next as $id) {
                $id = (int) $id;
                if (isset($seen[$id])) {
                    continue;   // cycle guard: never revisit
                }
                $seen[$id] = true;
                $team[] = $id;
                $frontier[] = $id;
            }
        }

        return $team;
    }

    /** A5: tenant setting, default 1. */
    public function teamScopeDepth(int $subInstituteId): int
    {
        $v = DB::table('tenant_setting')
            ->where('sub_institute_id', $subInstituteId)
            ->where('setting_key', 'team_scope_depth')
            ->value('setting_value');

        return is_numeric($v) ? max(1, (int) $v) : self::DEFAULT_TEAM_SCOPE_DEPTH;
    }

    /**
     * Every cycle currently in the data. For the periodic check — the same shape as
     * the polymorphic-integrity check for competency_kasba_item.
     *
     * @return array<int, int[]> manager chains that loop
     */
    public function findCycles(?int $subInstituteId = null): array
    {
        $q = DB::table('tbluser')->whereNotNull('reporting_manager_id');
        if ($subInstituteId !== null) {
            $q->where('sub_institute_id', $subInstituteId);
        }
        $edges = $q->pluck('reporting_manager_id', 'id')->all();

        $cycles = [];
        $settled = [];

        foreach (array_keys($edges) as $start) {
            if (isset($settled[$start])) {
                continue;
            }
            $path = [];
            $cursor = (int) $start;

            for ($i = 0; $i < self::MAX_WALK && isset($edges[$cursor]); $i++) {
                if (isset($path[$cursor])) {
                    $cycles[] = array_keys($path);
                    break;
                }
                $path[$cursor] = true;
                $cursor = (int) $edges[$cursor];
            }
            foreach (array_keys($path) as $n) {
                $settled[$n] = true;
            }
        }

        return $cycles;
    }
}
