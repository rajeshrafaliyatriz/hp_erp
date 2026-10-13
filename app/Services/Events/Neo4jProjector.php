<?php

namespace App\Services\Events;

use App\Services\Neo4jService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PROJECTOR — the Neo4j Organization/Department/JobRole graph. (kind = P)
 *
 * The FIRST working sync for this graph. The thing that looked like a sync
 * before this (Neo4jSyncController::sync()) is dead code: it reads
 * LmsDataContentNeo4j::all(), and that table does not exist in this
 * database, so calling it throws before a single row is read. Separately,
 * even its intent was wrong - it MERGEd `:JobRoles`/`:Departments` (plural),
 * while every read controller (JobRoleGraphController,
 * DepartmentGraphController, OrganizationGraphController) queries
 * `:JobRole`/`:Department` (singular). This class writes the labels the
 * readers actually query.
 *
 * PURE, like every other projector here: it only MERGEs the graph, never
 * invokes a reactor, and is safe to call repeatedly (MERGE, not CREATE).
 *
 * ALSO PROJECTS (Phase 3 step 2): Competency and KasbaItem nodes, and the
 * JobRole-[:REQUIRES_COMPETENCY]->Competency edge, sourced from `competency`
 * + `competency_kasba_item` + `jobrole_competency_map` - not from
 * `s_competency_framework_items`, which holds a second, non-canonical
 * required-proficiency column this product does not actually read from.
 * Proficiency itself is never written here: it is computed on read by
 * ProficiencyService::rollUp(), by design (see EventCatalogue's own
 * docblock on PROFICIENCY IS NOT PROJECTED AT ALL) - this class only
 * projects what a role REQUIRES, never what an employee HAS.
 *
 * KasbaItem nodes are projected only for items with a resolved item_id -
 * a label-only item (item_id null, a documented "holding state" in the
 * source schema) has no stable identity to key a node on, so it is skipped
 * rather than given one invented here.
 *
 * STILL NOT COVERED: the REQUIRES_SKILL/KNOWLEDGE/ABILITY/BEHAVIOUR/ATTITUDE
 * KASBA ontology already in this graph (a different, older layer from the
 * Competency/KasbaItem one above) has no reproducible writer anywhere in
 * this codebase - it was loaded once, outside this app, by a process
 * nobody can find.
 *
 * PHASE 7.2 WIRED THE REST OF departmentController::store(): every formType
 * branch now emits (add/edit department, edit sub_department, the nested
 * sub-department create inside edit-department, and the "import" branch's
 * two insert paths) plus DepartmentManagementController::store()/update()
 * (a separate, newer sub-department surface) and SchoolSetupController's
 * bulk tenant-onboarding inserts. jobroletexonomycontroller::update() is
 * NOT wired and cannot be: its body is dead, commented-out code with no
 * live route reaching it - there is no write to emit an event for. A full
 * audit of this codebase's OTHER legacy CRUD controllers beyond the ones
 * that already fed this projector remains follow-up work, not assumed
 * complete here.
 *
 * PHASE 7.2 ADDED Person-[:REPORTS_TO]->Person, sourced from
 * tbluser.reporting_manager_id via ReportingLineController::applyOne() - the
 * one write path for that column (ReportingLineValidator's cycle check runs
 * before every write). This is the FIRST Person node this graph has; it
 * carries no properties beyond personId yet, because reporting line is all
 * this phase asked for, not a full employee/org-structure Person layer.
 */
class Neo4jProjector
{
    public const CONSUMER = 'neo4j_projector';

    public function __construct(private readonly Neo4jService $neo4j)
    {
    }

    private const HANDLED_TYPES = [
        'organization.changed',
        'department.changed',
        'jobrole.changed',
        'competency.changed',
        'jobrole_competency_map.changed',
        'employee_reporting_line.changed',
    ];

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLED_TYPES, true);
    }

    /**
     * Project one event. Safe to call repeatedly - every write below is a
     * MERGE. A Neo4j failure is caught here (not left to propagate) so one
     * bad event marks itself `failed` in the delivery ledger and catchUp()
     * keeps moving through the rest of the batch, the same way a graph
     * outage degrades on the K-12 side rather than taking the whole run down.
     */
    public function project(object $event): void
    {
        $payload = json_decode((string) $event->payload, true) ?: [];
        $entityId = (int) ($event->entity_id ?? 0);

        try {
            match ($event->type) {
                'organization.changed' => $this->projectOrganization($entityId, $payload),
                'department.changed' => $this->projectDepartment($entityId, $payload),
                'jobrole.changed' => $this->projectJobRole($entityId, $payload),
                'competency.changed' => $this->projectCompetency($entityId, $payload),
                'jobrole_competency_map.changed' => $this->projectJobroleCompetencyMap($entityId, $payload),
                'employee_reporting_line.changed' => $this->projectReportingLine($entityId, $payload),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('Neo4jProjector: failed to project event.', [
                'event_id' => (int) $event->id, 'type' => $event->type, 'error' => $e->getMessage(),
            ]);

            DB::table('g2g_event_delivery')->updateOrInsert(
                ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
                ['status' => 'failed', 'attempts' => DB::raw('attempts + 1'), 'last_error' => mb_substr($e->getMessage(), 0, 2000)]
            );

            return;
        }

        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            ['status' => 'done', 'attempts' => DB::raw('attempts + 1'), 'completed_at' => now(), 'last_error' => null]
        );
    }

    /**
     * Phase 7.2 — extracted out of project()'s match arm (previously inline)
     * so a backfill can call the same MERGE directly, without an event
     * envelope or a delivery-ledger row. Public for that reason.
     */
    public function projectOrganization(int $orgId, array $payload): void
    {
        $this->neo4j->getClient()->run(
            'MERGE (o:Organization {orgId: $id}) SET o += $props',
            [
                'id' => $orgId,
                'props' => [
                    'name'     => (string) ($payload['legal_name'] ?? ''),
                    'industry' => (string) ($payload['industry'] ?? ''),
                ],
            ]
        );
    }

    /** Phase 7.2 — same extraction reasoning as projectOrganization(). */
    public function projectDepartment(int $deptId, array $payload): void
    {
        $this->neo4j->getClient()->run(
            'MERGE (d:Department {deptId: $id}) SET d += $props',
            [
                'id' => $deptId,
                'props' => [
                    'name' => (string) ($payload['department'] ?? ''),
                ],
            ]
        );
    }

    /** Phase 7.2 — same extraction reasoning as projectOrganization(). */
    public function projectJobRole(int $jobRoleId, array $payload): void
    {
        $this->neo4j->getClient()->run(
            'MERGE (j:JobRole {jobRoleId: $id}) SET j += $props',
            [
                'id' => $jobRoleId,
                'props' => [
                    'category' => (string) ($payload['jobrole_category'] ?? ''),
                ],
            ]
        );
    }

    /**
     * Competency node + its KasbaItem nodes/edges. `competencyId`/`kasbaType`/
     * `kasbaItemId` are the merge keys the graph's property names use
     * consistently in this class - entityId here is `competency.id`, and
     * `payload['items']` is the exact `items` array the request sent
     * (competency_kasba_item is inserted, not upserted by id, in the SQL
     * source, so there is no per-item row id to key on here either; the
     * resolved item_id is the only stable identity available).
     */
    private function projectCompetency(int $competencyId, array $payload): void
    {
        $this->neo4j->getClient()->run(
            'MERGE (c:Competency {competencyId: $id}) SET c += $props',
            [
                'id' => $competencyId,
                'props' => [
                    'code' => (string) ($payload['code'] ?? ''),
                    'name' => (string) ($payload['name'] ?? ''),
                    'competencyType' => (string) ($payload['competency_type'] ?? ''),
                ],
            ]
        );

        foreach ((array) ($payload['items'] ?? []) as $item) {
            $itemId = $item['item_id'] ?? null;

            if (empty($itemId)) {
                continue; // label-only holding state - no stable identity to key a node on
            }

            $this->neo4j->getClient()->run(
                'MERGE (k:KasbaItem {kasbaType: $type, itemId: $itemId})
                 SET k.weight = $weight
                 WITH k
                 MATCH (c:Competency {competencyId: $competencyId})
                 MERGE (c)-[:HAS_KASBA_ITEM]->(k)',
                [
                    'type' => (string) $item['kasba_type'],
                    'itemId' => (int) $itemId,
                    'weight' => (float) ($item['weight'] ?? 1.0),
                    'competencyId' => $competencyId,
                ]
            );
        }
    }

    /**
     * JobRole-[:REQUIRES_COMPETENCY]->Competency, full-replace to match the
     * SQL source's own sync semantics: jobrole_competency_map.store() deletes
     * any row not in the submitted list, so this removes any edge not in it
     * too, rather than only ever adding.
     */
    private function projectJobroleCompetencyMap(int $jobroleId, array $payload): void
    {
        $items = (array) ($payload['items'] ?? []);
        $keepIds = array_values(array_map(static fn ($item) => (int) $item['competency_id'], $items));

        $this->neo4j->getClient()->run(
            'MATCH (jr:JobRole {jobRoleId: $id})-[r:REQUIRES_COMPETENCY]->(c:Competency)
             WHERE NOT c.competencyId IN $keepIds
             DELETE r',
            ['id' => $jobroleId, 'keepIds' => $keepIds]
        );

        foreach ($items as $item) {
            $this->neo4j->getClient()->run(
                'MERGE (jr:JobRole {jobRoleId: $jobroleId})
                 MERGE (c:Competency {competencyId: $competencyId})
                 MERGE (jr)-[r:REQUIRES_COMPETENCY]->(c)
                 SET r.requiredProficiency = $requiredProficiency, r.isMandatory = $isMandatory',
                [
                    'jobroleId' => $jobroleId,
                    'competencyId' => (int) $item['competency_id'],
                    'requiredProficiency' => (int) $item['required_proficiency'],
                    'isMandatory' => ! empty($item['is_mandatory']),
                ]
            );
        }
    }

    /**
     * Person-[:REPORTS_TO]->Person, full-replace per employee (Phase 7.2).
     *
     * `entityId` is the employee's own tbluser.id, so their :Person node is
     * MERGEd unconditionally first - an employee with no manager yet (the
     * org head, or anyone not assigned one) still gets a node, just no
     * outgoing edge. The existing edge is dropped before writing the new
     * one, same full-replace reasoning as projectJobroleCompetencyMap():
     * ReportingLineController::applyOne() treats manager_id: null as a
     * legitimate clear, and a stale edge left behind would misreport who
     * that person currently reports to.
     */
    private function projectReportingLine(int $employeeId, array $payload): void
    {
        $managerId = $payload['manager_id'] ?? null;

        $this->neo4j->getClient()->run(
            'MERGE (p:Person {personId: $id})',
            ['id' => $employeeId]
        );

        $this->neo4j->getClient()->run(
            'MATCH (p:Person {personId: $id})-[r:REPORTS_TO]->()
             DELETE r',
            ['id' => $employeeId]
        );

        if ($managerId !== null) {
            $this->neo4j->getClient()->run(
                'MERGE (p:Person {personId: $id})
                 MERGE (m:Person {personId: $managerId})
                 MERGE (p)-[:REPORTS_TO]->(m)',
                ['id' => $employeeId, 'managerId' => (int) $managerId]
            );
        }
    }

    /** Project everything not yet delivered (done OR failed) to this consumer. */
    public function catchUp(int $limit = 500): int
    {
        $events = DB::table('g2g_event as e')
            ->leftJoin('g2g_event_delivery as d', function ($join) {
                $join->on('d.event_id', '=', 'e.id')->where('d.consumer', '=', self::CONSUMER);
            })
            ->whereNull('d.id')
            ->whereIn('e.type', self::HANDLED_TYPES)
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.*']);

        foreach ($events as $event) {
            $this->project($event);
        }

        return $events->count();
    }

    /** Rebuild, per the same convention as AuditLogProjector::rebuild() — this projector owns no table to truncate, only graph nodes, so only its own ledger is cleared. */
    public function rebuild(): int
    {
        DB::table('g2g_event_delivery')->where('consumer', self::CONSUMER)->delete();

        $done = 0;
        while (($n = $this->catchUp()) > 0) {
            $done += $n;
        }

        return $done;
    }
}
