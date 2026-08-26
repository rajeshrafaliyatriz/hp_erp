<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Competency "Libraries & Taxonomy" API.
 *
 * One controller behind the eight library tabs the legacy Skill Library screen
 * exposed (Skill, Jobrole, Jobrole Task, Knowledge, Ability, Attitude,
 * Behaviour, Invisible) plus the taxonomy editors and the skill taxonomy tree.
 *
 * Every route is token authenticated and tenant scoped through
 * ResolvesCompetencyContext, and answers the same envelope as the rest of
 * /api/competency/*: {status, message, data[, pagination]}.
 *
 * The legacy screens read these tables through the generic `table_data`
 * endpoint, which returns the whole table with no pagination, no tenant
 * enforcement on some tabs and no write path at all. These endpoints replace
 * that: filtering, sorting and pagination happen in SQL, the tenant comes from
 * the token context (never from a query string), and every tab gets real CRUD.
 */
class LibraryController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * The library resources this controller serves.
     *
     * `title`     - the column holding the row's display name
     * `category`  - the taxonomy's first level (null when the type has none)
     * `sub`       - the taxonomy's second level (null when the type has none)
     * `tenant`    - true (rows belong to one tenant) or 'shared' (rows with a
     *               NULL sub_institute_id are curated platform content that
     *               everyone reads and nobody but the platform edits)
     * `soft`      - false when the table has no deleted_at column
     * `search`    - columns the `search` param matches against
     * `fields`    - writable columns, all optional strings unless listed in `required`
     */
    private const RESOURCES = [
        'skill' => [
            'table'    => 's_users_skills',
            'label'    => 'Skill',
            'title'    => 'title',
            'category' => 'category',
            'sub'      => 'sub_category',
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['title', 'description', 'category', 'sub_category', 'department'],
            'required' => ['title'],
            'fields'   => [
                'title', 'description', 'category', 'sub_category', 'micro_category',
                'department', 'sub_department', 'department_id', 'skill_code',
                'related_skills', 'bussiness_links', 'custom_tags', 'proficiency_level',
                'job_titles', 'learning_resources', 'assesment_method',
                'certification_qualifications', 'experience_project', 'skill_maps',
                'skill_status', 'skill_importance', 'legal_compliance_relevance',
                'sop_practice_link', 'performance_metrics', 'common_errors_tips',
                'sme_contacts', 'sub_skills', 'skill_flow', 'tasklist',
                'competency_type', 'status', 'approve_status',
            ],
        ],
        'jobrole' => [
            'table'    => 's_user_jobrole',
            'label'    => 'Job role',
            'title'    => 'jobrole',
            'category' => 'jobrole_category',
            'sub'      => null,
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['jobrole', 'description', 'department', 'jobrole_category'],
            'required' => ['jobrole'],
            'fields'   => [
                'jobrole', 'description', 'industries', 'department', 'sub_department',
                'department_id', 'job_level', 'sequence_order', 'jobrole_category',
                'performance_expectation', 'status', 'related_jobrole',
                'required_skill_experience', 'location', 'salary_range',
                'company_information', 'responsibilities', 'benefits', 'keyword_tags',
                'education', 'experience', 'training',
            ],
        ],
        'jobrole-task' => [
            'table'    => 's_user_jobrole_task',
            'label'    => 'Job role task',
            'title'    => 'task',
            'category' => 'task_category',
            'sub'      => null,
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['task', 'jobrole', 'critical_work_function', 'track'],
            'required' => ['task'],
            'fields'   => [
                // `jobrole` is the display name; `jobrole_id` is the link.
                //
                // The id was absent from this whitelist, and payload() iterates
                // the whitelist rather than the request - so a client sending
                // jobrole_id got a 200 and no column written, silently. That is
                // why the column is 0 of 91,539 populated on live while the
                // name column carries the whole relation.
                'task', 'jobrole', 'jobrole_id', 'critical_work_function', 'task_type',
                'task_category', 'sector', 'track',
            ],
        ],
        'knowledge' => [
            'table'    => 's_user_knowledge',
            'label'    => 'Knowledge',
            'title'    => 'title',
            'category' => 'category',
            'sub'      => 'sub_category',
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['title', 'description', 'category', 'sub_category'],
            'required' => ['title'],
            'fields'   => [
                'title', 'description', 'category', 'sub_category', 'business_link',
                'assessment_method', 'knowledge_tags', 'key_concepts',
                'theoretical_foundation', 'complexity_level', 'proficiency_expectation',
                'references', 'certification_options', 'compliance_relevance',
            ],
        ],
        'ability' => [
            'table'    => 's_user_ability',
            'label'    => 'Ability',
            'title'    => 'title',
            'category' => 'category',
            'sub'      => 'sub_category',
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['title', 'description', 'category', 'sub_category'],
            'required' => ['title'],
            'fields'   => [
                'title', 'description', 'category', 'sub_category', 'business_link',
                'assessment_method', 'ability_tags', 'cognitive_elements',
                'psychomotor_elements', 'measurement_metrics', 'importance_level',
                'common_challenges', 'improvement_tips',
            ],
        ],
        'attitude' => [
            'table'    => 's_user_attitude',
            'label'    => 'Attitude',
            'title'    => 'title',
            'category' => 'category',
            'sub'      => 'sub_category',
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['title', 'description', 'category', 'sub_category'],
            'required' => ['title'],
            'fields'   => [
                'title', 'description', 'category', 'sub_category', 'business_link',
                'assessment_method', 'attitude_tags', 'development_methods',
                'negative_indicators', 'improvement_strategies', 'cultural_alignment',
            ],
        ],
        'behaviour' => [
            'table'    => 's_user_behaviour',
            'label'    => 'Behaviour',
            'title'    => 'title',
            'category' => 'category',
            'sub'      => 'sub_category',
            'tenant'   => true,
            'soft'     => true,
            'search'   => ['title', 'description', 'category', 'sub_category'],
            'required' => ['title'],
            'fields'   => [
                'title', 'description', 'category', 'sub_category', 'business_link',
                'assessment_method', 'behaviour_tags', 'measurable_indicators',
                'behaviour_alternatives', 'performance_metrics', 'risk_implications',
                'coaching_guidelines',
            ],
        ],
        'invisible' => [
            'table'    => 's_invisible_library',
            'label'    => 'Invisible library entry',
            'title'    => 'title',
            'category' => 'type',
            'sub'      => null,
            // Shared reference content: rows with a NULL sub_institute_id are
            // the curated platform catalogue every tenant reads, rows with an id
            // are that tenant's own additions.
            'tenant'   => 'shared',
            'soft'     => true,
            'search'   => ['title', 'description', 'type', 'tags'],
            'required' => ['title', 'type'],
            'fields'   => [
                'type', 'title', 'description', 'purpose', 'when_to_use', 'benefits',
                'limitations', 'example_use_case', 'tags', 'difficulty_level',
            ],
            // `type` is already the API's transport flag (type=API rides on every
            // request), so the column of the same name is written through an alias.
            // Without it a save would stamp the literal string "API" into it.
            'aliases'  => ['entry_type' => 'type'],
            // Both columns are MySQL ENUMs. Checked here so an unexpected value
            // is a 422 with the allowed list rather than a 500 on "Data truncated".
            'enums'    => [
                'type'             => ['mental models', 'frameworks', 'matrices'],
                'difficulty_level' => ['beginner', 'intermediate', 'advanced'],
            ],
        ],
    ];

    /**
     * Request keys that belong to the Laravel API envelope, never to a row.
     * Any column sharing one of these names must be written through an alias.
     */
    private const RESERVED_PARAMS = [
        'type', 'token', 'sub_institute_id', 'syear', 'financial_year',
        'user_id', 'organization_id', 'org_type', '_method',
    ];

    /** KASA tabs share one shape, so one set of routes serves all four. */
    private const KASA_TYPES = ['knowledge', 'ability', 'attitude', 'behaviour'];

    /**
     * Types whose categories are tenant-owned and therefore editable.
     *
     * `invisible` is excluded: its rows are shared reference content with no
     * sub_institute_id, so renaming a type there would rewrite it for every
     * tenant on the platform.
     */
    private const TAXONOMY_TYPES = [
        'skill', 'jobrole', 'jobrole-task', 'knowledge', 'ability', 'attitude', 'behaviour',
    ];

    /* ================================================================== *
     * Shared helpers
     * ================================================================== */

    /** @return array<string, mixed>|null */
    private function resource(string $type): ?array
    {
        return self::RESOURCES[$type] ?? null;
    }

    /**
     * The resource behind a taxonomy route, or null when the type has no
     * editable taxonomy.
     *
     * @return array<string, mixed>|null
     */
    private function taxonomyResource(string $type): ?array
    {
        if (!in_array($type, self::TAXONOMY_TYPES, true)) {
            return null;
        }

        $resource = $this->resource($type);

        return ($resource && $resource['category']) ? $resource : null;
    }

    private function badRequest(string $message, int $status = 400)
    {
        return response()->json(['status' => 0, 'message' => $message], $status);
    }

    private function ok(string $message, $data = null, ?array $pagination = null)
    {
        $payload = ['status' => 1, 'message' => $message, 'data' => $data];
        if ($pagination !== null) {
            $payload['pagination'] = $pagination;
        }

        return response()->json($payload);
    }

    /**
     * Base query for a resource: tenant scoped and excluding soft-deleted rows.
     *
     * A 'shared' resource also returns the platform's curated rows (NULL owner);
     * pass $ownedOnly to get just the tenant's own, which is what every write
     * path uses so curated content cannot be edited or deleted per tenant.
     */
    private function baseQuery(array $resource, int $sid, bool $ownedOnly = false)
    {
        $query = DB::table($resource['table']);

        if ($resource['tenant'] === 'shared') {
            if ($ownedOnly) {
                $query->where('sub_institute_id', $sid);
            } else {
                $query->where(function ($q) use ($sid) {
                    $q->whereNull('sub_institute_id')->orWhere('sub_institute_id', $sid);
                });
            }
        } elseif ($resource['tenant']) {
            $query->where('sub_institute_id', $sid);
        }

        if ($resource['soft']) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    /** True when a resource keeps platform-owned rows alongside tenant rows. */
    private function isShared(array $resource): bool
    {
        return $resource['tenant'] === 'shared';
    }

    /**
     * A write missed the owned-rows scope. Say which it was - the row does not
     * exist, or it is curated platform content this tenant may only read - so
     * the UI can explain rather than showing a bare "not found".
     */
    private function readOnlyOrMissing(array $resource, int $sid, int $id, string $verb)
    {
        if ($this->isShared($resource) && $this->baseQuery($resource, $sid)->where('id', $id)->exists()) {
            return $this->badRequest(
                'This is a shared ' . strtolower($resource['label']) . ' maintained for every organisation, so it cannot be ' . $verb . ' here. Duplicate it to make your own version.',
                403
            );
        }

        return $this->badRequest($resource['label'] . ' not found.', 404);
    }

    /**
     * Apply the list filters every tab shares: free-text search, category,
     * sub-category and any extra column=value pair the tab declares.
     */
    private function applyFilters($query, array $resource, Request $request): void
    {
        if ($search = $this->activeFilter($request->input('search'))) {
            $columns = $resource['search'];
            $query->where(function ($q) use ($columns, $search) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        if ($resource['category'] && ($category = $this->activeFilter($request->input('category')))) {
            $query->where($resource['category'], $category);
        }

        if ($resource['sub'] && ($sub = $this->activeFilter($request->input('sub_category')))) {
            $query->where($resource['sub'], $sub);
        }
    }

    /**
     * Sort column + direction, restricted to the resource's own columns so the
     * `sort` param can never inject SQL.
     *
     * @return array{0:string, 1:string}
     */
    private function sortFor(array $resource, Request $request): array
    {
        $allowed = array_merge($resource['fields'], ['id', 'created_at', 'updated_at']);
        $sort = (string) $request->input('sort', '');
        $sort = in_array($sort, $allowed, true) ? $sort : $resource['title'];
        $direction = strtolower((string) $request->input('direction')) === 'desc' ? 'desc' : 'asc';

        return [$sort, $direction];
    }

    /** @return array{0:int, 1:int} page, per_page */
    private function paging(Request $request): array
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        return [$page, $perPage];
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'last_page' => (int) max(ceil($total / max($perPage, 1)), 1),
        ];
    }

    /**
     * Pull the writable columns out of the request.
     *
     * Only keys the caller actually sent are returned, so a PUT that carries one
     * field updates one column instead of blanking the rest of the row.
     *
     * @return array<string, mixed>
     */
    private function payload(array $resource, Request $request, int $subInstituteId): array
    {
        $data = [];
        // column => request key it is read from (identity unless aliased).
        $aliases = array_flip($resource['aliases'] ?? []);

        foreach ($resource['fields'] as $field) {
            $key = $aliases[$field] ?? $field;

            // A column named like an envelope param can only be written through
            // an alias - otherwise `type=API` would land in the row.
            if ($key === $field && in_array($field, self::RESERVED_PARAMS, true)) {
                continue;
            }

            if (!$request->has($key)) {
                continue;
            }

            $value = $request->input($key);

            if (is_array($value)) {
                $value = implode(',', array_filter(array_map('strval', $value), fn ($item) => $item !== ''));
            }
            if (is_string($value)) {
                $value = trim($value);
            }

            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

        // ── L-01 / L-02 ─────────────────────────────────────────────────────
        // G-LIB-01: the backend has ACCEPTED `department_id` all along - it is in
        // the fields whitelist above - and the form has never sent it. Measured:
        // 0 occurrences in library-config.ts. So the column exists, the form
        // offers a department by NAME, and the two never meet.
        //
        // RESOLVED HERE, NOT IN THE FORM. The picker is suggested-not-closed
        // (X-03), so a genuinely new department must still be typeable - a form
        // that could only send an id would refuse the one case the picker exists
        // to allow. Resolving at write time is Q-C1's position and the same rule
        // the framework importer uses: names become ids at the moment of writing,
        // where "whose copy does this name mean" has exactly one answer.
        //
        // UNMATCHED IS HELD, NOT GUESSED (F-07b): the name stays in `department`
        // and `department_id` stays NULL. A department nobody has created yet is
        // a legitimate thing to type, and inventing an id for it would be the
        // system manufacturing a claim nobody made.
        //
        // AND AN AMBIGUOUS NAME IS HELD TOO, which is the same rule one step
        // further. This used `->value('id')`, which silently takes the FIRST
        // match - and department names are more ambiguous than role names, not
        // less: 557 ambiguous (tenant, name) groups covering 1,120 rows on
        // live, with "Workplace Safety and Health" appearing four times inside
        // tenant 1 alone. Picking the first of four is a coin toss reported as
        // a fact. Two matches now resolve to NULL, exactly as zero matches do.
        //
        // `whereNull('deleted_at')` for the reason the job-role resolver below
        // gives: a retired department resolving to a live id is worse than NULL.
        if (array_key_exists('department_id', $data) === false
            && in_array('department_id', $resource['fields'], true)
            && !empty($data['department'])) {

            // limit(2) is all it takes to tell "exactly one" from "more than
            // one" - the rest of the matches are not needed to know it is
            // ambiguous.
            $matches = DB::table('hrms_departments')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($data['department']))])
                ->limit(2)
                ->pluck('id');

            $data['department_id'] = $matches->count() === 1 ? (int) $matches->first() : null;
        }

        /*
         * The same rule for a job role task's role.
         *
         * `s_user_jobrole_task.jobrole` is the only link a task has to its
         * role, and it is a NAME - so renaming the role orphaned every one of
         * its tasks with nothing left pointing back. The id column has existed
         * all along and is 0 of 91,539 populated on live.
         *
         * TWO DIFFERENCES FROM THE DEPARTMENT CASE ABOVE, both deliberate:
         *
         *   - `->whereNull('deleted_at')`. s_user_jobrole soft-deletes, and a
         *     retired role resolving to a live id is worse than NULL.
         *   - the name is AMBIGUOUS far more often here. There are 91 (tenant,
         *     role name) groups covering 220 rows - tenant 1 alone has "Vice
         *     President" eleven times - so this can match more than one row.
         *     `value('id')` takes the first, which is a guess.
         *
         * That is why the field is whitelisted as well: the Job Role Task form
         * uses a CLOSED select and already holds the id, so it can send it and
         * skip this entirely. This resolver is the fallback for the writers
         * that only have a name, and it holds rather than guesses when the
         * name is ambiguous.
         */
        if (in_array('jobrole_id', $resource['fields'], true)) {

            if (array_key_exists('jobrole_id', $data) && !empty($data['jobrole_id'])) {
                /*
                 * AN ID SUPPLIED BY THE CALLER IS NOT TRUSTED.
                 *
                 * Whitelisting the column means a request can now name a role
                 * directly, and without this check a tenant-1 caller could
                 * attach their task to tenant 2's role simply by sending its
                 * id - the exact cross-tenant shape this work exists to close.
                 * Verified: it did, before this guard.
                 *
                 * A foreign or retired id is dropped to NULL rather than
                 * refused, so the task is still created and still carries its
                 * name. Held, not guessed - and never someone else's.
                 */
                $ownRole = DB::table('s_user_jobrole')
                    ->where('id', (int) $data['jobrole_id'])
                    ->where('sub_institute_id', $subInstituteId)
                    ->whereNull('deleted_at')
                    ->exists();

                $data['jobrole_id'] = $ownRole ? (int) $data['jobrole_id'] : null;

            } elseif (!empty($data['jobrole'])) {
                $matches = DB::table('s_user_jobrole')
                    ->where('sub_institute_id', $subInstituteId)
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(TRIM(jobrole)) = ?', [mb_strtolower(trim($data['jobrole']))])
                    ->limit(2)
                    ->pluck('id');

                // Exactly one match is a resolution. Two is a coin toss, and a
                // wrong id is worse than none - the name stays and the id is
                // NULL, the same rule the department resolver above follows.
                $data['jobrole_id'] = $matches->count() === 1 ? (int) $matches->first() : null;
            }
        }

        return $data;
    }

    /**
     * Reject a create/update whose required fields are missing or blank.
     * On create the required fields must be present; on update they may be
     * absent (untouched) but must not be blanked out.
     */
    private function validatePayload(array $resource, array $data, bool $creating): ?string
    {
        $aliases = array_flip($resource['aliases'] ?? []);

        foreach ($resource['required'] as $field) {
            // Report the name the caller actually sends, not the column name.
            $label = ucfirst(str_replace('_', ' ', $aliases[$field] ?? $field));

            if ($creating && !array_key_exists($field, $data)) {
                return $label . ' is required.';
            }
            if (array_key_exists($field, $data) && ($data[$field] === null || $data[$field] === '')) {
                return $label . ' cannot be empty.';
            }
        }

        foreach ($resource['enums'] ?? [] as $column => $allowed) {
            if (!array_key_exists($column, $data) || $data[$column] === null) {
                continue;
            }
            if (!in_array($data[$column], $allowed, true)) {
                $label = ucfirst(str_replace('_', ' ', $aliases[$column] ?? $column));

                return $label . ' must be one of: ' . implode(', ', $allowed) . '.';
            }
        }

        return null;
    }

    /**
     * Guard against a second row with the same name inside the same taxonomy
     * bucket. `$ignoreId` skips the row being edited.
     */
    private function duplicateExists(array $resource, int $sid, array $data, ?int $ignoreId = null): bool
    {
        $titleColumn = $resource['title'];
        if (!isset($data[$titleColumn])) {
            return false;
        }

        $query = $this->baseQuery($resource, $sid)->where($titleColumn, $data[$titleColumn]);

        // Same title under a different category is a different library entry.
        if ($resource['category'] && array_key_exists($resource['category'], $data)) {
            $query->where($resource['category'], $data[$resource['category']]);
        }
        if ($resource['sub'] && array_key_exists($resource['sub'], $data)) {
            $query->where($resource['sub'], $data[$resource['sub']]);
        }
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }

    /** Stamp the audit columns every library table carries. */
    private function stamp(array $data, ?int $userId, bool $creating): array
    {
        if ($creating) {
            $data['created_by'] = $userId;
            $data['created_at'] = now();
        } else {
            $data['updated_by'] = $userId;
            $data['updated_at'] = now();
        }

        return $data;
    }

    /* ================================================================== *
     * Generic list / show / store / update / destroy
     * ================================================================== */

    private function listResource(string $type, Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->resource($type);
        if (!$resource) {
            return $this->badRequest('Unknown library type.', 404);
        }

        $query = $this->baseQuery($resource, $context['sub_institute_id']);

        // Taxonomy placeholders are rows that carry only a category, so that an
        // empty branch can exist before its first entry. They are not library
        // entries and must never surface in a list.
        $query->whereNotNull($resource['title'])->where($resource['title'], '!=', '');

        $this->applyFilters($query, $resource, $request);

        // Tab-specific extras.
        if ($type === 'skill') {
            if ($department = $this->activeFilter($request->input('department'))) {
                $query->where('department', $department);
            }
            if ($proficiency = $this->activeFilter($request->input('proficiency_level'))) {
                $query->where('proficiency_level', $proficiency);
            }
            if ($status = $this->activeFilter($request->input('approve_status'))) {
                $query->where('approve_status', $status);
            }
            if ($jobrole = $this->activeFilter($request->input('jobrole'))) {
                // Skills mapped to a job role live in s_user_skill_jobrole by name.
                $query->whereIn('title', function ($sub) use ($jobrole, $context) {
                    $sub->from('s_user_skill_jobrole')
                        ->select('skill')
                        ->where('jobrole', $jobrole)
                        ->where('sub_institute_id', $context['sub_institute_id'])
                        ->whereNull('deleted_at');
                });
            }
        }

        if ($type === 'jobrole') {
            if ($department = $this->activeFilter($request->input('department'))) {
                $query->where('department', $department);
            }
            if ($status = $this->activeFilter($request->input('status'))) {
                $query->where('status', $status);
            }
        }

        if ($type === 'jobrole-task') {
            if ($jobrole = $this->activeFilter($request->input('jobrole'))) {
                $query->where('jobrole', $jobrole);
            }
            if ($cwf = $this->activeFilter($request->input('critical_work_function'))) {
                $query->where('critical_work_function', $cwf);
            }
            if ($taskType = $this->activeFilter($request->input('task_type'))) {
                $query->where('task_type', $taskType);
            }
        }

        if ($type === 'invisible' && ($difficulty = $this->activeFilter($request->input('difficulty_level')))) {
            $query->where('difficulty_level', $difficulty);
        }

        $total = (clone $query)->count();
        [$page, $perPage] = $this->paging($request);
        [$sort, $direction] = $this->sortFor($resource, $request);

        $rows = $query
            ->orderBy($sort, $direction)
            // Stable secondary key so rows never shuffle between pages when the
            // sort column repeats (every task in a work function, say).
            ->orderBy('id', 'asc')
            ->forPage($page, $perPage)
            ->get();

        return $this->ok('Library fetched successfully', $rows, $this->pagination($page, $perPage, $total));
    }

    private function storeResource(string $type, Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->resource($type);
        if (!$resource) {
            return $this->badRequest('Unknown library type.', 404);
        }

        $data = $this->payload($resource, $request, (int) $context['sub_institute_id']);

        if ($message = $this->validatePayload($resource, $data, true)) {
            return $this->badRequest($message, 422);
        }
        if ($this->duplicateExists($resource, $context['sub_institute_id'], $data)) {
            return $this->badRequest($resource['label'] . ' "' . $data[$resource['title']] . '" already exists in this category.', 422);
        }

        if ($resource['tenant']) {
            $data['sub_institute_id'] = $context['sub_institute_id'];
        }
        $data = $this->stamp($data, $context['user_id'], true);

        $id = DB::table($resource['table'])->insertGetId($data);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'created_library_item',
            'Added ' . strtolower($resource['label']) . ' "' . $data[$resource['title']] . '" to the library',
            $type,
            (int) $id,
            (string) $data[$resource['title']]
        );

        // Counts and dropdown options are cached; a write makes them stale.
        $this->forgetLibraryMeta((int) $context['sub_institute_id']);

        return $this->ok($resource['label'] . ' created successfully', ['id' => (int) $id]);
    }

    private function showResource(string $type, int $id, Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->resource($type);
        if (!$resource) {
            return $this->badRequest('Unknown library type.', 404);
        }

        $row = $this->baseQuery($resource, $context['sub_institute_id'])->where('id', $id)->first();
        if (!$row) {
            return $this->badRequest($resource['label'] . ' not found.', 404);
        }

        $data = ['record' => $row];

        if ($type === 'skill') {
            $data += $this->skillAssociations($row, $context['sub_institute_id']);
        }
        if ($type === 'jobrole') {
            $data += $this->jobroleAssociations($row, $context['sub_institute_id']);
        }

        return $this->ok($resource['label'] . ' fetched successfully', $data);
    }

    private function updateResource(string $type, int $id, Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->resource($type);
        if (!$resource) {
            return $this->badRequest('Unknown library type.', 404);
        }

        $existing = $this->baseQuery($resource, $context['sub_institute_id'], true)->where('id', $id)->first();
        if (!$existing) {
            return $this->readOnlyOrMissing($resource, $context['sub_institute_id'], $id, 'edited');
        }

        $data = $this->payload($resource, $request, (int) $context['sub_institute_id']);

        if ($message = $this->validatePayload($resource, $data, false)) {
            return $this->badRequest($message, 422);
        }
        if ($data === []) {
            return $this->badRequest('Nothing to update.', 422);
        }
        if ($this->duplicateExists($resource, $context['sub_institute_id'], $data, $id)) {
            return $this->badRequest('Another ' . strtolower($resource['label']) . ' with that name already exists in this category.', 422);
        }

        $labels = [];
        foreach ($resource['fields'] as $field) {
            $labels[$field] = ucwords(str_replace('_', ' ', $field));
        }
        $changes = $this->diffChanges($existing, $data, $labels);

        $stamped = $this->stamp($data, $context['user_id'], false);
        DB::table($resource['table'])->where('id', $id)->update($stamped);

        /*
         * A RENAMED JOB ROLE MUST TAKE ITS LABELS WITH IT.
         *
         * A dozen tables store the role's NAME beside (or instead of) its id.
         * Until now, renaming a role left every one of those rows holding the
         * OLD text - still displayed, still filtered on, and matching nothing.
         * That is one of the ways this data got into its current state.
         *
         * Scoped on jobrole_id ONLY, never on the old name: 90 role names on
         * live belong to roles in more than one department, and a name-scoped
         * update would rewrite the namesake's rows too.
         */
        if ($type === 'jobrole' && isset($data['jobrole'])) {
            $this->propagateJobroleRename(
                (int) $id,
                (string) ($existing->jobrole ?? ''),
                (string) $data['jobrole'],
                (int) $context['sub_institute_id']
            );
        }

        $name = $data[$resource['title']] ?? $existing->{$resource['title']};

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'updated_library_item',
            'Updated ' . strtolower($resource['label']) . ' "' . $name . '"',
            $type,
            $id,
            (string) $name,
            $changes
        );

        // Counts and dropdown options are cached; a write makes them stale.
        $this->forgetLibraryMeta((int) $context['sub_institute_id']);

        return $this->ok($resource['label'] . ' updated successfully', ['id' => $id]);
    }

    /**
     * Carry a job role's new name onto every row keyed to it.
     *
     * Only rows whose `jobrole_id` IS this role are touched. Rows that still
     * carry the name and no id are LEFT ALONE - they may belong to a namesake
     * in another department, and `jobroles:backfill-ids` reports them rather
     * than letting anything guess.
     *
     * The table list is JobRoleMergeService::ROLE_NAME_TABLES, reused rather
     * than copied: a rename and a merge have to agree about which columns hold
     * a role's label, and two lists would drift.
     */
    private function propagateJobroleRename(int $roleId, string $oldName, string $newName, int $subInstituteId): void
    {
        if (trim($oldName) === trim($newName) || trim($newName) === '') {
            return;
        }

        foreach (\App\Services\Org\JobRoleMergeService::ROLE_NAME_TABLES as $table => [$column, $label]) {
            $idColumn = $table === 's_mobility_succession_plans' ? 'critical_jobrole_id' : 'jobrole_id';

            try {
                if (!$this->hasJobroleColumn($table, $column) || !$this->hasJobroleColumn($table, $idColumn)) {
                    continue;
                }

                DB::table($table)
                    ->where('sub_institute_id', $subInstituteId)
                    ->where($idColumn, $roleId)
                    ->update([$column => $newName]);
            } catch (\Throwable $e) {
                // A label that failed to follow is not a reason to fail the
                // rename itself - the role IS renamed, and the id link that
                // actually matters is untouched.
                \Log::warning('Job role renamed but a label did not follow', [
                    'role' => $roleId, 'table' => $table, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** information_schema directly - live is MariaDB 10.1. */
    private function hasJobroleColumn(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function destroyResource(string $type, int $id, Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->resource($type);
        if (!$resource) {
            return $this->badRequest('Unknown library type.', 404);
        }

        $existing = $this->baseQuery($resource, $context['sub_institute_id'], true)->where('id', $id)->first();
        if (!$existing) {
            return $this->readOnlyOrMissing($resource, $context['sub_institute_id'], $id, 'deleted');
        }

        if ($resource['soft']) {
            DB::table($resource['table'])->where('id', $id)->update([
                'deleted_by' => $context['user_id'],
                'deleted_at' => now(),
            ]);
        } else {
            DB::table($resource['table'])->where('id', $id)->delete();
        }

        $name = (string) $existing->{$resource['title']};

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'deleted_library_item',
            'Removed ' . strtolower($resource['label']) . ' "' . $name . '" from the library',
            $type,
            $id,
            $name
        );

        // Counts and dropdown options are cached; a write makes them stale.
        $this->forgetLibraryMeta((int) $context['sub_institute_id']);

        return $this->ok($resource['label'] . ' deleted successfully', ['id' => $id]);
    }

    /* ================================================================== *
     * Detail panels
     * ================================================================== */

    /**
     * What a skill is wired to: job roles, proficiency levels, its KASA
     * classification items and its applications.
     *
     * @return array<string, mixed>
     */
    private function skillAssociations($skill, int $sid): array
    {
        $jobroles = DB::table('s_user_skill_jobrole')
            ->where('skill', $skill->title)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('jobrole')
            ->get(['id', 'jobrole', 'proficiency_level', 'proficiency_description', 'track', 'sector']);

        $proficiency = DB::table('s_proficiency_levels')
            ->where('skill_id', $skill->id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('proficiency_level')
            ->get(['id', 'proficiency_level', 'description', 'proficiency_type', 'type_description']);

        $classification = DB::table('s_skill_knowledge_ability')
            ->where('skill_id', $skill->id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('proficiency_level')
            ->get(['id', 'classification', 'classification_category', 'classification_sub_category', 'classification_item', 'proficiency_level']);

        $applications = DB::table('s_user_skill_application')
            ->where('skill_id', $skill->id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('proficiency_level')
            ->get(['id', 'application', 'proficiency_level']);

        // Group the KASA rows the way the detail panel renders them.
        $grouped = ['knowledge' => [], 'ability' => [], 'attitude' => [], 'behaviour' => []];
        foreach ($classification as $row) {
            $key = strtolower((string) $row->classification);
            if (array_key_exists($key, $grouped)) {
                $grouped[$key][] = $row;
            }
        }

        return [
            'jobroles'       => $jobroles,
            'proficiency'    => $proficiency,
            'classification' => $grouped,
            'applications'   => $applications,
        ];
    }

    /**
     * What a job role is made of: its tasks grouped by critical work function,
     * and the skills mapped to it.
     *
     * @return array<string, mixed>
     */
    private function jobroleAssociations($jobrole, int $sid): array
    {
        $tasks = DB::table('s_user_jobrole_task')
            ->where('jobrole', $jobrole->jobrole)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('critical_work_function')
            ->orderBy('task')
            ->get(['id', 'critical_work_function', 'task', 'task_type', 'task_category']);

        $skills = DB::table('s_user_skill_jobrole')
            ->where('jobrole', $jobrole->jobrole)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('skill')
            ->get(['id', 'skill', 'proficiency_level', 'proficiency_description', 'type']);

        return [
            'tasks'  => $tasks,
            'skills' => $skills,
            'kasa'   => $this->libraryMapAttributes('jobrole', (int) $jobrole->id, $sid),
        ];
    }

    /**
     * The knowledge / ability / attitude / behaviour a job role is mapped to.
     *
     * s_library_map stores those as comma-separated id lists per subject, which
     * is what the legacy job description screen read. Resolved here so the role
     * detail panel shows the same picture without a second round trip.
     *
     * @return array<string, array<int, object>>
     */
    private function libraryMapAttributes(string $subjectType, int $subjectId, int $sid): array
    {
        $empty = ['knowledge' => [], 'ability' => [], 'attitude' => [], 'behaviour' => []];

        $map = DB::table('s_library_map')
            ->where('type', $subjectType)
            ->where('type_id', $subjectId)
            ->where('sub_institute_id', $sid)
            ->first();

        if (!$map) {
            return $empty;
        }

        foreach (array_keys($empty) as $kind) {
            $column = $kind . '_ids';
            $ids = array_values(array_filter(
                array_map('intval', explode(',', (string) ($map->{$column} ?? ''))),
                fn ($id) => $id > 0
            ));

            if ($ids === []) {
                continue;
            }

            $resource = $this->resource($kind);
            $empty[$kind] = $this->baseQuery($resource, $sid)
                ->whereIn('id', $ids)
                ->orderBy('title')
                ->get(['id', 'title', 'category', 'sub_category', 'description'])
                ->all();
        }

        return $empty;
    }

    /* ================================================================== *
     * Public routes - Skills
     * ================================================================== */

    public function skills(Request $request)
    {
        return $this->listResource('skill', $request);
    }

    public function storeSkill(Request $request)
    {
        return $this->storeResource('skill', $request);
    }

    public function showSkill(Request $request, $id)
    {
        return $this->showResource('skill', (int) $id, $request);
    }

    public function updateSkill(Request $request, $id)
    {
        return $this->updateResource('skill', (int) $id, $request);
    }

    public function destroySkill(Request $request, $id)
    {
        return $this->destroyResource('skill', (int) $id, $request);
    }

    /* ---------------------------- Job roles --------------------------- */

    public function jobroles(Request $request)
    {
        return $this->listResource('jobrole', $request);
    }

    public function storeJobrole(Request $request)
    {
        return $this->storeResource('jobrole', $request);
    }

    public function showJobrole(Request $request, $id)
    {
        return $this->showResource('jobrole', (int) $id, $request);
    }

    public function updateJobrole(Request $request, $id)
    {
        return $this->updateResource('jobrole', (int) $id, $request);
    }

    public function destroyJobrole(Request $request, $id)
    {
        return $this->destroyResource('jobrole', (int) $id, $request);
    }

    /* -------------------------- Job role tasks ------------------------ */

    public function jobroleTasks(Request $request)
    {
        return $this->listResource('jobrole-task', $request);
    }

    public function storeJobroleTask(Request $request)
    {
        return $this->storeResource('jobrole-task', $request);
    }

    public function showJobroleTask(Request $request, $id)
    {
        return $this->showResource('jobrole-task', (int) $id, $request);
    }

    public function updateJobroleTask(Request $request, $id)
    {
        return $this->updateResource('jobrole-task', (int) $id, $request);
    }

    public function destroyJobroleTask(Request $request, $id)
    {
        return $this->destroyResource('jobrole-task', (int) $id, $request);
    }

    /* ------------------------------- KASA ----------------------------- */

    private function kasaType(string $type): ?string
    {
        $type = strtolower(trim($type));
        // The legacy screens spell it both ways.
        if ($type === 'behavior') {
            $type = 'behaviour';
        }

        return in_array($type, self::KASA_TYPES, true) ? $type : null;
    }

    public function kasa(Request $request, $type)
    {
        $resolved = $this->kasaType($type);

        return $resolved
            ? $this->listResource($resolved, $request)
            : $this->badRequest('Unknown attribute type. Expected knowledge, ability, attitude or behaviour.', 404);
    }

    public function storeKasa(Request $request, $type)
    {
        $resolved = $this->kasaType($type);

        return $resolved
            ? $this->storeResource($resolved, $request)
            : $this->badRequest('Unknown attribute type.', 404);
    }

    public function showKasa(Request $request, $type, $id)
    {
        $resolved = $this->kasaType($type);

        return $resolved
            ? $this->showResource($resolved, (int) $id, $request)
            : $this->badRequest('Unknown attribute type.', 404);
    }

    /**
     * Where a knowledge / ability / attitude / behaviour item is actually used.
     *
     * The previous app's detail popup declared state for exactly this - which
     * skills reference the item, at which proficiency levels, and which job
     * roles inherit it - and then never fetched any of it, so those panels were
     * permanently empty. The data exists: s_skill_knowledge_ability joins a
     * classification item back to the skills that list it.
     *
     * Matching is by `classification_item` against the item's title, because
     * that join carries the text rather than a foreign key.
     */
    public function usageKasa(Request $request, $type, $id)
    {
        $resolved = $this->kasaType($type);

        if (!$resolved) {
            return $this->badRequest('Unknown attribute type.', 404);
        }

        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $config = self::RESOURCES[$resolved];

        $item = DB::table($config['table'])
            ->where('id', (int) $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$item) {
            return $this->badRequest('Not found.', 404);
        }

        $title = trim((string) ($item->{$config['title']} ?? ''));

        // A blank title cannot be matched against, and matching on '' would
        // return every unclassified row in the tenant.
        if ($title === '') {
            return $this->ok('Usage', [
                'item'         => $item,
                'skills'       => [],
                'jobroles'     => [],
                'levels'       => [],
                'skill_count'  => 0,
            ]);
        }

        $links = DB::table('s_skill_knowledge_ability as ska')
            ->join('s_users_skills as s', 's.id', '=', 'ska.skill_id')
            ->where('ska.classification', $resolved)
            ->where('ska.classification_item', $title)
            ->where('ska.sub_institute_id', $sid)
            ->whereNull('ska.deleted_at')
            ->whereNull('s.deleted_at')
            ->orderBy('s.title')
            ->get([
                'ska.id',
                'ska.skill_id',
                'ska.proficiency_level',
                'ska.classification_category',
                'ska.classification_sub_category',
                's.title as skill_title',
                's.category as skill_category',
                's.sub_category as skill_sub_category',
                's.department as skill_department',
            ]);

        // Job roles reached through those skills - the item's real blast radius.
        $skillTitles = $links->pluck('skill_title')->filter()->unique()->values()->all();

        $jobroles = $skillTitles === [] ? collect() : DB::table('s_user_skill_jobrole')
            ->whereIn('skill', $skillTitles)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('jobrole')
            ->get(['id', 'jobrole', 'skill', 'proficiency_level', 'proficiency_description', 'track', 'sector']);

        return $this->ok('Usage', [
            'item'        => $item,
            'skills'      => $links,
            'jobroles'    => $jobroles,
            'levels'      => $links->pluck('proficiency_level')->filter()->unique()->sort()->values(),
            'skill_count' => $links->count(),
        ]);
    }

    public function updateKasa(Request $request, $type, $id)
    {
        $resolved = $this->kasaType($type);

        return $resolved
            ? $this->updateResource($resolved, (int) $id, $request)
            : $this->badRequest('Unknown attribute type.', 404);
    }

    public function destroyKasa(Request $request, $type, $id)
    {
        $resolved = $this->kasaType($type);

        return $resolved
            ? $this->destroyResource($resolved, (int) $id, $request)
            : $this->badRequest('Unknown attribute type.', 404);
    }

    /* ---------------------------- Invisible --------------------------- */

    public function invisible(Request $request)
    {
        return $this->listResource('invisible', $request);
    }

    public function storeInvisible(Request $request)
    {
        return $this->storeResource('invisible', $request);
    }

    public function showInvisible(Request $request, $id)
    {
        return $this->showResource('invisible', (int) $id, $request);
    }

    public function updateInvisible(Request $request, $id)
    {
        return $this->updateResource('invisible', (int) $id, $request);
    }

    public function destroyInvisible(Request $request, $id)
    {
        return $this->destroyResource('invisible', (int) $id, $request);
    }

    /**
     * POST /api/competency/library/invisible/{id}/clone
     *
     * Copies a shared (platform-owned) entry into this tenant so it can be
     * edited. Without this, "cannot be edited here" would be a dead end.
     */
    public function cloneInvisible(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->resource('invisible');
        $sid = $context['sub_institute_id'];

        $source = $this->baseQuery($resource, $sid)->where('id', (int) $id)->first();
        if (!$source) {
            return $this->badRequest($resource['label'] . ' not found.', 404);
        }

        $name = trim((string) $request->input('title', '')) ?: $source->title . ' (Copy)';

        if ($this->baseQuery($resource, $sid)->where('title', $name)->exists()) {
            return $this->badRequest('An entry called "' . $name . '" already exists.', 422);
        }

        $row = [];
        foreach ($resource['fields'] as $field) {
            $row[$field] = $source->{$field} ?? null;
        }
        $row['title'] = $name;
        $row['sub_institute_id'] = $sid;
        $row = $this->stamp($row, $context['user_id'], true);

        $newId = DB::table($resource['table'])->insertGetId($row);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'cloned_library_item',
            'Copied invisible library entry "' . $source->title . '" as "' . $name . '"',
            'invisible',
            (int) $newId,
            $name
        );

        return $this->ok('Entry copied successfully', ['id' => (int) $newId, 'title' => $name]);
    }

    /* ================================================================== *
     * Taxonomy (categories and sub-categories)
     * ================================================================== */

    /**
     * GET /api/competency/library/taxonomy/{type}
     *
     * The category tree for a library type, with the row count behind each node
     * so the editor can warn before a rename touches thousands of records.
     */
    public function taxonomy(Request $request, $type)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->taxonomyResource($type);
        if (!$resource) {
            return $this->badRequest('That library type has no editable taxonomy.', 404);
        }

        $categoryColumn = $resource['category'];
        $subColumn = $resource['sub'];
        $titleColumn = $resource['title'];

        $select = [DB::raw($categoryColumn . ' as category')];
        $groupBy = [$categoryColumn];
        if ($subColumn) {
            $select[] = DB::raw($subColumn . ' as sub_category');
            $groupBy[] = $subColumn;
        }
        // Placeholder rows (category only, no name) keep an empty branch alive
        // but are not entries, so they must not be counted as ones.
        $select[] = DB::raw("SUM(CASE WHEN {$titleColumn} IS NULL OR {$titleColumn} = '' THEN 0 ELSE 1 END) as total");

        $rows = $this->baseQuery($resource, $context['sub_institute_id'])
            ->whereNotNull($categoryColumn)
            ->where($categoryColumn, '!=', '')
            ->groupBy($groupBy)
            ->orderBy($categoryColumn)
            ->get($select);

        $tree = [];
        foreach ($rows as $row) {
            $category = (string) $row->category;
            if (!isset($tree[$category])) {
                $tree[$category] = ['category' => $category, 'total' => 0, 'sub_categories' => []];
            }

            $tree[$category]['total'] += (int) $row->total;

            $sub = $subColumn ? trim((string) ($row->sub_category ?? '')) : '';
            if ($sub !== '') {
                $tree[$category]['sub_categories'][] = ['name' => $sub, 'total' => (int) $row->total];
            }
        }

        return $this->ok('Taxonomy fetched successfully', [
            'type'          => $type,
            'has_sub_level' => (bool) $subColumn,
            'categories'    => array_values($tree),
        ]);
    }

    /**
     * POST /api/competency/library/taxonomy/{type}
     *
     * Creates a category, or a sub-category inside one.
     *
     * A taxonomy node only exists as a value on library rows, so a brand new
     * empty node is stored as a placeholder row with no title. The list
     * endpoints skip those (they have no name), while the taxonomy tree still
     * shows the node so it can be picked when creating the first real entry.
     */
    public function storeTaxonomy(Request $request, $type)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->taxonomyResource($type);
        if (!$resource) {
            return $this->badRequest('That library type has no editable taxonomy.', 404);
        }

        $validator = Validator::make($request->all(), [
            'category'     => 'required|string|max:191',
            'sub_category' => 'nullable|string|max:191',
        ]);
        if ($validator->fails()) {
            return $this->badRequest($validator->errors()->first(), 422);
        }

        $category = trim((string) $request->input('category'));
        $sub = trim((string) $request->input('sub_category', ''));

        if ($sub !== '' && !$resource['sub']) {
            return $this->badRequest('That library type has no sub-categories.', 422);
        }

        $exists = $this->baseQuery($resource, $context['sub_institute_id'])
            ->where($resource['category'], $category)
            ->when($sub !== '', fn ($q) => $q->where($resource['sub'], $sub))
            ->exists();

        if ($exists) {
            return $this->badRequest($sub !== '' ? 'That sub-category already exists.' : 'That category already exists.', 422);
        }

        // Empty string, not null: s_users_skills.title is NOT NULL with no default.
        $row = [$resource['category'] => $category, $resource['title'] => ''];
        if ($sub !== '') {
            $row[$resource['sub']] = $sub;
        }
        if ($resource['tenant']) {
            $row['sub_institute_id'] = $context['sub_institute_id'];
        }
        $row = $this->stamp($row, $context['user_id'], true);

        DB::table($resource['table'])->insert($row);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'created_taxonomy_node',
            'Added ' . strtolower($resource['label']) . ' taxonomy ' . ($sub !== '' ? 'sub-category "' . $sub . '" under "' . $category . '"' : 'category "' . $category . '"'),
            $type . '-taxonomy',
            null,
            $sub !== '' ? $sub : $category
        );

        return $this->ok($sub !== '' ? 'Sub-category added successfully' : 'Category added successfully');
    }

    /**
     * PUT /api/competency/library/taxonomy/{type}
     *
     * Renames a category (every row under it) or a sub-category (every row
     * under that category + sub-category pair).
     */
    public function updateTaxonomy(Request $request, $type)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->taxonomyResource($type);
        if (!$resource) {
            return $this->badRequest('That library type has no editable taxonomy.', 404);
        }

        $validator = Validator::make($request->all(), [
            'category'         => 'required|string|max:191',
            'old_category'     => 'required|string|max:191',
            'sub_category'     => 'nullable|string|max:191',
            'old_sub_category' => 'nullable|string|max:191',
        ]);
        if ($validator->fails()) {
            return $this->badRequest($validator->errors()->first(), 422);
        }

        $sid = $context['sub_institute_id'];
        $category = trim((string) $request->input('category'));
        $oldCategory = trim((string) $request->input('old_category'));
        $sub = trim((string) $request->input('sub_category', ''));
        $oldSub = trim((string) $request->input('old_sub_category', ''));

        $renamingSub = $oldSub !== '';

        if ($renamingSub && !$resource['sub']) {
            return $this->badRequest('That library type has no sub-categories.', 422);
        }
        if ($renamingSub && $sub === '') {
            return $this->badRequest('Sub-category name cannot be empty.', 422);
        }

        $query = $this->baseQuery($resource, $sid)->where($resource['category'], $oldCategory);
        if ($renamingSub) {
            $query->where($resource['sub'], $oldSub);
        }

        $affected = (clone $query)->count();
        if ($affected === 0) {
            return $this->badRequest('That taxonomy entry no longer exists.', 404);
        }

        // Renaming onto an existing sibling would silently merge two branches.
        $clash = $this->baseQuery($resource, $sid)
            ->where($resource['category'], $category)
            ->when($renamingSub, fn ($q) => $q->where($resource['sub'], $sub))
            ->when(!$renamingSub, fn ($q) => $q->where($resource['category'], '!=', $oldCategory))
            ->when($renamingSub, fn ($q) => $q->where(function ($inner) use ($resource, $oldCategory, $oldSub) {
                $inner->where($resource['category'], '!=', $oldCategory)
                    ->orWhere($resource['sub'], '!=', $oldSub);
            }))
            ->exists();

        if ($clash) {
            return $this->badRequest($renamingSub ? 'A sub-category with that name already exists here.' : 'A category with that name already exists.', 422);
        }

        $update = [$resource['category'] => $category];
        if ($renamingSub) {
            $update[$resource['sub']] = $sub;
        }
        $update = $this->stamp($update, $context['user_id'], false);

        $query->update($update);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'renamed_taxonomy_node',
            'Renamed ' . strtolower($resource['label']) . ' taxonomy ' .
                ($renamingSub ? 'sub-category "' . $oldSub . '" to "' . $sub . '"' : 'category "' . $oldCategory . '" to "' . $category . '"') .
                ' (' . $affected . ' ' . ($affected === 1 ? 'record' : 'records') . ')',
            $type . '-taxonomy',
            null,
            $renamingSub ? $sub : $category
        );

        // Counts and dropdown options are cached; a write makes them stale.
        $this->forgetLibraryMeta((int) $context['sub_institute_id']);

        return $this->ok('Taxonomy updated successfully', ['affected' => $affected]);
    }

    /**
     * DELETE /api/competency/library/taxonomy/{type}
     *
     * Only removes an EMPTY node - a category or sub-category whose rows are
     * all placeholders with no name. A node that still holds real library
     * entries is refused with a count, because deleting it would orphan them.
     */
    public function destroyTaxonomy(Request $request, $type)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $resource = $this->taxonomyResource($type);
        if (!$resource) {
            return $this->badRequest('That library type has no editable taxonomy.', 404);
        }

        $category = trim((string) $request->input('category', ''));
        $sub = trim((string) $request->input('sub_category', ''));

        if ($category === '') {
            return $this->badRequest('Category is required.', 422);
        }
        if ($sub !== '' && !$resource['sub']) {
            return $this->badRequest('That library type has no sub-categories.', 422);
        }

        $sid = $context['sub_institute_id'];
        $titleColumn = $resource['title'];

        $scope = fn () => $this->baseQuery($resource, $sid)
            ->where($resource['category'], $category)
            ->when($sub !== '', fn ($q) => $q->where($resource['sub'], $sub));

        $inUse = (clone $scope())
            ->whereNotNull($titleColumn)
            ->where($titleColumn, '!=', '')
            ->count();

        if ($inUse > 0) {
            return $this->badRequest(
                'This ' . ($sub !== '' ? 'sub-category' : 'category') . ' still holds ' . $inUse . ' ' .
                strtolower($resource['label']) . ($inUse === 1 ? ' entry' : ' entries') . '. Move or delete them first.',
                422
            );
        }

        if ($resource['soft']) {
            $scope()->update(['deleted_by' => $context['user_id'], 'deleted_at' => now()]);
        } else {
            $scope()->delete();
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'deleted_taxonomy_node',
            'Removed empty ' . strtolower($resource['label']) . ' taxonomy ' .
                ($sub !== '' ? 'sub-category "' . $sub . '"' : 'category "' . $category . '"'),
            $type . '-taxonomy',
            null,
            $sub !== '' ? $sub : $category
        );

        return $this->ok('Taxonomy entry removed successfully');
    }

    /* ================================================================== *
     * Skill taxonomy tree + shared filter options
     * ================================================================== */

    /**
     * GET /api/competency/library/skill-taxonomy-tree
     *
     * The category -> sub-category -> skill hierarchy the Skill Taxonomy screen
     * draws. Replaces the third-party iframe the legacy page embedded, which
     * shipped tenant data to an external host and could not be filtered.
     */
    public function skillTaxonomyTree(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $resource = $this->resource('skill');

        $query = $this->baseQuery($resource, $sid)
            ->whereNotNull('title')
            ->where('title', '!=', '');

        if ($department = $this->activeFilter($request->input('department'))) {
            $query->where('department', $department);
        }
        if ($category = $this->activeFilter($request->input('category'))) {
            $query->where('category', $category);
        }
        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('category', 'like', '%' . $search . '%')
                    ->orWhere('sub_category', 'like', '%' . $search . '%');
            });
        }

        $rows = $query
            ->orderBy('category')
            ->orderBy('sub_category')
            ->orderBy('title')
            ->limit(5000)
            ->get(['id', 'title', 'category', 'sub_category', 'department', 'proficiency_level', 'approve_status']);

        $tree = [];
        foreach ($rows as $row) {
            $category = trim((string) $row->category) !== '' ? trim((string) $row->category) : 'Uncategorised';
            $sub = trim((string) $row->sub_category) !== '' ? trim((string) $row->sub_category) : 'General';

            if (!isset($tree[$category])) {
                $tree[$category] = ['name' => $category, 'total' => 0, 'children' => []];
            }
            if (!isset($tree[$category]['children'][$sub])) {
                $tree[$category]['children'][$sub] = ['name' => $sub, 'total' => 0, 'children' => []];
            }

            $tree[$category]['total']++;
            $tree[$category]['children'][$sub]['total']++;
            $tree[$category]['children'][$sub]['children'][] = [
                'id'                => (int) $row->id,
                'name'              => (string) $row->title,
                'department'        => $row->department,
                'proficiency_level' => $row->proficiency_level,
                'approve_status'    => $row->approve_status,
            ];
        }

        // Re-key so the payload is JSON arrays rather than objects.
        $categories = array_values(array_map(function ($node) {
            $node['children'] = array_values($node['children']);
            return $node;
        }, $tree));

        return $this->ok('Skill taxonomy fetched successfully', [
            'total_skills'     => $rows->count(),
            'total_categories' => count($categories),
            'truncated'        => $rows->count() >= 5000,
            'categories'       => $categories,
        ]);
    }

    /**
     * GET /api/competency/library/work-functions
     *
     * Distinct critical work functions, optionally for one job role, so the Job
     * Role Task tab can offer the same Job Role -> Work Function drill-down the
     * legacy screen had. Kept off /meta because the list is per role and the
     * table holds tens of thousands of tasks.
     */
    public function workFunctions(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $values = DB::table('s_user_jobrole_task')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->whereNotNull('critical_work_function')
            ->where('critical_work_function', '!=', '')
            ->when($this->activeFilter($request->input('jobrole')), fn ($q, $jobrole) => $q->where('jobrole', $jobrole))
            ->distinct()
            ->orderBy('critical_work_function')
            ->limit(500)
            ->pluck('critical_work_function');

        return $this->ok('Work functions fetched successfully', $values);
    }

    /**
     * GET /api/competency/library/levels-of-responsibility
     *
     * The SFIA-style responsibility ladder that sits alongside the job role
     * taxonomy: one entry per level, with its attributes grouped by type.
     *
     * The legacy screen read this through a web route behind session middleware,
     * so it was unreachable from a token-authenticated client. The content is
     * reference data shared by every tenant, hence no tenant filter.
     */
    public function levelsOfResponsibility(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('s_level_responsibility')
            ->orderBy('level')
            ->orderBy('attribute_type')
            ->orderBy('attribute_name')
            ->get();

        $levels = [];
        foreach ($rows as $row) {
            $level = (string) $row->level;

            if (!isset($levels[$level])) {
                $levels[$level] = [
                    'level'          => $level,
                    'guiding_phrase' => $row->guiding_phrase,
                    'essence'        => $row->essence_level,
                    'guidance_notes' => $row->guidance_notes,
                    'groups'         => [],
                ];
            }

            // 'Business skills/Behavioural factors' carries a slash, which reads
            // badly as a tab label - shortened here rather than in the browser.
            $group = $row->attribute_type === 'Business skills/Behavioural factors'
                ? 'Business Skills & Behaviours'
                : (string) $row->attribute_type;

            $levels[$level]['groups'][$group][] = [
                'code'                 => $row->attribute_code,
                'name'                 => $row->attribute_name,
                'overall_description'  => $row->attribute_overall_description,
                'guidance_notes'       => $row->attribute_guidance_notes,
                'description'          => $row->attribute_description,
            ];
        }

        $data = array_values(array_map(function ($level) {
            $level['groups'] = array_map(
                fn ($name, $attributes) => ['name' => $name, 'attributes' => $attributes],
                array_keys($level['groups']),
                array_values($level['groups'])
            );

            return $level;
        }, $levels));

        return $this->ok('Levels of responsibility fetched successfully', $data);
    }

    /**
     * GET /api/competency/library/meta
     *
     * The dropdown options every tab shares: departments, job roles grouped by
     * department, distinct proficiency levels and the invisible-library types.
     * One round trip instead of the four the legacy screens fired per tab.
     */
    public function meta(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        // The database this talks to is remote: a bare `SELECT 1` costs about
        // 400ms, so the cost of this endpoint is round trips, not rows. It
        // used to make thirteen of them and take roughly four seconds.
        //
        // Cached on local disk rather than the default store, because the
        // default store is `database` - the same remote server - so caching
        // there would just swap thirteen slow round trips for one.
        //
        // Writes call forgetLibraryMeta(), so the TTL is only a backstop for
        // rows changed outside this controller.
        return Cache::store('file')->remember(
            self::metaCacheKey($sid),
            now()->addMinutes(10),
            fn () => $this->buildMeta($sid),
        );
    }

    /** Cache key for one tenant's library metadata. */
    private static function metaCacheKey(int $sid): string
    {
        return "competency:library:meta:{$sid}";
    }

    /**
     * Drop a tenant's cached metadata.
     *
     * Called after every library write, so the tab counts and dropdown options
     * reflect the change on the next read instead of after the TTL.
     */
    private function forgetLibraryMeta(int $sid): void
    {
        Cache::store('file')->forget(self::metaCacheKey($sid));
    }

    /** @return \Illuminate\Http\JsonResponse */
    private function buildMeta(int $sid)
    {
        $jobroleRows = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole')
            ->where('jobrole', '!=', '')
            ->orderBy('department')
            ->orderBy('jobrole')
            ->get(['id', 'jobrole', 'department', 'jobrole_category']);

        $byDepartment = [];
        foreach ($jobroleRows as $row) {
            $department = trim((string) $row->department);
            if ($department === '') {
                continue;
            }
            $byDepartment[$department][] = ['id' => (int) $row->id, 'jobrole' => (string) $row->jobrole];
        }
        ksort($byDepartment);

        // Four DISTINCT lists in one round trip. They are unrelated columns
        // on three tables, so a UNION of (bucket, value) pairs is the only way
        // to ask for them together; the caller splits them back apart.
        $optionRows = DB::select(
            "  SELECT 'proficiency' AS bucket, proficiency_level AS value FROM s_users_skills"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND proficiency_level IS NOT NULL AND proficiency_level <> ''"
            . " UNION SELECT 'department', department FROM s_users_skills"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND department IS NOT NULL AND department <> ''"
            // The second and third levels below department and category. They
            // are free-text columns, so offering what already exists is what
            // stops the same sub-department being spelled three ways.
            . " UNION SELECT 'sub_department', sub_department FROM s_users_skills"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND sub_department IS NOT NULL AND sub_department <> ''"
            . " UNION SELECT 'sub_department', sub_department FROM s_user_jobrole"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND sub_department IS NOT NULL AND sub_department <> ''"
            . " UNION SELECT 'micro_category', micro_category FROM s_users_skills"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND micro_category IS NOT NULL AND micro_category <> ''"
            . " UNION SELECT 'industry', industries FROM s_user_jobrole"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND industries IS NOT NULL AND industries <> ''"
            . " UNION SELECT 'invisible_type', type FROM s_invisible_library"
            . "   WHERE type IS NOT NULL AND type <> ''"
            . " UNION SELECT 'task_type', task_type FROM s_user_jobrole_task"
            . "   WHERE sub_institute_id = ? AND deleted_at IS NULL"
            . "     AND task_type IS NOT NULL AND task_type <> ''"
            . " ORDER BY bucket, value",
            [$sid, $sid, $sid, $sid, $sid, $sid, $sid]
        );

        $buckets = [
            'proficiency' => [], 'department' => [], 'sub_department' => [],
            'micro_category' => [], 'industry' => [], 'invisible_type' => [], 'task_type' => [],
        ];
        foreach ($optionRows as $row) {
            if (array_key_exists($row->bucket, $buckets)) {
                $buckets[$row->bucket][] = $row->value;
            }
        }

        // ── DEPARTMENT OPTIONS COME FROM hrms_departments, NOT FROM DISTINCT TEXT
        //
        // The UNION above builds `department` from SELECT DISTINCT over
        // s_users_skills.department - the values somebody has already TYPED. That
        // is self-referential: a tenant with no skill rows gets an EMPTY dropdown
        // and no way to fill it, because you cannot pick a department until a row
        // already has one. Meanwhile `hrms_departments` holds the real list, and
        // LMS course creation has been validating against it all along.
        //
        // THE WRITE PATH IS NOT TOUCHED. It already does the right thing (L-01 /
        // L-02, ~1,380 lines above): a name becomes an id at write time, and an
        // unmatched name is HELD, not guessed. Measured before this change:
        // 3,839 rows carry both columns and ALL 3,839 AGREE, 0 dangling ids, and
        // all 45 text-only rows resolve by name. This is an OPTIONS-SOURCE
        // change only.
        //
        // TENANT-SCOPED, AND THAT IS NOT THE "FILTERING" X-03 WARNS ABOUT.
        // `hrms_departments` carries `sub_institute_id` (tenant 7: 25 rows,
        // tenant 3: 74). Offering the whole 1,181-row table would show a caller
        // other tenants' departments - a leak, not generosity. The tenant's own
        // rows ARE its complete list, so nothing is hidden from anyone.
        //
        // ALREADY-USED VALUES FIRST. The tenant's real vocabulary is what it has
        // actually typed; the rest of its department list stays available and out
        // of the way. And the list remains SUGGESTED, NOT CLOSED - a genuinely new
        // department must still be typeable, which is exactly the case the write
        // path holds by name with a NULL id.
        $used = $buckets['department'];
        $master = DB::table('hrms_departments')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('department')->where('department', '!=', '')
            ->orderBy('department')
            ->pluck('department')
            ->all();

        $seen = [];
        $ordered = [];
        foreach (array_merge($used, $master) as $name) {
            $key = mb_strtolower(trim((string) $name));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordered[] = $name;
        }
        $buckets['department'] = $ordered;

        // X-03. Three fields named an entity and stored free text (G-LIB-02).
        // The picker mechanism already existed; these are the lists it lacked.
        //
        // SUGGESTED, NOT CLOSED. Each is offered as an open list: a tenant whose
        // library is incomplete must still be able to type a value the library
        // does not hold yet, or the picker blocks the work it was meant to help.
        //
        // certification_qualifications is DELIBERATELY ABSENT: certification_type
        // holds 0 rows, and a picker over an empty table looks like a closed list
        // and offers nothing - worse than the free text it would replace.
        // Scheduled on 'certification_type populated', not parked.
        $relatedSkills = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereNotNull('title')->where('title', '!=', '')
            ->distinct()->orderBy('title')->limit(2000)->pluck('title')->all();

        $jobTitles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereNotNull('jobrole')->where('jobrole', '!=', '')
            ->distinct()->orderBy('jobrole')->limit(2000)->pluck('jobrole')->all();

        $learningResources = DB::table('sub_std_map')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereNotNull('display_name')->where('display_name', '!=', '')
            ->distinct()->orderBy('display_name')->limit(2000)->pluck('display_name')->all();

        return $this->ok('Library metadata fetched successfully', [
            'related_skills'         => $relatedSkills,
            'job_titles'             => $jobTitles,
            'learning_resources'     => $learningResources,
            'departments'            => $buckets['department'],
            'sub_departments'        => array_values(array_unique($buckets['sub_department'])),
            'micro_categories'       => $buckets['micro_category'],
            'industries'             => $buckets['industry'],
            'jobroles_by_department' => $byDepartment,
            'proficiency_levels'     => $buckets['proficiency'],
            'invisible_types'        => $buckets['invisible_type'],
            'task_types'             => $buckets['task_type'],
            'counts'                 => $this->libraryCounts($sid),
        ]);
    }

    /**
     * Row counts per tab, for the badges on the tab strip.
     *
     * Built as one UNION ALL rather than a count per resource. Eight separate
     * COUNT queries is eight round trips, and against a remote database that
     * is roughly two and a half seconds spent almost entirely on the wire -
     * the largest table here is 85k rows, so none of it is query time.
     *
     * The WHERE clauses are assembled from the same RESOURCES config the rest
     * of the controller uses, so a new tab is counted without touching this.
     */
    private function libraryCounts(int $sid): array
    {
        $parts = [];
        $bindings = [];

        foreach (self::RESOURCES as $type => $resource) {
            $where = [];
            // The bucket label is the first placeholder in this SELECT, so its
            // binding has to lead the resource's own bindings.
            $partBindings = [$type];

            if ($resource['tenant'] === 'shared') {
                // Platform rows have no owner and are visible to everyone.
                $where[] = '(sub_institute_id IS NULL OR sub_institute_id = ?)';
                $partBindings[] = $sid;
            } elseif ($resource['tenant']) {
                $where[] = 'sub_institute_id = ?';
                $partBindings[] = $sid;
            }

            if (!empty($resource['soft'])) {
                $where[] = 'deleted_at IS NULL';
            }

            // Placeholder rows carry no title and are not real library entries.
            $title = $resource['title'];
            $where[] = "`{$title}` IS NOT NULL";
            $where[] = "`{$title}` <> ''";

            $parts[] = sprintf(
                "SELECT ? AS bucket, COUNT(*) AS total FROM `%s` WHERE %s",
                $resource['table'],
                implode(' AND ', $where)
            );

            $bindings = array_merge($bindings, $partBindings);
        }

        $rows = DB::select(implode(' UNION ALL ', $parts), $bindings);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->bucket] = (int) $row->total;
        }

        // Any resource the union somehow skipped still reports a number.
        foreach (self::RESOURCES as $type => $resource) {
            $counts[$type] = $counts[$type] ?? 0;
        }

        return $counts;
    }

}
