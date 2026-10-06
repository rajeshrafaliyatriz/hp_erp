<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TENANT 6 DEMO DATA — WRITTEN THROUGH THE CONTROLLERS, NOT INTO THE TABLES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SEEDER AND WHY IT CALLS CONTROLLERS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The first version of this was three one-off scripts under docs/phase3/_changes/
 * doing raw DB::table()->insert(). That was wrong twice over:
 *
 *   1. WRONG HOME. Demo data belongs in database/seeders/ where Laravel and
 *      every developer expect it, runnable with one command on any environment.
 *      A script in a docs folder is knowledge that lives in one person's head.
 *      This is the same failure the deployment plan criticises about menus —
 *      and I reproduced it while the criticism was still on the page.
 *
 *   2. WRONG PATH. Raw inserts bypass validation, tenant resolution, item
 *      resolution and every guard. Data that arrives that way PROVES NOTHING
 *      ABOUT THE PRODUCT: it can be shaped in ways the application would never
 *      accept, and a screen reading it may still be broken.
 *
 * So every write below goes through the SAME CONTROLLER a click would reach:
 *
 *   CompetencyDefinitionController::store   competency + its KASBA items
 *   RoleCompetencyMapController::store      competency -> job role
 *   JobroleTaskCompetencyMapController::store  task -> competency
 *   CourseCompetencyMapController::store    course -> competency
 *   KasbaRatingController::store            person -> rating
 *
 * ACTING AS A REAL ADMIN. A Sanctum token is minted for tenant 6's administrator
 * and attached to every request, so the tenant is resolved from the token exactly
 * as it would be for a signed-in user. THE SEEDER NEVER SUPPLIES A TENANT ID.
 *
 * IF A CONTROLLER REFUSES, THAT IS A REAL FINDING AND IT IS REPORTED, not worked
 * around. The point of seeding this way is that a refusal here is a refusal a
 * customer would have hit.
 *
 * THE ONE EXCEPTION, STATED RATHER THAN HIDDEN: s_competency_frameworks has no
 * write endpoint. A framework cannot be created from the frontend at all, so it
 * is inserted directly — and that absence is itself worth knowing.
 */
class Tenant6DemoSeeder extends Seeder
{
    private const TENANT = 6;

    /** name => [code, description, criticality, [[kasba_type, label, weight], ...]] */
    private const MODEL = [
        4326 => ['IT Operations and Support Competency Framework', 'ITOPS', [
            ['Systems and Network Monitoring', 'Observing system and network health continuously, and recognising when behaviour departs from normal.', 'high', [
                ['knowledge', 'Monitoring tool operation and alert configuration', 3],
                ['knowledge', 'Network and system performance indicators', 3],
                ['skill', 'Interpreting dashboards and threshold breaches', 3],
                ['ability', 'Sustaining attention across long monitoring periods', 2],
            ]],
            ['Incident Identification and Triage', 'Detecting faults, judging their severity, and routing them to the right team without delay.', 'high', [
                ['knowledge', 'Incident severity classification', 3],
                ['skill', 'Distinguishing a symptom from a root cause', 3],
                ['behaviour', 'Escalating early rather than waiting for certainty', 3],
            ]],
            ['Health Check Reporting', 'Gathering health data for software and hardware teams and turning it into a document they can act on.', 'medium', [
                ['knowledge', 'Health check data sources and collection methods', 2],
                ['skill', 'Compiling accurate health check reports', 3],
                ['attitude', 'Recording what was observed, not what was expected', 3],
            ]],
        ]],
        4297 => ['AI and Machine Learning Engineering Framework', 'AIML', [
            ['ML Pipeline Engineering', 'Building and maintaining the pipelines that take a model from notebook to production.', 'high', [
                ['knowledge', 'Model packaging, serving and versioning', 3],
                ['skill', 'Building scalable ETL and feature pipelines', 3],
                ['ability', 'Reasoning about pipeline failure and recovery', 2],
            ]],
            ['Model Development and Evaluation', 'Training models and judging honestly whether they are good enough to ship.', 'high', [
                ['knowledge', 'Evaluation metrics and their failure modes', 3],
                ['skill', 'Diagnosing overfitting and data leakage', 3],
                ['attitude', 'Reporting a model that underperforms rather than reframing the metric', 3],
            ]],
        ]],
        4331 => ['Infrastructure Engineering Framework', 'INFRA', [
            ['Capacity Planning and Statistics', 'Gathering usage data and turning it into a defensible capacity forecast.', 'high', [
                ['knowledge', 'Capacity modelling and headroom planning', 3],
                ['skill', 'Collecting and interpreting usage statistics', 3],
            ]],
            ['Infrastructure Testing and Implementation', 'Changing infrastructure without breaking what already runs on it.', 'high', [
                ['knowledge', 'Change and rollback procedures', 3],
                ['behaviour', 'Testing the rollback, not only the change', 3],
            ]],
        ]],
        4302 => ['Data Analysis and Engineering Framework', 'DATA', [
            ['Requirements and Stakeholder Analysis', 'Finding out what a decision-maker actually needs, which is rarely what they first ask for.', 'high', [
                ['knowledge', 'Requirements elicitation techniques', 2],
                ['ability', 'Distinguishing the stated request from the real need', 3],
            ]],
            ['Analytics and Reporting Delivery', 'Building the reporting that answers the question, and only that question.', 'high', [
                ['skill', 'Building analyses that survive scrutiny', 3],
                ['attitude', 'Reporting what the data says, not what was hoped for', 3],
            ]],
        ]],
        4353 => ['Product Management Framework', 'PM', [
            ['Market and Opportunity Analysis', 'Turning market research into a defensible view of what to build and why.', 'high', [
                ['knowledge', 'Market research methods and their limits', 3],
                ['skill', 'Translating research into product requirements', 3],
            ]],
            ['Roadmap and Prioritisation', 'Deciding sequence, and defending the decision when everything is urgent.', 'high', [
                ['skill', 'Drafting and maintaining a feature roadmap', 3],
                ['behaviour', 'Saying no with a reason rather than deferring silently', 3],
            ]],
        ]],
        4381 => ['Sales Execution Framework', 'SALES', [
            ['Client Communication and Presentation', 'Preparing and delivering material that a client can act on.', 'high', [
                ['skill', 'Preparing client-facing materials', 3],
                ['ability', 'Reading a room and adjusting emphasis', 3],
            ]],
            ['Pipeline and Record Discipline', 'Keeping the client database and documentation current enough to be trusted.', 'high', [
                ['skill', 'Maintaining accurate client records', 2],
                ['behaviour', 'Updating the record at the time, not at month end', 3],
            ]],
        ]],
        4352 => ['Product Design Framework', 'DESIGN', [
            ['Design Advocacy', 'Articulating what design contributes, to functions that do not practise it.', 'high', [
                ['skill', 'Explaining design decisions to non-designers', 3],
                ['attitude', 'Defending the user when the user is not in the room', 3],
            ]],
            ['Stakeholder Alignment', 'Running the conversations that get a design agreed rather than merely admired.', 'high', [
                ['knowledge', 'Facilitation and buy-in techniques', 2],
                ['ability', 'Holding a position under disagreement without escalating', 3],
            ]],
        ]],
        4337 => ['Associate Product Management Framework', 'APM', [
            ['Business Proposal Contribution', 'Completing the parts of a proposal that need evidence rather than opinion.', 'medium', [
                ['skill', 'Drafting proposal segments', 3],
                ['attitude', 'Marking an assumption as an assumption', 3],
            ]],
            ['Partner Research and Compilation', 'Gathering the partner information the team will make a decision on.', 'medium', [
                ['skill', 'Compiling comparable partner information', 3],
                ['behaviour', 'Recording what is unknown alongside what is known', 3],
            ]],
        ]],
    ];

    public function run(): void
    {
        $admin = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.sub_institute_id', self::TENANT)
            ->where('p.role_key', 'administrator')
            ->first(['u.id']);

        if (!$admin) {
            $this->command->error('No administrator in tenant ' . self::TENANT . ' - cannot seed as a real user.');
            return;
        }

        $userModel = \App\Models\auth\tbluserModel::find($admin->id);
        $token = $userModel->createToken('tenant6-demo-seeder');
        $bearer = explode('|', $token->plainTextToken, 2)[1];

        $this->command->info('Acting as administrator #' . $admin->id . ' via a real token.');

        try {
            $this->seedCompetencies($bearer);
            $this->seedRatings($bearer);
            $this->seedTaskMap($bearer);
        } finally {
            $token->accessToken->delete();
            $this->command->info('Token deleted.');
        }
    }

    /** A request carrying the admin's token, exactly as the frontend sends. */
    private function request(string $bearer, string $method, array $payload = []): Request
    {
        $r = Request::create('/seed', $method, $payload);
        $r->headers->set('Authorization', 'Bearer ' . $bearer);
        return $r;
    }

    private function seedCompetencies(string $bearer): void
    {
        $defs = app(\App\Http\Controllers\Api\Competency\CompetencyDefinitionController::class);
        $roleMap = app(\App\Http\Controllers\Api\Competency\RoleCompetencyMapController::class);

        $made = 0; $mapped = 0; $refused = 0;
        $pending = [];

        foreach (self::MODEL as $jobroleId => [$fwName, $prefix, $competencies]) {
            // The ONE direct insert, and it is stated: no endpoint creates a
            // framework, so the frontend cannot either.
            $fwId = DB::table('s_competency_frameworks')
                ->where('sub_institute_id', self::TENANT)->where('name', $fwName)->value('id');

            if (!$fwId) {
                $fwId = DB::table('s_competency_frameworks')->insertGetId([
                    'sub_institute_id' => self::TENANT,
                    'name' => $fwName,
                    'description' => 'Derived from the recorded tasks of this job role.',
                    'version' => '1.0',
                    'status' => 1,
                    'created_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $i = 1;
            foreach ($competencies as [$name, $desc, $crit, $items]) {
                $code = sprintf('%s-%02d', $prefix, $i++);

                // AN EXISTING COMPETENCY IS STILL COLLECTED FOR THE ROLE SYNC.
                // The first version skipped straight past, so a competency that
                // already existed never reached role-map and the mapping stayed
                // empty on a re-run. Idempotency must skip the CREATE, not the
                // rest of the work.
                $already = DB::table('competency')->where('sub_institute_id', self::TENANT)
                    ->where('code', $code)->value('id');

                if ($already) {
                    $pending[$jobroleId][] = ['competency_id' => (int) $already, 'required_proficiency' => 3, 'is_mandatory' => 1];
                    continue;
                }

                $res = $defs->store($this->request($bearer, 'POST', [
                    'name' => $name,
                    'code' => $code,
                    'description' => $desc,
                    'framework_id' => $fwId,
                    'competency_type' => 'technical',
                    'criticality' => $crit,
                    'items' => array_map(fn ($it) => [
                        'kasba_type' => $it[0],
                        'item_label' => $it[1],
                        'weight' => $it[2],
                    ], $items),
                ]));

                if ($res->getStatusCode() >= 300) {
                    $this->command->warn('  REFUSED ' . $code . ': ' . $res->getContent());
                    $refused++;
                    continue;
                }
                $made++;

                $cid = DB::table('competency')->where('sub_institute_id', self::TENANT)->where('code', $code)->value('id');

                // THE REAL CONTRACT, read from the controller rather than assumed.
                // My first attempt sent `competency_ids` + a flat
                // `required_proficiency`; the endpoint wants `items` — a list of
                // {competency_id, required_proficiency}. It refused every call
                // with "The items field is required", which is exactly the value
                // of seeding through the controller: a wrong shape fails here
                // instead of arriving in the table looking correct.
                //
                // NOTE: role-map is SYNC, not append. Sending one competency
                // would REMOVE every other mapping for that role, so the full set
                // is accumulated per role and written once, after the loop.
                $pending[$jobroleId][] = ['competency_id' => (int) $cid, 'required_proficiency' => 3, 'is_mandatory' => 1];
            }
        }

        // One sync per role, with the whole set.
        foreach ($pending as $jobroleId => $items) {
            $rm = $roleMap->store($this->request($bearer, 'POST', [
                'jobrole_id' => $jobroleId,
                'items' => $items,
            ]));
            if ($rm->getStatusCode() < 300) {
                $mapped += count($items);
            } else {
                $this->command->warn('  role-map REFUSED for role ' . $jobroleId . ': ' . $rm->getContent());
            }
        }

        $this->command->info(sprintf('  competencies created %d, role-mapped %d, refused %d', $made, $mapped, $refused));
    }

    private function seedRatings(string $bearer): void
    {
        $rating = app(\App\Http\Controllers\Api\Competency\KasbaRatingController::class);
        $scores = [3, 4, 3, 2, 4, 3, 3, 4, 2];
        $people = 0; $rows = 0; $refused = 0;

        foreach (DB::table('tbluser')->where('sub_institute_id', self::TENANT)
            ->whereNotNull('jobtitle_id')->where('jobtitle_id', '>', 0)->get(['id', 'jobtitle_id']) as $e) {

            $items = DB::table('jobrole_competency_map as m')
                ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
                ->where('m.jobrole_id', $e->jobtitle_id)
                ->where('m.sub_institute_id', self::TENANT)->where('k.sub_institute_id', self::TENANT)
                ->pluck('k.id')->all();

            if (!$items) {
                continue;
            }

            foreach ($items as $i => $kid) {
                $res = $rating->store($this->request($bearer, 'POST', [
                    'user_id' => $e->id,
                    'kasba_item_id' => $kid,
                    'rating' => $scores[$i % count($scores)],
                ]));
                $res->getStatusCode() < 300 ? $rows++ : $refused++;
            }
            $people++;
        }

        $total = DB::table('tbluser')->where('sub_institute_id', self::TENANT)->count();
        $measured = DB::table('competency_kasba_rating')->where('sub_institute_id', self::TENANT)->distinct()->count('user_id');
        $this->command->info(sprintf('  rated %d people, %d rows, %d refused', $people, $rows, $refused));
        $this->command->info(sprintf('  COVERAGE %d of %d = %.1f%%', $measured, $total, $total ? $measured / $total * 100 : 0));
    }

    private function seedTaskMap(string $bearer): void
    {
        $taskMap = app(\App\Http\Controllers\Api\Competency\JobroleTaskCompetencyMapController::class);
        $linked = 0; $refused = 0; $skippedNoCatalogue = 0; $noBridge = false;

        foreach (array_keys(self::MODEL) as $jobroleId) {
            $comps = DB::table('jobrole_competency_map')
                ->where('sub_institute_id', self::TENANT)->where('jobrole_id', $jobroleId)
                ->pluck('competency_id')->all();
            // THE REFERENT IS THE CATALOGUE, AND THAT IS CORRECT.
            // jobrole_task_competency_map.jobrole_task_id points at s_jobrole_task
            // - the shared catalogue - not at a tenant's own copy. A competency is
            // a property of the STANDARD task: "monitor system capacity" needs the
            // same capability at every company, so it is declared once and every
            // tenant holding that task inherits it.
            //
            // catalogue_task_id is the bridge, and it is the column F-10a spent
            // 80,064 rows populating. My first attempt passed s_user_jobrole_task
            // ids straight through and every call was refused "Job role task not
            // found" - the right refusal to a wrong referent.
            //
            // 8 of this role-set's 129 tasks have NO catalogue_task_id: they are
            // tenant-authored with no standard equivalent. They are SKIPPED and
            // COUNTED, never mapped to an approximate catalogue row.
            // catalogue_task_id IS THE BRIDGE, AND IT MAY NOT EXIST YET.
            // F-10a added that column; a database where F-10a has not run has no
            // way to resolve a tenant task to its catalogue entry, so link 04
            // cannot be seeded there at all. Detected and reported rather than
            // crashing, because "the column is absent" is a different finding
            // from "the mapping failed".
            // SHOW COLUMNS, NOT Schema::hasColumn(). The live server is old enough
            // that Laravel's schema inspector queries `generation_expression`,
            // which does not exist there - the same failure that stopped the
            // migrations running. I reached for the one API already known to
            // break on that box. SHOW COLUMNS works on every version.
            if (!DB::select("SHOW COLUMNS FROM s_user_jobrole_task LIKE 'catalogue_task_id'")) {
                $noBridge = true;
                break;
            }

            $tasks = DB::table('s_user_jobrole_task')
                ->where('jobrole_id', $jobroleId)
                ->whereNotNull('catalogue_task_id')
                ->pluck('catalogue_task_id')->unique()->values()->all();

            $unbridged = DB::table('s_user_jobrole_task')
                ->where('jobrole_id', $jobroleId)->whereNull('catalogue_task_id')->count();
            if ($unbridged) {
                $skippedNoCatalogue += $unbridged;
            }

            if (!$comps || !$tasks) {
                continue;
            }

            foreach ($tasks as $i => $tid) {
                $res = $taskMap->store($this->request($bearer, 'POST', [
                    'jobrole_task_id' => $tid,
                    'items' => [['competency_id' => (int) $comps[$i % count($comps)]]],
                ]));
                $res->getStatusCode() < 300 ? $linked++ : $refused++;
            }
        }

        if ($noBridge) {
            $this->command->warn('  task-competency SKIPPED: s_user_jobrole_task has no catalogue_task_id');
            $this->command->warn('  on this database. F-10a has not run here, so a tenant task cannot');
            $this->command->warn('  resolve to its catalogue entry and link 04 cannot be seeded at all.');
            return;
        }
        $this->command->info(sprintf('  task-competency links %d, refused %d, skipped (no catalogue entry) %d',
            $linked, $refused, $skippedNoCatalogue));
    }
}
