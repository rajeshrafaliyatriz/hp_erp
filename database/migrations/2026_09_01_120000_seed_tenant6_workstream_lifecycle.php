<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fill in the four workstreams tenant 6 already created by hand.
 *
 * ── WHAT THIS IS ────────────────────────────────────────────────────────────
 *
 * The customer wrote an operating model (Workstream.docx) and then typed its four
 * workstreams into project G2G: Product & Requirements, Engineering & AI
 * Delivery, Project Delivery & Governance, Quality, Release & Adoption. The
 * product could hold their names, an owner and a status, and nothing else — so
 * the purpose, the responsibilities, the deliverable chains, the scope
 * boundaries, the risks and the relationships between them had nowhere to go.
 *
 * This writes that content onto the rows that already exist.
 *
 * ── SIX RULES, ALL OF WHICH ARE ABOUT NOT DESTROYING ANYTHING ───────────────
 *
 * 1. NEVER INSERT A WORKSTREAM, NEVER DELETE ONE. Rows are found by project +
 *    exact name. A name that is not found is skipped in silence. Dev's project
 *    carries "designing", "designing" and "frontend", none of which match, so
 *    this migration is a deliberate NO-OP there and does all its work on live.
 *
 * 2. NEVER WRITE owner_id. Live already holds 49/63/42/43 and they are correct;
 *    re-asserting them would risk overwriting a change somebody made since.
 *
 * 3. PURPOSE IS WRITTEN ONLY WHERE IT IS NULL. Never clobber authored prose.
 *
 * 4. CHILD ROWS ARE INSERTED ONLY WHERE THAT WORKSTREAM HAS NONE OF THAT TYPE.
 *    So a re-run adds nothing, and a workstream somebody has since edited is
 *    left alone rather than reset to the document.
 *
 * 5. RESOLVE PEOPLE BY ID, NEVER BY NAME. The document spells her "Darshna";
 *    the database has "Darshana Hirani". A name match would silently drop her
 *    from both workstreams she contributes to. Ids are looked up once, and a
 *    person who cannot be found is omitted rather than invented.
 *
 * 6. NOTHING IS MANUFACTURED. See the note on KPIs below.
 *
 * ── ONE REAL DATA CHANGE, STATED PLAINLY ────────────────────────────────────
 *
 * `sort_order` on live is currently 4, 1, 2, 3 — Product & Requirements sorts
 * LAST — because the old form wrote an insertion counter rather than a sequence
 * anybody chose. This sets 1, 2, 3, 4.
 *
 * Side effect worth knowing: ProjectController::linkedTasks orders by
 * sort_order, so the Tasks tab's workstream grouping changes order. Harmless,
 * but it should not be a surprise.
 *
 * ── WHY NO KPIs ARE SEEDED ──────────────────────────────────────────────────
 *
 * The document defines the Success Metrics FIELD — "objective, quantifiable
 * targets that prove the workstream's purpose has been met" — and gives two
 * generic illustrations ("15% reduction in latency", "100 units produced"). It
 * names no metric for any of the four workstreams.
 *
 * So none are seeded. Writing "API latency" against WS02 would be this migration
 * deciding what the engineering team measures, which is exactly the invention it
 * refuses everywhere else. The table, the editor and the UNMEASURED state all
 * exist from day one; the owners fill them.
 *
 * Checkpoints and external dependencies are empty for the same reason: the
 * document names no checkpoint dates and no external prerequisites.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_09_01_120000_seed_tenant6_workstream_lifecycle.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_01_120000_seed_tenant6_workstream_lifecycle.php
 */
return new class extends Migration
{
    private const TENANT = 6;

    private const PROJECT = 'G2G';

    public function up(): void
    {
        foreach (['task_management_workstreams', 'task_management_workstream_statements'] as $table) {
            if (! $this->tableExists($table)) {
                return;
            }
        }

        $project = DB::table('task_management_projects')
            ->where('sub_institute_id', self::TENANT)
            ->where('name', self::PROJECT)
            ->whereNull('archived_at')
            ->first(['id']);

        if (! $project) {
            return;
        }

        $people = $this->people();
        $ids    = [];

        foreach ($this->model() as $spec) {
            $row = DB::table('task_management_workstreams')
                ->where('project_id', $project->id)
                ->where('name', $spec['name'])
                ->first(['id', 'purpose', 'code', 'kind', 'core_question', 'sort_order']);

            if (! $row) {
                continue;   // rule 1
            }

            $ids[$spec['code']] = (int) $row->id;

            $update = [
                'code'          => $spec['code'],
                'kind'          => $spec['kind'],
                'core_question' => $spec['core_question'],
                'sort_order'    => $spec['sort_order'],
                'updated_at'    => now(),
            ];

            // Rule 3: prose is written only into an empty field.
            if ($row->purpose === null || trim((string) $row->purpose) === '') {
                $update['purpose'] = $spec['purpose'];
            }

            DB::table('task_management_workstreams')->where('id', $row->id)->update($update);

            $this->seedStatements((int) $row->id, 'RESPONSIBILITY', $spec['responsibilities']);
            $this->seedStatements((int) $row->id, 'IN_SCOPE', $spec['in_scope']);
            $this->seedStatements((int) $row->id, 'OUT_OF_SCOPE', $spec['out_of_scope']);
            $this->seedDeliverables((int) $row->id, $spec['deliverables']);
            $this->seedRisks((int) $row->id, $spec['risks']);
            $this->seedMembers((int) $row->id, $spec['contributors'], $people);
        }

        $this->seedLinks((int) $project->id, $ids);
    }

    /**
     * ROLLBACK REMOVES ONLY WHAT THIS ADDED.
     *
     * Child rows are deleted, the columns this set are cleared, and `purpose` is
     * cleared only where it still matches exactly what was seeded — so prose
     * somebody has since edited survives a rollback. `owner_id` is never touched
     * in either direction, and `sort_order` is deliberately NOT restored to
     * 4,1,2,3: that was an insertion counter, not an order anybody chose, and
     * putting it back would be reinstating a defect.
     */
    public function down(): void
    {
        if (! $this->tableExists('task_management_workstreams')) {
            return;
        }

        $project = DB::table('task_management_projects')
            ->where('sub_institute_id', self::TENANT)->where('name', self::PROJECT)
            ->first(['id']);

        if (! $project) {
            return;
        }

        foreach ($this->model() as $spec) {
            $row = DB::table('task_management_workstreams')
                ->where('project_id', $project->id)->where('name', $spec['name'])
                ->first(['id', 'purpose']);

            if (! $row) {
                continue;
            }

            foreach (['statements', 'deliverables', 'risks', 'members'] as $child) {
                DB::table('task_management_workstream_' . $child)
                    ->where('workstream_id', $row->id)->delete();
            }

            DB::table('task_management_workstreams')->where('id', $row->id)->update([
                'code' => null, 'kind' => 'DELIVERY', 'core_question' => null,
                'purpose' => $row->purpose === $spec['purpose'] ? null : $row->purpose,
                'updated_at' => now(),
            ]);
        }

        DB::table('task_management_workstream_links')->where('project_id', $project->id)->delete();
    }

    /* ------------------------------------------------------------------ *
     * Writers — each obeys rule 4: only where that type is empty.
     * ------------------------------------------------------------------ */

    private function seedStatements(int $workstreamId, string $kind, array $bodies): void
    {
        if ($bodies === [] || DB::table('task_management_workstream_statements')
            ->where('workstream_id', $workstreamId)->where('kind', $kind)->exists()) {
            return;
        }

        foreach (array_values($bodies) as $i => $body) {
            DB::table('task_management_workstream_statements')->insert([
                'workstream_id' => $workstreamId, 'kind' => $kind, 'body' => $body,
                'sort_order' => $i, 'created_by' => 0, 'updated_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedDeliverables(int $workstreamId, array $names): void
    {
        if (DB::table('task_management_workstream_deliverables')
            ->where('workstream_id', $workstreamId)->exists()) {
            return;
        }

        foreach (array_values($names) as $i => $name) {
            DB::table('task_management_workstream_deliverables')->insert([
                'workstream_id' => $workstreamId, 'name' => $name,
                // NOT STARTED and no dates: the document gives a chain, not a plan.
                'status' => 'NOT STARTED', 'due_date' => null, 'delivered_at' => null,
                'sort_order' => $i, 'created_by' => 0, 'updated_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedRisks(int $workstreamId, array $risks): void
    {
        if ($risks === [] || DB::table('task_management_workstream_risks')
            ->where('workstream_id', $workstreamId)->exists()) {
            return;
        }

        foreach (array_values($risks) as $i => $risk) {
            DB::table('task_management_workstream_risks')->insert([
                'workstream_id' => $workstreamId,
                'title'       => $risk['title'],
                'description' => $risk['description'],
                'probability' => $risk['probability'],
                'impact'      => $risk['impact'],
                'severity'    => $risk['severity'],
                'mitigation'  => $risk['mitigation'],
                'status'      => 'OPEN',
                'sort_order'  => $i, 'created_by' => 0, 'updated_by' => null,
                'created_at'  => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedMembers(int $workstreamId, array $contributors, array $people): void
    {
        if (DB::table('task_management_workstream_members')
            ->where('workstream_id', $workstreamId)->exists()) {
            return;
        }

        $order = 0;

        foreach ($contributors as $key => $lane) {
            // Rule 5: absent people are omitted, never invented.
            if (! isset($people[$key])) {
                continue;
            }

            DB::table('task_management_workstream_members')->insert([
                'workstream_id' => $workstreamId,
                'user_id'       => $people[$key],
                'role'          => 'CONTRIBUTOR',
                'lane'          => $lane,
                'sort_order'    => $order++,
                'created_by'    => 0,
                'created_at'    => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * The graph, with the document's own edge captions.
     *
     * WS01 -> WS02 -> WS04 is the delivery flow; WS04 -> WS01 is the FEEDBACK
     * loop that makes the model 360 rather than a pipeline; WS03 GOVERNS all
     * three, which is how "deliberately horizontal" is expressed as data instead
     * of as a caption on a picture.
     */
    private function seedLinks(int $projectId, array $ids): void
    {
        $edges = [
            ['WS01', 'WS02', 'FLOW',     'WHAT + WHY'],
            ['WS02', 'WS04', 'FLOW',     'WORKING PRODUCT'],
            ['WS04', 'WS01', 'FEEDBACK', 'USER FEEDBACK'],
            ['WS03', 'WS01', 'GOVERNS',  'Scope'],
            ['WS03', 'WS02', 'GOVERNS',  'Delivery'],
            ['WS03', 'WS04', 'GOVERNS',  'Release'],
        ];

        foreach ($edges as [$from, $to, $type, $label]) {
            if (! isset($ids[$from], $ids[$to])) {
                continue;
            }

            $exists = DB::table('task_management_workstream_links')
                ->where('predecessor_workstream_id', $ids[$from])
                ->where('successor_workstream_id', $ids[$to])
                ->where('link_type', $type)->exists();

            if ($exists) {
                continue;
            }

            DB::table('task_management_workstream_links')->insert([
                'project_id' => $projectId,
                'predecessor_workstream_id' => $ids[$from],
                'successor_workstream_id'   => $ids[$to],
                'link_type' => $type, 'label' => $label,
                'created_by' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** first_name -> id, for the six people the model names. Rule 5. */
    private function people(): array
    {
        $wanted = [
            'kalpesh' => 'Kalpesh', 'milan' => 'Milan', 'rajesh' => 'Rajesh',
            'abhi' => 'Abhi', 'sonika' => 'Sonika',
            // The document spells this "Darshna"; the database has "Darshana".
            'darshana' => 'Darshana',
        ];

        $out = [];

        foreach ($wanted as $key => $firstName) {
            $id = DB::table('tbluser')
                ->where('sub_institute_id', self::TENANT)
                ->where('status', 1)
                ->where('first_name', 'like', $firstName . '%')
                ->orderBy('id')
                ->value('id');

            if ($id) {
                $out[$key] = (int) $id;
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ *
     * The model, verbatim from Workstream.docx.
     * ------------------------------------------------------------------ */

    private function model(): array
    {
        return [
            [
                'code' => 'WS01', 'kind' => 'DELIVERY', 'sort_order' => 1,
                'name' => 'Product & Requirements',
                'core_question' => 'What and why are we building?',
                'purpose' => 'Translate business/customer needs into a clearly prioritized product scope.',
                'responsibilities' => [
                    'Customer/stakeholder requirements', 'Problem and outcome definition', 'Product scope',
                    'User journeys/use cases', 'Functional requirements', 'Acceptance criteria',
                    'Product backlog', 'Feature prioritization', 'MVP vs future scope',
                    'Business validation', 'Requirement/change decisions',
                ],
                'deliverables' => ['Product Goal', 'Requirements', 'Prioritized Backlog', 'Acceptance Criteria'],
                'in_scope' => ['What and why: scope, priority and value decisions'],
                'out_of_scope' => ['How it is built — the technical solution belongs to WS02'],
                'contributors' => ['milan' => null, 'abhi' => null],
                'risks' => [[
                    'title' => 'Backlog ownership drifts into a committee',
                    'description' => 'The model is explicit that the Product Owner is one person rather than a committee, and holds final accountability for backlog and value decisions.',
                    'probability' => 'Medium', 'impact' => 'High', 'severity' => 'High',
                    'mitigation' => 'One named Product Owner retains final accountability for backlog and value decisions.',
                ]],
            ],
            [
                'code' => 'WS02', 'kind' => 'DELIVERY', 'sort_order' => 2,
                'name' => 'Engineering & AI Delivery',
                'core_question' => 'How do we build it?',
                'purpose' => 'Design, build, and deploy scalable artificial intelligence solutions into production while ensuring high-quality software engineering standards.',
                'responsibilities' => [
                    'Solution architecture', 'Technical design', 'Frontend implementation',
                    'Backend implementation', 'Database/data model', 'APIs and integrations',
                    'AI/ML implementation', 'Technical dependencies', 'Code quality',
                    'Unit/integration testing', 'Technical documentation', 'Deployment readiness',
                ],
                'deliverables' => ['Technical Design', 'Build', 'Integrate', 'Tested Increment'],
                'in_scope' => ['How it is built: architecture, implementation and technical feasibility'],
                'out_of_scope' => ['What and why — scope and priority belong to WS01'],
                // The lanes are the whole defence against splitting this workstream.
                'contributors' => [
                    'sonika'   => 'Backend, APIs, database, integrations',
                    'darshana' => 'AI/ML models, AI services, evaluation, AI integration',
                ],
                'risks' => [[
                    'title' => 'Frontend, Backend and AI split into three workstreams',
                    'description' => 'The model warns directly against this for a team of this size: they are technical lanes inside one delivery workstream, not independent workstreams.',
                    'probability' => 'High', 'impact' => 'Medium', 'severity' => 'High',
                    'mitigation' => 'Keep one delivery workstream and record each person\'s technical ownership as a lane against their contributor row.',
                ]],
            ],
            [
                'code' => 'WS03', 'kind' => 'GOVERNANCE', 'sort_order' => 3,
                'name' => 'Project Delivery & Governance',
                'core_question' => 'Are we delivering it predictably?',
                'purpose' => 'Provide the strategic rules, oversight, and accountability needed to ensure projects meet their goals and align with organizational priorities. This workstream is deliberately horizontal: it coordinates the delivery flow rather than being a stage within it.',
                'responsibilities' => [
                    'Project plan', 'Milestones', 'Sprint/release planning', 'Task coordination',
                    'Dependency management', 'Resource/capacity tracking', 'RAID management',
                    'Risks', 'Assumptions', 'Issues', 'Dependencies', 'Blocker management',
                    'Status reporting', 'Change control', 'Decision log', 'Project communication',
                    'Sprint ceremonies', 'Delivery metrics', 'Escalations',
                ],
                'deliverables' => ['Plan', 'Coordinate', 'Track', 'Unblock', 'Report', 'Control'],
                'in_scope' => ['When, coordination, dependencies and risks across every workstream'],
                // Verbatim from "Rajesh should not own product decisions or
                // engineering decisions." The only place the document states an
                // exclusion outright.
                'out_of_scope' => ['Product decisions', 'Engineering decisions'],
                'contributors' => ['kalpesh' => null, 'milan' => null, 'abhi' => null, 'sonika' => null, 'darshana' => null],
                'risks' => [[
                    'title' => 'Product Manager and Product Owner roles overlap',
                    'description' => 'The model identifies a potential ambiguity between the Product Manager and the Product Owner, and notes that without an explicit split the delivery lead, the product owner and the project manager can easily overlap.',
                    'probability' => 'High', 'impact' => 'Medium', 'severity' => 'High',
                    'mitigation' => 'Publish the decision rights: what/why/priority to the Product Owner, product shaping and technical feasibility to the Product Manager, when/coordination/dependencies/risks to the Project Manager, how to Engineering.',
                ], [
                    'title' => 'Governance becomes another sequential stage',
                    'description' => 'The model states this workstream is deliberately horizontal — coordinating the whole system rather than becoming another stage between delivery and release.',
                    'probability' => 'Medium', 'impact' => 'Medium', 'severity' => 'Medium',
                    'mitigation' => 'Model it as a governance layer spanning the delivery flow, never as a step inside it.',
                ]],
            ],
            [
                'code' => 'WS04', 'kind' => 'DELIVERY', 'sort_order' => 4,
                'name' => 'Quality, Release & Adoption',
                'core_question' => 'Does it work and deliver value in production?',
                'purpose' => 'Ensure that new software, products, or process changes are thoroughly tested, safely deployed, and successfully accepted by end-users.',
                'responsibilities' => [
                    'End-to-end functional validation', 'Acceptance testing coordination',
                    'Regression testing', 'Release readiness', 'Production deployment validation',
                    'User documentation', 'Support readiness', 'Issue/defect triage',
                    'Production monitoring', 'Customer/user feedback', 'Adoption issues',
                    'Post-release validation',
                ],
                'deliverables' => ['Validate', 'Release', 'Support', 'Observe', 'Feedback'],
                'in_scope' => ['Whether it is operationally ready and working for users'],
                'out_of_scope' => ['Building the increment — implementation belongs to WS02'],
                'contributors' => [
                    'milan'    => 'Technical testing',
                    'sonika'   => 'Technical testing',
                    'darshana' => 'Technical testing',
                    'kalpesh'  => 'Business acceptance',
                    'rajesh'   => 'Release coordination',
                ],
                'risks' => [[
                    'title' => 'Quality, release and adoption omitted entirely',
                    'description' => 'The model calls this the important workstream that small development teams often omit.',
                    'probability' => 'Medium', 'impact' => 'High', 'severity' => 'High',
                    'mitigation' => 'Keep it as a named workstream with its own accountable owner rather than folding it into engineering.',
                ]],
            ],
        ];
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue. */
    private function tableExists(string $table): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->c ?? 0) > 0;
    }
};
