<?php

namespace App\Console\Commands;

use App\Http\Controllers\AI\AiToolAgentController;
use App\Models\auth\tbluserModel;
use App\Services\Ai\AiScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Seed one real, working Automation agent per AI Stack module, for one tenant.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * The AI Stack's Automations tab reads `agentic_agents` / `agentic_agent_runs`
 * scoped to `sub_module IN (the 9 AI-Stack module_keys)` — see
 * AiToolAgentController::index(). That wiring is real and already works; what
 * was missing was any row to show, because no organisation had created an
 * agent through it yet. This seeds one, per module, so the tab has a genuine
 * first example instead of an empty state.
 *
 * ── IT USES THE REAL CODE PATH, LIKE lms:seed-ai-demo ───────────────────────
 *
 * Every agent is created via AiToolAgentController::store() and executed via
 * ::run(), through a real Request/Sanctum-token cycle — the same controller
 * the Automations tab itself calls. That means:
 *
 *   - the agent is validated exactly as a real admin's would be (module must
 *     be a real AI Stack key, tool must be a real ModuleDataSourceCatalog
 *     source registered to that module);
 *   - running it executes the real, tenant-scoped SQL read
 *     (ModuleDataSourceCatalog::run) and writes a genuine
 *     agentic_agent_runs row with the real output — including a real "0
 *     rows" result if the tenant has none of that data yet, which is the
 *     honest answer, not a failure;
 *   - because it writes to `agentic_agents`, the SAME agent is also an
 *     ordinary row in G2G's own Agentic AI module (routes/api.php's
 *     /api/agentic/*) — this is what "connects" the two, not new plumbing.
 *
 * Idempotent (skips a module that already has an agent for this tenant) and
 * dry-run by default, matching every other lms:seed-* command:
 *
 *   php artisan ai-stack:seed-automations --tenant=3
 *   php artisan ai-stack:seed-automations --tenant=3 --execute
 *   php artisan ai-stack:seed-automations --database=live --tenant=3 --execute
 */
class LmsSeedAiStackAutomations extends Command
{
    protected $signature = 'ai-stack:seed-automations
        {--database=mysql : Connection to seed}
        {--tenant= : sub_institute_id (required)}
        {--execute : Actually write and run. Without it, nothing is changed}';

    protected $description = 'Seed one real, module-scoped Automation agent per AI Stack module and run it once';

    /**
     * module_key => [data source name, agent name, description, one-line instruction].
     *
     * One source per module — the same ones ModuleDataSourceCatalog::forModule()
     * registers and AiToolAgentController already validates against. Text is
     * written per module's real function, not a template with the name swapped.
     */
    private const MODULES = [
        'lms_course_builder' => [
            'lms.course_builder',
            'Course Readiness Reporter',
            'Reads this organisation\'s course settings, chapters and content items, so an author can see how much material actually exists before asking AI to draft more.',
            'Report how many chapters and content items each in-progress course has, and flag any with none.',
        ],
        'lms_assessments' => [
            'lms.assessment_cycles',
            'Assessment Cycle Reporter',
            'Reads the organisation\'s live competency assessment cycles, so a reviewer can see which are open before generating new questions for one.',
            'List open assessment cycles and how many assessments have been recorded against each.',
        ],
        'lms_my_learning' => [
            'lms.my_enrolments',
            'Learner Enrolment Reporter',
            'Reads real course enrolments for this organisation\'s employees, so a recommendation is based on who is actually enrolled and how far they have progressed.',
            'Report enrolment and progress counts by department, not by name.',
        ],
        'lms_learning_catalog' => [
            'lms.catalog',
            'Catalog Coverage Reporter',
            'Reads the organisation\'s published course catalog and its enrolment counts, so an author can see which courses have no audience yet.',
            'List published courses with zero or near-zero enrolment.',
        ],
        'capability_library' => [
            'capability.jobroles',
            'Job Role Capability Reporter',
            'Reads real job roles and their mapped competencies, so an Employee Skill Objective is drafted against roles that actually exist in this organisation.',
            'Report job roles with the fewest mapped competencies, since those are the ones most likely to need one drafted.',
        ],
        'capability_explorer' => [
            'capability.entity_mappings',
            'Entity Mapping Reporter',
            'Reads the organisation\'s real entity-to-entity mappings, so an explanation of one is grounded in the mapping that actually exists, not a hypothetical one.',
            'Summarise how many entity mappings exist and which source fields they most commonly map from.',
        ],
        'talent_recruitment' => [
            'talent.pipeline',
            'Recruitment Pipeline Reporter',
            'Reads real job applications, interviews and offers for this organisation, so a JD or screening note is written against candidates actually in the pipeline.',
            'Report how many applications are at each pipeline stage, without naming or ranking individual candidates.',
        ],
        'talent_administration' => [
            'talent.workflows',
            'Hiring Workflow Reporter',
            'Reads the organisation\'s configured hiring workflows and stages, so an administrator can see what is actually set up before changing assessment scope.',
            'List configured workflows and how many stages each one has.',
        ],
        'task_my_tasks' => [
            'tasks.my_tasks',
            'Task Completion Reporter',
            'Reads real tasks assigned across this organisation, so a completion classification is checked against tasks that actually exist.',
            'Report open versus completed task counts by department, not by individual.',
        ],
    ];

    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $tenant = (int) $this->option('tenant');
        $execute = (bool) $this->option('execute');

        if ($tenant <= 0) {
            $this->error('--tenant=<sub_institute_id> is required.');

            return self::FAILURE;
        }

        DB::setDefaultConnection($connection);
        $db = DB::connection($connection);

        $this->info("Seeding AI Stack Automation agents on '{$connection}', tenant {$tenant}");
        $this->line($execute ? '  MODE: writing' : '  MODE: dry run (pass --execute to write)');
        $this->newLine();

        $admin = $this->administrator($db, $tenant);

        if (!$admin) {
            $this->error("  No administrator found for tenant {$tenant} - cannot create agents as no one.");

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;

        foreach (self::MODULES as $moduleKey => [$source, $name, $description, $instructions]) {
            $existing = $db->table('agentic_agents')
                ->where('sub_institute_id', $tenant)
                ->where('sub_module', $moduleKey)
                ->whereNull('deleted_at')
                ->value('id');

            if ($existing) {
                $this->line("  {$moduleKey}: already has agent #{$existing} - skipped");
                $skipped++;

                continue;
            }

            $this->line("  {$moduleKey}: CREATE \"{$name}\" (tool: {$source})");

            if (!$execute) {
                continue;
            }

            $result = $this->createAndRun($admin, $moduleKey, $source, $name, $description, $instructions);

            if ($result === null) {
                continue;
            }

            [$agentId, $runSummary] = $result;
            $this->line("                    -> agent #{$agentId}, run: {$runSummary}");
            $created++;
        }

        $this->newLine();

        if (!$execute) {
            $this->warn('Nothing was written. Re-run with --execute.');

            return self::SUCCESS;
        }

        $this->info("Done. Created {$created}, skipped {$skipped} (already present).");

        return self::SUCCESS;
    }

    /** The same administrator-lookup shape used by lms:seed-ai-demo. */
    private function administrator($db, int $tenant): ?int
    {
        return $db->table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->whereIn('user_profile_id', function ($q) use ($tenant) {
                $q->select('id')->from('tbluserprofilemaster')
                    ->where('sub_institute_id', $tenant)
                    ->where('role_key', 'administrator');
            })
            ->value('id');
    }

    /**
     * Create the agent and run it once, through the real controller - not a raw insert.
     *
     * @return array{0:int,1:string}|null [agent id, one-line run summary]
     */
    private function createAndRun(
        int $adminId,
        string $moduleKey,
        string $source,
        string $name,
        string $description,
        string $instructions
    ): ?array {
        $user = tbluserModel::find($adminId);
        $token = $user->createToken('seed-ai-stack-automations')->plainTextToken;
        $resolver = app(AiScopeResolver::class);

        // Calling the controller directly skips the HTTP kernel, so AiAuth and
        // AiContextHydrator never run. Their entire job is putting `ai_auth` and
        // `ai_scope` on the request from the same resolver used here - so this
        // replicates exactly what they do, rather than a second, divergent way of
        // deciding who the caller is.
        $hydrate = function (Request $request) use ($resolver, $user, $token) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
            $identity = $resolver->identityFor($user);
            $request->attributes->set('ai_auth', $identity);
            $request->attributes->set('ai_scope', $resolver->resolve($request, $identity));

            return $request;
        };

        try {
            $controller = app(AiToolAgentController::class);

            $storeRequest = $hydrate(Request::create('/x', 'POST', [
                'name' => $name,
                'description' => $description,
                'module' => $moduleKey,
                'tools_allowed' => [$source],
                'instructions' => $instructions,
                'status' => 'active',
            ]));

            $storeResponse = $controller->store($storeRequest);
            $storeBody = json_decode($storeResponse->getContent(), true);

            if (($storeBody['success'] ?? false) !== true) {
                $this->error('                    create failed: ' . ($storeBody['message'] ?? 'unknown'));

                return null;
            }

            $agentId = (int) $storeBody['data']['agent']['id'];

            $runRequest = $hydrate(Request::create('/x', 'POST', ['tool' => $source]));

            $runResponse = $controller->run($runRequest, $agentId);
            $runBody = json_decode($runResponse->getContent(), true);

            $run = $runBody['data']['run'] ?? [];
            $summary = ($run['status'] ?? 'unknown') === 'success'
                ? sprintf('%d row(s) read', (int) ($run['output']['total'] ?? 0))
                : 'did not complete: ' . ($run['error'] ?? $runBody['message'] ?? 'unknown');

            return [$agentId, $summary];
        } finally {
            $user->tokens()->where('name', 'seed-ai-stack-automations')->delete();
        }
    }
}
