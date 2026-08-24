<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ADOPT — the customer takes catalogue content, instead of being given it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS REPLACES.
 *
 *   Signup used to copy the shared catalogue into every new organisation. Live
 *   measured 98.3% and 99.1% of all rows written at signup for the two real
 *   customers - tenant 14 received 276 job roles, 5,876 tasks, 482 skills and
 *   98 departments for ONE employee, none of it asked for, every role name
 *   colliding with another organisation's.
 *
 *   That copy is gone (SchoolSetupController). This is the replacement, and the
 *   difference is consent: SeedLibraryPreviewController reports what the
 *   catalogue holds, this endpoint moves only what the customer names.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ── THREE RULES ─────────────────────────────────────────────────────────────
 *
 * 1. **NO MAPPING FAN-OUT.** Roles and skills only - never the role-skill links,
 *    tasks, or departments derived from them. `competencyLibraryClone` already
 *    states the rule for this codebase: "Associations are deliberately NOT
 *    copied... silently duplicating 50k mapping rows would be a destructive
 *    surprise." Adopting 20 roles must produce 20 rows, not 20 plus 400.
 *
 * 2. **PROVENANCE AT WRITE TIME.** Every adopted row records the catalogue id it
 *    came from. Not backfilled later - the one attempt at name-matched
 *    provenance left 5,470 rows ambiguous, and a guessed source id looks like a
 *    fact. Recorded here, where it is known.
 *
 * 3. **RE-ADOPTION IS A NO-OP, NOT A DUPLICATE.** Provenance is what makes that
 *    possible: a second call naming the same catalogue rows finds them already
 *    adopted and reports them as such. There are no unique constraints on
 *    (sub_institute_id, jobrole) or (sub_institute_id, title), so this is
 *    enforced in application code - the same place `competencyLibraryImport`
 *    enforces it.
 *
 * ── THE PREVIEW AND THE WRITE ARE ONE FUNCTION ──────────────────────────────
 *
 * Modelled on FrameworkImportController, whose comment gives the reason: "TWO
 * PATHS THAT CAN DISAGREE IS THE DEFECT THIS FEATURE EXISTS TO AVOID." A
 * preview that does not predict the write is worse than no preview, because the
 * customer acts on it.
 *
 * ── A NAME COLLISION IS REPORTED, NOT RESOLVED ──────────────────────────────
 *
 * If the tenant already has a role called "Staff Nurse" with no provenance,
 * this does NOT assume it is the catalogue's "Staff Nurse" and it does NOT
 * create a second one. It reports the collision and skips the row. Deciding
 * whether two things with the same name are the same thing is the customer's
 * call, and a name match is a candidate, never a relationship.
 */
class CatalogueAdoptController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * Content columns shared by the catalogue and tenant tables, measured
     * rather than assumed.
     *
     * `id` IS ABSENT ON PURPOSE - it is the SOURCE of the provenance value, and
     * copying it would collide with the tenant table's own sequence. Tenancy
     * and audit columns are set explicitly below, not copied.
     */
    private const ROLE_COLUMNS = [
        'jobrole', 'description', 'jobrole_category', 'performance_expectation',
        'related_jobrole', 'education', 'experience', 'training', 'status',
    ];

    private const SKILL_COLUMNS = [
        'category', 'sub_category', 'micro_category', 'skill_code', 'title',
        'description', 'status', 'related_skills', 'bussiness_links', 'custom_tags',
        'proficiency_level', 'job_titles', 'learning_resources', 'assesment_method',
        'certification_qualifications', 'experience_project', 'skill_maps',
    ];

    /** Reports what adopting would do. Writes nothing. */
    public function preview(Request $request)
    {
        return $this->run($request, false);
    }

    /**
     * Adopts, in one transaction.
     *
     * Not resumable, for the reason FrameworkImportController gives: the preview
     * already buys what resumability would, so a mid-write failure is a system
     * fault rather than a content surprise, and "nothing happened, here is why"
     * is the right answer to a system fault.
     */
    public function adopt(Request $request)
    {
        return $this->run($request, true);
    }

    /**
     * BROWSE — the catalogue, so that a person can choose from it.
     *
     * ── WHY THIS HAD TO BE ADDED ────────────────────────────────────────────
     *
     * preview() and adopt() both take catalogue ids, and until this method there
     * was no way for a client to LEARN one. SeedLibraryPreviewController returns
     * counts and not a single row; the only other reader of `s_jobrole` that a
     * client can reach (`jobrolecontroller::getJobRolesByDepartment`) selects the
     * name and DROPS the id. So adopt was complete on the server and unreachable
     * from the product: you could adopt, but only if you already knew the numbers.
     *
     * ── PAGED AND SEARCHED, NOT DUMPED ──────────────────────────────────────
     *
     * The catalogue is 3,347 job roles and 5,640 skills. A picker that loads all
     * of them is a picker nobody can use, so this pages and searches instead.
     *
     * ── ANNOTATED WITH WHAT ADOPTING WOULD DO ───────────────────────────────
     *
     * Every row carries `already_adopted` and `name_collision`, computed the same
     * way plan() computes them, so the list shows the outcome BEFORE anything is
     * selected. The name check is scoped to the names on THIS PAGE rather than
     * loading the tenant's whole library, so its cost does not grow with the
     * customer.
     *
     * ⚠ DELIBERATELY CONSISTENT WITH plan(), INCLUDING WHERE plan() IS ARGUABLY
     * WRONG. Neither excludes the tenant's SOFT-DELETED rows, so a role that was
     * adopted and later deleted still reads as `already_adopted` and cannot be
     * re-adopted. That is worth fixing, but it must be fixed in BOTH or in
     * NEITHER: a browse that says "available" while the preview says
     * "ALREADY_ADOPTED" is exactly the two-paths-that-disagree defect this
     * controller exists to avoid. Reported rather than changed here.
     */
    public function browse(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'kind'   => 'required|in:role,skill',
            'q'      => 'sometimes|nullable|string|max:120',
            'limit'  => 'sometimes|integer|min:1|max:200',
            'offset' => 'sometimes|integer|min:0',
        ]);

        $tenant = (int) $identity['sub_institute_id'];
        $kind   = $data['kind'];
        $q      = trim((string) ($data['q'] ?? ''));
        $limit  = (int) ($data['limit'] ?? 50);
        $offset = (int) ($data['offset'] ?? 0);

        [$sourceTable, $targetTable, $nameColumn, $provenance] = $this->tables($kind);

        // No deleted_at on either catalogue table - confirmed on live, and the
        // same reason SeedLibraryPreviewController counts them unfiltered.
        $query = DB::table($sourceTable);

        if ($q !== '') {
            // `%` and `_` are escaped: a customer searching for "50_ Nurse"
            // should not silently match more than they asked for.
            $query->where($nameColumn, 'like', '%' . addcslashes($q, '%_\\') . '%');
        }

        $total = (clone $query)->count();
        $rows  = $query->orderBy($nameColumn)->offset($offset)->limit($limit)->get();

        $ids   = $rows->pluck('id')->map(static fn ($i) => (int) $i)->all();
        $names = $rows->pluck($nameColumn)->map(static fn ($n) => (string) $n)->all();

        $adopted = $ids === [] ? [] : DB::table($targetTable)
            ->where('sub_institute_id', $tenant)
            ->whereIn($provenance, $ids)
            ->pluck('id', $provenance)
            ->all();

        $ownNames = [];
        if ($names !== []) {
            $held = DB::table($targetTable)
                ->where('sub_institute_id', $tenant)
                ->whereIn($nameColumn, $names)
                ->pluck($nameColumn);

            foreach ($held as $name) {
                $ownNames[mb_strtolower(trim((string) $name))] = true;
            }
        }

        $categoryColumn = $kind === 'role' ? 'jobrole_category' : 'category';

        $items = [];
        foreach ($rows as $row) {
            $id   = (int) $row->id;
            $name = (string) ($row->{$nameColumn} ?? '');

            $items[] = [
                'catalogue_id'    => $id,
                'name'            => $name,
                'category'        => $row->{$categoryColumn} ?? null,
                'already_adopted' => isset($adopted[$id]),
                'name_collision'  => isset($ownNames[mb_strtolower(trim($name))]),
            ];
        }

        return response()->json(['status' => 1, 'data' => [
            'kind'   => $kind,
            'total'  => $total,
            'shown'  => count($items),
            'offset' => $offset,
            'items'  => $items,
        ]]);
    }

    /** THE ONE PATH. `$write` is the only difference. */
    private function run(Request $request, bool $write)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'job_role_ids'   => 'sometimes|array|max:5000',
            'job_role_ids.*' => 'integer|min:1',
            'skill_ids'      => 'sometimes|array|max:5000',
            'skill_ids.*'    => 'integer|min:1',
        ]);

        $roleIds  = array_values(array_unique($data['job_role_ids'] ?? []));
        $skillIds = array_values(array_unique($data['skill_ids'] ?? []));

        if ($roleIds === [] && $skillIds === []) {
            return response()->json([
                'status'  => 0,
                'message' => 'Name at least one job role or skill to adopt.',
            ], 422);
        }

        $tenant = (int) $identity['sub_institute_id'];
        $userId = $identity['user_id'] ?? null;

        $roles  = $this->plan('role', $roleIds, $tenant);
        $skills = $this->plan('skill', $skillIds, $tenant);

        $created = ['job_roles' => 0, 'skills' => 0];

        if ($write) {
            DB::transaction(function () use ($roles, $skills, $tenant, $userId, &$created) {
                $created['job_roles'] = $this->write('role', $roles, $tenant, $userId);
                $created['skills']    = $this->write('skill', $skills, $tenant, $userId);
            });
        }

        return response()->json(['status' => 1, 'data' => [
            'written' => $write,
            'created' => $write ? $created : null,
            'would_create' => [
                'job_roles' => $this->countState($roles, 'NEW'),
                'skills'    => $this->countState($skills, 'NEW'),
            ],
            'skipped' => [
                'already_adopted' => [
                    'job_roles' => $this->countState($roles, 'ALREADY_ADOPTED'),
                    'skills'    => $this->countState($skills, 'ALREADY_ADOPTED'),
                ],
                'name_collision' => [
                    'job_roles' => $this->countState($roles, 'NAME_COLLISION'),
                    'skills'    => $this->countState($skills, 'NAME_COLLISION'),
                ],
                'not_in_catalogue' => [
                    'job_roles' => $this->countState($roles, 'NOT_IN_CATALOGUE'),
                    'skills'    => $this->countState($skills, 'NOT_IN_CATALOGUE'),
                ],
            ],
            'job_roles' => $roles,
            'skills'    => $skills,
            'note' => $write
                ? 'Adopted in one transaction. Each row records the catalogue id it came from, '
                  . 'so adopting the same rows again does nothing rather than duplicating them. '
                  . 'Role-skill mappings, tasks and departments were NOT copied.'
                : 'NOTHING HAS BEEN WRITTEN. Rows already adopted are listed so you can see they '
                  . 'will be skipped, and rows whose name already exists in your library are '
                  . 'reported rather than merged - whether two things with the same name are the '
                  . 'same thing is your decision, not ours.',
        ]]);
    }

    /**
     * Works out what would happen to each requested id, WITHOUT writing.
     *
     * The write below consumes this same array, so the preview and the write
     * cannot disagree about which rows are new.
     *
     * @return list<array{catalogue_id:int,name:string,state:string,existing_id:int|null}>
     */
    private function plan(string $kind, array $ids, int $tenant): array
    {
        if ($ids === []) {
            return [];
        }

        [$sourceTable, $targetTable, $nameColumn, $provenance] = $this->tables($kind);

        $sourceRows = DB::table($sourceTable)->whereIn('id', $ids)->get();
        $found = $sourceRows->keyBy('id');

        // Already adopted, keyed by the catalogue id it came from.
        $adopted = DB::table($targetTable)
            ->where('sub_institute_id', $tenant)
            ->whereIn($provenance, $ids)
            ->pluck('id', $provenance)
            ->all();

        // Names the tenant already holds, lower-cased. Used ONLY to report a
        // collision - never to treat a name match as the same row.
        $ownNames = [];
        foreach (DB::table($targetTable)->where('sub_institute_id', $tenant)->pluck($nameColumn) as $name) {
            $ownNames[mb_strtolower(trim((string) $name))] = true;
        }

        $plan = [];

        foreach ($ids as $id) {
            $row = $found->get($id);

            if (!$row) {
                $plan[] = [
                    'catalogue_id' => $id,
                    'name'         => null,
                    'state'        => 'NOT_IN_CATALOGUE',
                    'existing_id'  => null,
                ];
                continue;
            }

            $name = (string) ($row->{$nameColumn} ?? '');

            if (isset($adopted[$id])) {
                $plan[] = [
                    'catalogue_id' => $id,
                    'name'         => $name,
                    'state'        => 'ALREADY_ADOPTED',
                    'existing_id'  => (int) $adopted[$id],
                ];
                continue;
            }

            if (isset($ownNames[mb_strtolower(trim($name))])) {
                $plan[] = [
                    'catalogue_id' => $id,
                    'name'         => $name,
                    'state'        => 'NAME_COLLISION',
                    'existing_id'  => null,
                ];
                continue;
            }

            $plan[] = [
                'catalogue_id' => $id,
                'name'         => $name,
                'state'        => 'NEW',
                'existing_id'  => null,
                // Carried so the write does not re-read the catalogue and risk
                // seeing something different from what the preview reported.
                'source'       => $row,
            ];
        }

        return $plan;
    }

    /** Inserts the NEW rows from a plan. Returns how many landed. */
    private function write(string $kind, array $plan, int $tenant, $userId): int
    {
        [$_source, $targetTable, $_name, $provenance] = $this->tables($kind);
        $columns = $kind === 'role' ? self::ROLE_COLUMNS : self::SKILL_COLUMNS;

        $rows = [];

        foreach ($plan as $entry) {
            if ($entry['state'] !== 'NEW') {
                continue;
            }

            $source = $entry['source'];
            $record = [];

            foreach ($columns as $column) {
                $record[$column] = $source->{$column} ?? null;
            }

            // A catalogue row with no status would arrive invisible. Active is
            // the only sensible reading of "the customer asked for this".
            if (($record['status'] ?? '') === '' || $record['status'] === null) {
                $record['status'] = 'Active';
            }

            $record['sub_institute_id'] = $tenant;
            $record[$provenance]        = $entry['catalogue_id'];
            $record['created_at']       = now();
            $record['updated_at']       = now();

            if ($userId !== null) {
                $record['created_by'] = $userId;
            }

            $rows[] = $record;
        }

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($targetTable)->insert($chunk);
        }

        return count($rows);
    }

    /** [source table, target table, name column, provenance column] */
    private function tables(string $kind): array
    {
        return $kind === 'role'
            ? ['s_jobrole', 's_user_jobrole', 'jobrole', 'catalogue_jobrole_id']
            : ['master_skills', 's_users_skills', 'title', 'catalogue_skill_id'];
    }

    private function countState(array $plan, string $state): int
    {
        return count(array_filter($plan, static fn ($e) => $e['state'] === $state));
    }
}
