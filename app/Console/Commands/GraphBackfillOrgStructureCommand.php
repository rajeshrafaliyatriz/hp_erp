<?php

namespace App\Console\Commands;

use App\Services\Events\Neo4jProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7.2 — one-time catch-up for every Organization/Department/JobRole
 * row that existed BEFORE event emission was wired to its write path.
 *
 * graph:catch-up only drains g2g_event — it has nothing to replay for a row
 * that was created before EventRecorder::record() was ever called for its
 * table, which is every row today except what's been written since this
 * session's wiring. This calls Neo4jProjector's MERGE methods directly, the
 * same way K-12's coherence-map-nightly-sync bypasses its own outbox for a
 * full re-sync: a backfill is not a change notification, so it does not
 * belong in the event store or the delivery ledger.
 *
 * Safe to re-run: every write below is the same idempotent MERGE
 * graph:catch-up itself calls.
 */
class GraphBackfillOrgStructureCommand extends Command
{
    protected $signature = 'graph:backfill-org-structure {--tenant=} {--dry-run}';

    protected $description = 'One-time backfill: project every existing Organization/Department/JobRole row into Neo4j';

    public function handle(Neo4jProjector $projector): int
    {
        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $dryRun = (bool) $this->option('dry-run');

        $counts = [
            'organization' => $this->backfillOrganizations($projector, $tenant, $dryRun),
            'department' => $this->backfillDepartments($projector, $tenant, $dryRun),
            'jobrole' => $this->backfillJobRoles($projector, $tenant, $dryRun),
        ];

        $this->table(['Label', 'Rows projected'], [
            ['Organization', $counts['organization']],
            ['Department', $counts['department']],
            ['JobRole', $counts['jobrole']],
        ]);

        if ($dryRun) {
            $this->warn('Dry run — nothing was written to Neo4j.');
        }

        return self::SUCCESS;
    }

    private function backfillOrganizations(Neo4jProjector $projector, ?int $tenant, bool $dryRun): int
    {
        $rows = DB::table('org_details')
            ->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))
            ->get(['id', 'legal_name', 'industry']);

        foreach ($rows as $row) {
            if (! $dryRun) {
                $projector->projectOrganization((int) $row->id, [
                    'legal_name' => $row->legal_name,
                    'industry' => $row->industry,
                ]);
            }
        }

        return $rows->count();
    }

    private function backfillDepartments(Neo4jProjector $projector, ?int $tenant, bool $dryRun): int
    {
        $rows = DB::table('hrms_departments')
            ->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))
            ->get(['id', 'department']);

        foreach ($rows as $row) {
            if (! $dryRun) {
                $projector->projectDepartment((int) $row->id, ['department' => $row->department]);
            }
        }

        return $rows->count();
    }

    private function backfillJobRoles(Neo4jProjector $projector, ?int $tenant, bool $dryRun): int
    {
        $rows = DB::table('s_user_jobrole')
            ->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))
            ->get(['id', 'jobrole_category']);

        foreach ($rows as $row) {
            if (! $dryRun) {
                $projector->projectJobRole((int) $row->id, ['jobrole_category' => $row->jobrole_category]);
            }
        }

        return $rows->count();
    }
}
