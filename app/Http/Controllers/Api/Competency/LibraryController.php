<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
                'task', 'jobrole', 'critical_work_function', 'task_type',
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
    private function payload(array $resource, Request $request): array
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

        $data = $this->payload($resource, $request);

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

        $data = $this->payload($resource, $request);

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

        return $this->ok($resource['label'] . ' updated successfully', ['id' => $id]);
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

        $proficiency = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('proficiency_level')
            ->where('proficiency_level', '!=', '')
            ->distinct()
            ->orderBy('proficiency_level')
            ->pluck('proficiency_level');

        $departments = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        $invisibleTypes = DB::table('s_invisible_library')
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        $taskTypes = DB::table('s_user_jobrole_task')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('task_type')
            ->where('task_type', '!=', '')
            ->distinct()
            ->orderBy('task_type')
            ->pluck('task_type');

        return $this->ok('Library metadata fetched successfully', [
            'departments'          => $departments,
            'jobroles_by_department' => $byDepartment,
            'proficiency_levels'   => $proficiency,
            'invisible_types'      => $invisibleTypes,
            'task_types'           => $taskTypes,
            'counts'               => $this->libraryCounts($sid),
        ]);
    }

    /** Row counts per tab, for the badges on the tab strip. */
    private function libraryCounts(int $sid): array
    {
        $counts = [];

        foreach (self::RESOURCES as $type => $resource) {
            $counts[$type] = $this->baseQuery($resource, $sid)
                ->whereNotNull($resource['title'])
                ->where($resource['title'], '!=', '')
                ->count();
        }

        return $counts;
    }
}
