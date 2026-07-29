<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Audit & Activity Center (competency module, submenu cm-audit).
 *
 * The whole screen reads one table - s_competency_activity_log - which every
 * competency controller already writes to through
 * ResolvesCompetencyContext::logCompetencyActivity(). Nothing here writes to a
 * domain table; the only write is the export event this controller logs about
 * itself.
 *
 * Two things the raw table does not store are derived here rather than
 * duplicated into columns:
 *
 *  - "Module"      -> MODULES, keyed off subject_type. A record type belongs to
 *                     exactly one competency screen, so a map is the truth and a
 *                     column would only be able to drift from it.
 *  - "Action"      -> normaliseAction(), which turns the stored snake_case verb
 *                     (created_framework, bulk_verify_certifications, ...) into
 *                     the display verb the table shows plus the class the tabs
 *                     and KPI cards group by.
 *
 * The "User Actions Log" tab is the one place that reaches outside this table:
 * its per-user rollup comes from the activity log, and drilling into a user also
 * shows that user's screen-access history from tbl_user_journey_logs (the
 * existing page-visit clickstream, joined to tblmenumaster for screen names).
 *
 * Conventions match the rest of Api/Competency: Sanctum token in `token`,
 * tenant in `sub_institute_id`, {status, message, data[, pagination]} envelope.
 * NOTE `user_id` is the CONTEXT ACTOR on every /competency/* call - the user
 * being filtered on travels as `user_id_filter`.
 */
class AuditController extends Controller
{
    use ResolvesCompetencyContext;

    private const TABLE = 's_competency_activity_log';

    /** Hard ceiling on an export so a runaway request stays bounded. */
    private const EXPORT_LIMIT = 5000;

    /**
     * subject_type => [record type label, module key, module label, submenu id].
     * The submenu id is what the detail panel's "Record Link" opens - the
     * frontend turns it into /module/m2/{submenu}/{submenu}.
     */
    private const SUBJECTS = [
        'competency'                => ['Competency',               'library',       'Competency Library',        'cm-competency-library'],
        'framework'                 => ['Framework',                'framework',     'Framework & Role Mapping',  'cm-framework-mapping'],
        'framework_item'            => ['Framework Item',           'framework',     'Framework & Role Mapping',  'cm-framework-mapping'],
        'framework_weight'          => ['Framework Weighting',      'framework',     'Framework & Role Mapping',  'cm-framework-mapping'],
        'proficiency_level'         => ['Proficiency Level',        'framework',     'Framework & Role Mapping',  'cm-framework-mapping'],
        'role_mapping'              => ['Role Mapping',             'role_mapping',  'Role Mapping',              'cm-framework-mapping'],
        'mapping_review'            => ['Mapping Review',           'role_mapping',  'Role Mapping',              'cm-framework-mapping'],
        'assessment'                => ['Assessment',               'assessments',   'Assessments',               'cm-assessment-workspace'],
        'assessment_cycle'          => ['Assessment Cycle',         'assessments',   'Assessments',               'cm-assessment-workspace'],
        'certification'             => ['Certification',            'certifications','Certifications',            'cm-certifications'],
        'certification_requirement' => ['Certification Requirement','certifications','Certifications',            'cm-certifications'],
        // Every 'development' row must carry the SAME module label - filters()
        // groups by module key and keeps the first label it meets, so a
        // mismatch here would surface as a label that changes with the data.
        'development_plan'          => ['Development Plan',         'development',   'Development & Career',      'cm-development-career'],
        'plan_action'               => ['Plan Action',              'development',   'Development & Career',      'cm-development-career'],
        'career_path'               => ['Career Path',              'development',   'Development & Career',      'cm-development-career'],
        'learning_assignment'       => ['Learning Assignment',      'development',   'Development & Career',      'cm-development-career'],
        'evidence'                  => ['Evidence',                 'profiles',      'Employee Profile',          'cm-employee-profiles'],
        'employee_note'             => ['Employee Note',            'profiles',      'Employee Profile',          'cm-employee-profiles'],
        'employee_skill'            => ['Employee Competency',      'profiles',      'Employee Profile',          'cm-employee-profiles'],
        'audit_log'                 => ['Audit Log',                'administration','Administration',            'cm-audit'],
    ];

    /**
     * Stored action verb => [display label, class]. Matched longest-prefix
     * first, so 'bulk_verify_certifications' resolves through 'bulk_verify'
     * before the bare 'verify' entry is ever consulted.
     *
     * Classes drive both the tabs and the KPI cards:
     *   create | update | delete | approval | comment | io | assignment | mapping
     */
    private const ACTIONS = [
        'bulk_delete'  => ['Deleted',   'delete'],
        'bulk_verify'  => ['Verified',  'approval'],
        'bulk_reject'  => ['Rejected',  'approval'],
        'bulk_revoke'  => ['Revoked',   'update'],
        'bulk_status'  => ['Updated',   'update'],
        'bulk_'        => ['Updated',   'update'],
        'imported'     => ['Imported',  'io'],
        'import'       => ['Imported',  'io'],
        'exported'     => ['Exported',  'io'],
        'export'       => ['Exported',  'io'],
        'uploaded'     => ['Uploaded',  'io'],
        'attached'     => ['Uploaded',  'io'],
        'added_certification_document' => ['Uploaded', 'io'],
        'commented'    => ['Commented', 'comment'],
        'comment'      => ['Commented', 'comment'],
        'noted'        => ['Commented', 'comment'],
        'saved_note'   => ['Commented', 'comment'],
        'approved'     => ['Approved',  'approval'],
        'approve'      => ['Approved',  'approval'],
        'rejected'     => ['Rejected',  'approval'],
        'reject'       => ['Rejected',  'approval'],
        'calibrated'   => ['Calibrated','approval'],
        'calibrate'    => ['Calibrated','approval'],
        'verified'     => ['Verified',  'approval'],
        'published'    => ['Published', 'approval'],
        'submitted'    => ['Submitted', 'approval'],
        'certified'    => ['Certified', 'approval'],
        'completed'    => ['Completed', 'update'],
        'assigned'     => ['Assigned',  'assignment'],
        'unassigned'   => ['Unassigned','assignment'],
        'mapped'       => ['Mapped',    'mapping'],
        'unmapped'     => ['Unmapped',  'mapping'],
        'created'      => ['Created',   'create'],
        'added'        => ['Created',   'create'],
        'launched'     => ['Created',   'create'],
        'cloned'       => ['Cloned',    'create'],
        'updated'      => ['Updated',   'update'],
        'changed'      => ['Updated',   'update'],
        'set'          => ['Updated',   'update'],
        'saved'        => ['Updated',   'update'],
        'deleted'      => ['Deleted',   'delete'],
        'removed'      => ['Deleted',   'delete'],
    ];

    /** Tab id => the action classes it shows. 'history' and 'actions' are special. */
    private const TABS = [
        'approvals' => ['approval'],
        'comments'  => ['comment'],
        'io'        => ['io'],
    ];

    /* ================================================================== *
     * List
     * ================================================================== */

    /**
     * GET /competency/audit
     *
     * The main table. `tab` selects the slice (timeline = everything,
     * history = only entries carrying a field-level diff), the rest are the
     * filter bar's controls.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $query = $this->filteredQuery($request, $sid);
        $total = (clone $query)->count();

        [$column, $direction] = $this->sortFor($request);

        $rows = $query->orderBy($column, $direction)
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'status'     => 1,
            'message'    => 'Activities fetched successfully',
            'data'       => $this->decorate($rows),
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    /**
     * GET /competency/audit/metrics
     *
     * The five KPI cards. They honour the active filters (so the numbers always
     * describe what the table below is showing) and report the range they cover
     * back to the card sub-label, which is why `range_label` travels with them.
     */
    public function metrics(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // Metrics describe the filtered set, not one tab of it.
        $rows = $this->filteredQuery($request, $sid, false)
            ->get(['action', 'changes']);

        $counts = ['total' => $rows->count(), 'approvals' => 0, 'updates' => 0, 'comments' => 0, 'io' => 0, 'versions' => 0];

        foreach ($rows as $row) {
            [, $class] = $this->normaliseAction($row->action);

            if ($class === 'approval') {
                $counts['approvals']++;
            } elseif ($class === 'update') {
                $counts['updates']++;
            } elseif ($class === 'comment') {
                $counts['comments']++;
            } elseif ($class === 'io') {
                $counts['io']++;
            }

            if (!empty($row->changes) && $row->changes !== 'null' && $row->changes !== '[]') {
                $counts['versions']++;
            }
        }

        $from = $this->dateInput($request, 'from');
        $to = $this->dateInput($request, 'to');

        if ($from && $to) {
            $label = date('d M Y', strtotime($from)) . ' - ' . date('d M Y', strtotime($to));
        } elseif ($from) {
            $label = 'Since ' . date('d M Y', strtotime($from));
        } elseif ($to) {
            $label = 'Up to ' . date('d M Y', strtotime($to));
        } else {
            $label = 'All time';
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Audit metrics fetched successfully',
            'data'    => [
                'total'            => $counts['total'],
                'approvals'        => $counts['approvals'],
                'updates'          => $counts['updates'],
                'comments'         => $counts['comments'],
                'imports_exports'  => $counts['io'],
                'version_entries'  => $counts['versions'],
                'range_label'      => $label,
            ],
        ]);
    }

    /**
     * GET /competency/audit/filters
     *
     * Options for the four selects in the filter bar. Record types and modules
     * are built from what the tenant's log actually contains, so a dimension
     * with nothing behind it never shows up as a dead option.
     */
    public function filters(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $types = DB::table(self::TABLE)
            ->where('sub_institute_id', $sid)
            ->whereNotNull('subject_type')
            ->select('subject_type', DB::raw('COUNT(*) as total'))
            ->groupBy('subject_type')
            ->get();

        $recordTypes = [];
        $modules = [];

        foreach ($types as $type) {
            [$typeLabel, $moduleKey, $moduleLabel] = $this->subjectMeta($type->subject_type);

            $recordTypes[] = [
                'value' => $type->subject_type,
                'label' => $typeLabel,
                'count' => (int) $type->total,
            ];

            if (!isset($modules[$moduleKey])) {
                $modules[$moduleKey] = ['value' => $moduleKey, 'label' => $moduleLabel, 'count' => 0];
            }
            $modules[$moduleKey]['count'] += (int) $type->total;
        }

        usort($recordTypes, fn ($a, $b) => strcmp($a['label'], $b['label']));
        $modules = array_values($modules);
        usort($modules, fn ($a, $b) => strcmp($a['label'], $b['label']));

        $users = DB::table(self::TABLE)
            ->where('sub_institute_id', $sid)
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('MAX(actor_name) as actor_name'), DB::raw('COUNT(*) as total'))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($u) => [
                'value' => (string) $u->user_id,
                'label' => $u->actor_name ?: ('User ' . $u->user_id),
                'count' => (int) $u->total,
            ])
            ->all();

        // The date-range select: presets the filter bar offers out of the box.
        $ranges = [
            ['value' => 'all',   'label' => 'All time'],
            ['value' => '7d',    'label' => 'Last 7 days'],
            ['value' => '30d',   'label' => 'Last 30 days'],
            ['value' => '90d',   'label' => 'Last 90 days'],
            ['value' => 'month', 'label' => 'This month'],
            ['value' => 'year',  'label' => 'This year'],
        ];

        $actions = [];
        foreach (self::ACTIONS as $meta) {
            $actions[$meta[1]] = ['value' => $meta[1], 'label' => $this->classLabel($meta[1])];
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Audit filters fetched successfully',
            'data'    => [
                'record_types'   => $recordTypes,
                'modules'        => $modules,
                'users'          => $users,
                'date_ranges'    => $ranges,
                'action_classes' => array_values($actions),
            ],
        ]);
    }

    /**
     * GET /competency/audit/export
     *
     * Every row matching the current filters (capped), for the header's Export
     * button; the CSV is assembled client side so no new dependency is needed.
     * The export is itself logged, which is why it turns up in the
     * Imports / Exports tab straight after it runs.
     */
    public function export(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $rows = $this->filteredQuery($request, $sid)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'exported_audit_log',
            'Exported ' . $rows->count() . ' audit ' . ($rows->count() === 1 ? 'entry' : 'entries'),
            'audit_log',
            null,
            'Audit & Activity Log'
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Activities exported successfully',
            'data'    => $this->decorate($rows),
        ]);
    }

    /* ================================================================== *
     * Detail panel
     * ================================================================== */

    /**
     * GET /competency/audit/{id}
     *
     * The right-hand panel: Overview, Change Summary (only populated when the
     * originating endpoint recorded a diff) and the resolved Record Link.
     */
    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $row = DB::table(self::TABLE)
            ->where('sub_institute_id', $sid)
            ->where('id', $id)
            ->first();

        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'Activity not found'], 404);
        }

        $entry = $this->decorateRow($row);

        // Sibling entries against the same record, so the panel can show what
        // else happened to it without a second round trip.
        $related = [];
        if ($row->subject_type && $row->subject_id) {
            $related = DB::table(self::TABLE)
                ->where('sub_institute_id', $sid)
                ->where('subject_type', $row->subject_type)
                ->where('subject_id', $row->subject_id)
                ->where('id', '!=', $row->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get();
            $related = $this->decorate($related);
        }

        $entry['related'] = $related;

        return response()->json([
            'status'  => 1,
            'message' => 'Activity fetched successfully',
            'data'    => $entry,
        ]);
    }

    /* ================================================================== *
     * User Actions Log tab
     * ================================================================== */

    /**
     * GET /competency/audit/user-actions
     *
     * Per-actor rollup of the same feed: how much each user did, split by action
     * class, and when they were last active. Honours the filter bar's date range
     * and search box so the tab stays in step with the rest of the screen.
     */
    public function userActions(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $rows = $this->filteredQuery($request, $sid, false)
            ->get(['user_id', 'actor_name', 'action', 'created_at']);

        $users = [];

        foreach ($rows as $row) {
            $key = $row->user_id ? (string) $row->user_id : 'system';

            if (!isset($users[$key])) {
                $users[$key] = [
                    'user_id'   => $row->user_id ? (int) $row->user_id : null,
                    'user'      => $row->actor_name ?: 'System',
                    'initials'  => $this->initials($row->actor_name ?: 'System'),
                    'total'     => 0,
                    'created'   => 0,
                    'updated'   => 0,
                    'deleted'   => 0,
                    'approved'  => 0,
                    'commented' => 0,
                    'other'     => 0,
                    'last_at'   => null,
                ];
            }

            [, $class] = $this->normaliseAction($row->action);

            $users[$key]['total']++;
            $bucket = [
                'create'   => 'created',
                'update'   => 'updated',
                'delete'   => 'deleted',
                'approval' => 'approved',
                'comment'  => 'commented',
            ][$class] ?? 'other';
            $users[$key][$bucket]++;

            if ($row->created_at && (!$users[$key]['last_at'] || $row->created_at > $users[$key]['last_at'])) {
                $users[$key]['last_at'] = $row->created_at;
            }
        }

        $users = array_values($users);
        usort($users, fn ($a, $b) => $b['total'] <=> $a['total']);

        foreach ($users as &$user) {
            $user['last_active'] = $this->timestampLabel($user['last_at']);
        }
        unset($user);

        return response()->json([
            'status'  => 1,
            'message' => 'User actions fetched successfully',
            'data'    => $users,
        ]);
    }

    /**
     * GET /competency/audit/user-actions/{userId}
     *
     * Drill-down for one actor: their record changes from the competency feed,
     * plus their screen-access history from tbl_user_journey_logs - the existing
     * page-visit clickstream, joined to tblmenumaster for the screen name. The
     * access log is a different kind of evidence from the rest of the page, so
     * it is returned as its own list rather than merged into the timeline.
     */
    public function userActivity(Request $request, $userId)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $changes = $this->filteredQuery($request, $sid, false)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $access = DB::table('tbl_user_journey_logs as j')
            ->leftJoin('tblmenumaster as m', 'm.id', '=', 'j.menu_id')
            ->where('j.sub_institute_id', $sid)
            ->where('j.user_id', $userId)
            ->orderByDesc('j.timestamp')
            ->orderByDesc('j.id')
            ->limit(50)
            ->get([
                'j.id',
                'j.event_type',
                'j.access_link',
                'j.step_key',
                'j.timestamp',
                'm.menu_name',
            ])
            ->map(fn ($a) => [
                'id'         => (int) $a->id,
                'screen'     => $a->menu_name ?: 'Unknown screen',
                'event'      => $this->eventLabel($a->event_type),
                'event_type' => $a->event_type,
                'link'       => $this->cleanAccessLink($a->access_link),
                'step_key'   => $a->step_key,
                'at'         => $a->timestamp,
                'date'       => $this->timestampLabel($a->timestamp),
            ])
            ->all();

        $user = DB::table('tbluser')->where('id', $userId)->first();
        $name = $user
            ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->user_name ?? 'User ' . $userId))
            : ('User ' . $userId);

        return response()->json([
            'status'  => 1,
            'message' => 'User activity fetched successfully',
            'data'    => [
                'user' => [
                    'id'       => (int) $userId,
                    'name'     => $name,
                    'initials' => $this->initials($name),
                ],
                'changes'    => $this->decorate($changes),
                'access_log' => $access,
            ],
        ]);
    }

    /* ================================================================== *
     * Query building
     * ================================================================== */

    /**
     * The shared filter chain. $applyTab is false for the KPI cards and the
     * user rollup, which describe the filtered set as a whole rather than one
     * tab of it.
     */
    private function filteredQuery(Request $request, int $sid, bool $applyTab = true)
    {
        $query = DB::table(self::TABLE)->where('sub_institute_id', $sid);

        if ($applyTab) {
            $this->applyTab($query, (string) $request->input('tab', 'timeline'));
        }

        if ($from = $this->dateInput($request, 'from')) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }

        if ($to = $this->dateInput($request, 'to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        if ($recordType = $this->activeFilter($request->input('record_type'))) {
            $query->whereIn('subject_type', array_map('trim', explode(',', $recordType)));
        }

        if ($module = $this->activeFilter($request->input('module'))) {
            $types = [];
            foreach (self::SUBJECTS as $subject => $meta) {
                if ($meta[1] === $module) {
                    $types[] = $subject;
                }
            }
            // An unknown module key must not silently widen the result set.
            $query->whereIn('subject_type', $types ?: ['__none__']);
        }

        // NOTE: `user_id` is the calling actor on every /competency/* route, so
        // the user being filtered on has to travel under its own name.
        if ($actor = $this->activeFilter($request->input('user_id_filter'))) {
            $query->where('user_id', (int) $actor);
        }

        if ($class = $this->activeFilter($request->input('action_class'))) {
            $this->applyActionClasses($query, array_map('trim', explode(',', $class)));
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('subject_name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('actor_name', 'like', $like)
                    ->orWhere('action', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Tab -> slice of the feed. `history` is the only tab that is not an action
     * class: it shows the entries that actually carry a field-level diff, which
     * is exactly what a version history is.
     */
    private function applyTab($query, string $tab): void
    {
        if ($tab === 'history') {
            $query->whereNotNull('changes')
                ->where('changes', '!=', '')
                ->where('changes', '!=', '[]')
                ->where('changes', '!=', 'null');
            return;
        }

        if (isset(self::TABS[$tab])) {
            $this->applyActionClasses($query, self::TABS[$tab]);
        }

        // 'timeline' and 'actions' both read the whole feed.
    }

    /**
     * Action classes are derived, not stored, so filtering by one means matching
     * every stored verb that maps to it. Cheap here because the map is small and
     * the resulting LIKE set is bounded by the prefix list.
     *
     * @param array<int, string> $classes
     */
    private function applyActionClasses($query, array $classes): void
    {
        $prefixes = [];
        foreach (self::ACTIONS as $prefix => $meta) {
            if (in_array($meta[1], $classes, true)) {
                $prefixes[] = $prefix;
            }
        }

        if (!$prefixes) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                // Stored verbs are either the prefix itself ('approved') or the
                // prefix followed by the subject ('approved_mapping_review').
                $q->orWhere('action', $prefix)
                    ->orWhere('action', 'like', $prefix . '%');
            }
        });
    }

    /** @return array{0:string, 1:string} [column, direction] */
    private function sortFor(Request $request): array
    {
        $map = [
            'date'   => 'created_at',
            'user'   => 'actor_name',
            'action' => 'action',
            'record' => 'subject_name',
            'type'   => 'subject_type',
        ];

        $column = $map[(string) $request->input('sort', 'date')] ?? 'created_at';
        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$column, $direction];
    }

    /**
     * Accepts either an explicit from/to date or the `range` preset the filter
     * bar's first select offers.
     */
    private function dateInput(Request $request, string $key): ?string
    {
        $explicit = $this->activeFilter($request->input($key));
        if ($explicit) {
            $stamp = strtotime($explicit);
            return $stamp ? date('Y-m-d', $stamp) : null;
        }

        $range = $this->activeFilter($request->input('range'));
        if (!$range) {
            return null;
        }

        $bounds = [
            '7d'    => [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')],
            '30d'   => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            '90d'   => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
            'month' => [date('Y-m-01'), date('Y-m-t')],
            'year'  => [date('Y-01-01'), date('Y-12-31')],
        ];

        if (!isset($bounds[$range])) {
            return null;
        }

        return $key === 'from' ? $bounds[$range][0] : $bounds[$range][1];
    }

    /* ================================================================== *
     * Decoration
     * ================================================================== */

    /** @param \Illuminate\Support\Collection $rows */
    private function decorate($rows): array
    {
        return collect($rows)->map(fn ($row) => $this->decorateRow($row))->values()->all();
    }

    private function decorateRow($row): array
    {
        [$typeLabel, $moduleKey, $moduleLabel, $submenu] = $this->subjectMeta($row->subject_type);
        [$actionLabel, $actionClass] = $this->normaliseAction($row->action);

        $changes = $this->decodeChanges($row->changes ?? null);
        $actor = $row->actor_name ?: 'System';

        // Rows written before subject_name existed still carry the record name
        // inside the description, so fall back to what is quoted there.
        $recordName = $row->subject_name ?: $this->nameFromDescription($row->description);

        return [
            'id'              => (int) $row->id,
            'at'              => $row->created_at,
            'date'            => $row->created_at ? date('d M Y, h:i A', strtotime((string) $row->created_at)) : null,
            'relative'        => $this->timestampLabel($row->created_at),
            'user_id'         => $row->user_id ? (int) $row->user_id : null,
            'user'            => $actor,
            'initials'        => $this->initials($actor),
            'action'          => $actionLabel,
            'action_class'    => $actionClass,
            'action_raw'      => $row->action,
            'record_type'     => $typeLabel,
            'record_type_key' => $row->subject_type,
            'record_name'     => $recordName,
            'record_id'       => $row->subject_id ? (int) $row->subject_id : null,
            'module'          => $moduleLabel,
            'module_key'      => $moduleKey,
            'submenu_id'      => $submenu,
            'description'     => $row->description,
            'changes'         => $changes,
            'changes_count'   => count($changes),
            'has_changes'     => $changes !== [],
        ];
    }

    /** @return array<int, array{field:string, label:string, old:?string, new:?string}> */
    private function decodeChanges($raw): array
    {
        if (!$raw) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded)) {
            return [];
        }

        $changes = [];
        foreach ($decoded as $change) {
            if (!is_array($change) || !isset($change['field'])) {
                continue;
            }

            $changes[] = [
                'field' => (string) $change['field'],
                'label' => (string) ($change['label'] ?? ucwords(str_replace('_', ' ', (string) $change['field']))),
                'old'   => $this->scalarValue($change['old'] ?? null),
                'new'   => $this->scalarValue($change['new'] ?? null),
            ];
        }

        return $changes;
    }

    private function scalarValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => (string) $item, $value));
        }

        return (string) $value;
    }

    /** @return array{0:string, 1:string, 2:string, 3:?string} */
    private function subjectMeta(?string $subjectType): array
    {
        if ($subjectType && isset(self::SUBJECTS[$subjectType])) {
            return self::SUBJECTS[$subjectType];
        }

        if ($subjectType) {
            return [ucwords(str_replace('_', ' ', $subjectType)), 'other', 'Competency Management', null];
        }

        return ['Competency Management', 'other', 'Competency Management', null];
    }

    /**
     * Stored verb -> [display label, class]. Longest prefix wins, so
     * 'bulk_verify_certifications' never falls through to the bare 'verify'
     * rule and 'added_certification_document' is an upload, not a create.
     *
     * @return array{0:string, 1:string}
     */
    private function normaliseAction(?string $action): array
    {
        $action = strtolower(trim((string) $action));

        if ($action === '') {
            return ['Activity', 'other'];
        }

        $best = null;
        $bestLength = 0;

        foreach (self::ACTIONS as $prefix => $meta) {
            if ($action === $prefix || str_starts_with($action, $prefix)) {
                if (strlen($prefix) > $bestLength) {
                    $best = $meta;
                    $bestLength = strlen($prefix);
                }
            }
        }

        if ($best) {
            return $best;
        }

        return [ucwords(str_replace('_', ' ', $action)), 'other'];
    }

    private function classLabel(string $class): string
    {
        return [
            'create'     => 'Created',
            'update'     => 'Updated',
            'delete'     => 'Deleted',
            'approval'   => 'Approvals',
            'comment'    => 'Comments',
            'io'         => 'Imports / Exports',
            'assignment' => 'Assignments',
            'mapping'    => 'Role Mappings',
        ][$class] ?? ucfirst($class);
    }

    /** Pull the quoted record name out of a legacy description line. */
    private function nameFromDescription(?string $description): ?string
    {
        if (!$description) {
            return null;
        }

        return preg_match('/"([^"]+)"/', $description, $matches) ? $matches[1] : null;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? end($parts) : '';

        $initials = strtoupper(substr($first, 0, 1) . substr((string) $last, 0, 1));

        return $initials !== '' ? $initials : '?';
    }

    private function timestampLabel($value): ?string
    {
        if (!$value) {
            return null;
        }

        $stamp = strtotime((string) $value);
        if (!$stamp) {
            return null;
        }

        $diff = time() - $stamp;

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return $mins . ' min' . ($mins === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);
            return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
        }

        return date('d M Y', $stamp);
    }

    private function eventLabel(?string $eventType): string
    {
        return [
            'page_visit'         => 'Opened screen',
            'tour_step_view'     => 'Viewed tour step',
            'tour_step_complete' => 'Completed tour step',
            'tour_skipped'       => 'Skipped tour',
        ][$eventType] ?? ucwords(str_replace('_', ' ', (string) $eventType));
    }

    /** The clickstream stores a placeholder string when a menu has no route. */
    private function cleanAccessLink(?string $link): ?string
    {
        if (!$link || str_starts_with($link, 'No access link')) {
            return null;
        }

        return $link;
    }
}
