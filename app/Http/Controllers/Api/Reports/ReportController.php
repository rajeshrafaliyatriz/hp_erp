<?php

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Reports\ReportModuleRegistry;
use App\Domain\Reports\ReportQueryExecutionService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    use ResolvesApiIdentity;

    private const SCOPES = ['task_management', 'crm'];
    private const TYPES = ['tabular', 'summary', 'pivot', 'chart'];

    public function __construct(
        private readonly ReportModuleRegistry $registry,
        private readonly ReportQueryExecutionService $executor,
        private readonly ReportShareService $shares,
    ) {
    }

    /**
     * GET /api/reports?scope=&view={folder|all|shared}&folder_id=
     *
     * `view=all` and `view=shared` are computed — see class docblock on
     * ReportFolderController for why neither is a real folder row.
     */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $scope = (string) $request->input('scope');

        if (!in_array($scope, self::SCOPES, true)) {
            return response()->json(['status' => false, 'message' => 'Unknown scope.'], 422);
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $view = (string) $request->input('view', 'folder');

        $query = DB::table('reports as r')
            ->where('r.sub_institute_id', $tenantId)
            ->where('r.module_scope', $scope)
            ->whereNull('r.deleted_at');

        match ($view) {
            'all' => $query->where(function ($q) use ($userId) {
                $q->where('r.sharingtype', 'Public')
                    ->orWhere('r.owner_id', $userId)
                    ->orWhereExists(function ($sub) use ($userId) {
                        $sub->selectRaw('1')->from('report_shares as rs')
                            ->whereColumn('rs.report_id', 'r.id')
                            ->where('rs.viewer_user_id', $userId);
                    });
            }),
            'shared' => $query->where('r.sharingtype', 'Private')
                ->where('r.owner_id', '!=', $userId)
                ->whereExists(function ($sub) use ($userId) {
                    $sub->selectRaw('1')->from('report_shares as rs')
                        ->whereColumn('rs.report_id', 'r.id')
                        ->where('rs.viewer_user_id', $userId);
                }),
            default => $query->where('r.folder_id', (int) $request->input('folder_id'))
                ->where(function ($q) use ($userId) {
                    $q->where('r.sharingtype', 'Public')->orWhere('r.owner_id', $userId);
                }),
        };

        $reports = $query->orderByDesc('r.updated_at')
            ->get(['r.id', 'r.folder_id', 'r.report_type', 'r.primary_module', 'r.title', 'r.description', 'r.owner_id', 'r.sharingtype', 'r.last_run_at', 'r.updated_at']);

        return response()->json(['status' => true, 'data' => ['reports' => $reports]]);
    }

    public function show(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $report = $this->viewableReport($id, (int) $identity['sub_institute_id'], (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        return response()->json(['status' => true, 'data' => $this->resource($report)]);
    }

    /** POST /api/reports — save a new report definition (does not run it). */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $data = $this->validatePayload($request, $tenantId);

        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        $id = DB::table('reports')->insertGetId([
            'sub_institute_id' => $tenantId,
            'module_scope' => $data['scope'],
            'folder_id' => $data['folder_id'],
            'report_type' => $data['report_type'],
            'primary_module' => $data['primary_module'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'owner_id' => $identity['user_id'],
            'sharingtype' => $data['sharingtype'],
            'definition' => json_encode($data['definition']),
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = DB::table('reports')->where('id', $id)->first();

        return response()->json(['status' => true, 'message' => 'Report saved.', 'data' => $this->resource($report)], 201);
    }

    public function update(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $existing = $this->viewableReport($id, $tenantId, $userId);

        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        if ($existing->owner_id != $userId && !$this->shares->canEdit($id, $userId)) {
            return response()->json(['status' => false, 'message' => 'You do not have permission to edit this report.'], 403);
        }

        $data = $this->validatePayload($request, $tenantId);

        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        DB::table('reports')->where('id', $id)->update([
            'folder_id' => $data['folder_id'],
            'report_type' => $data['report_type'],
            'primary_module' => $data['primary_module'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'sharingtype' => $data['sharingtype'],
            'definition' => json_encode($data['definition']),
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        $report = DB::table('reports')->where('id', $id)->first();

        return response()->json(['status' => true, 'message' => 'Report updated.', 'data' => $this->resource($report)]);
    }

    public function destroy(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $userId = (int) $identity['user_id'];
        $existing = $this->viewableReport($id, $tenantId, $userId);

        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        if ($existing->owner_id != $userId) {
            return response()->json(['status' => false, 'message' => 'Only the owner can remove this report.'], 403);
        }

        DB::table('reports')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $userId,
        ]);

        return response()->json(['status' => true, 'message' => 'Report removed.']);
    }

    /** POST /api/reports/preview — run an UNSAVED definition for the builder's live preview. */
    public function preview(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $data = $this->validatePayload($request, $tenantId);

        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        return $this->runAndRespond($data['report_type'], $data['primary_module'], $data['definition'], $tenantId);
    }

    public function run(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $report = $this->viewableReport($id, $tenantId, (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        DB::table('reports')->where('id', $id)->update(['last_run_at' => now()]);

        return $this->runAndRespond($report->report_type, $report->primary_module, json_decode($report->definition, true), $tenantId);
    }

    /** GET /api/reports/{id}/export?format=csv */
    public function export(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $report = $this->viewableReport($id, $tenantId, (int) $identity['user_id']);

        if (!$report) {
            return response()->json(['status' => false, 'message' => 'Report not found.'], 404);
        }

        try {
            $result = $this->executor->execute($report->report_type, $report->primary_module, json_decode($report->definition, true), $tenantId);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => 'This report could not be run.'], 422);
        }

        [$header, $rows] = $this->flattenForCsv($report->report_type, $result, json_decode($report->definition, true));

        $csv = implode(',', array_map($this->csvCell(...), $header)) . "\r\n";

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($this->csvCell(...), $row)) . "\r\n";
        }

        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $report->title) . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    private function flattenForCsv(string $reportType, array $result, array $definition = []): array
    {
        // "task_management.tasks.task_title" means nothing to whoever opens
        // this in Excel - the report's own column labels ("Task", "Status")
        // are what the builder already showed them, so the export should
        // read the same way.
        $labelFor = function (string $qualifiedKey) use ($definition): string {
            foreach ($definition['columns'] ?? [] as $col) {
                if ($qualifiedKey === "{$col['module']}.{$col['field']}") {
                    return $col['label'] ?? $qualifiedKey;
                }
            }

            return $qualifiedKey;
        };

        return match ($reportType) {
            // __primary_id is internal plumbing (reserved for a future
            // drill-down/open-record action), never something an exported
            // CSV should show a viewer - confirmed live: it was leaking as
            // a literal, confusing first column before this fix.
            'tabular' => [
                $result['rows'] === []
                    ? []
                    : array_map($labelFor, array_values(array_diff(array_keys($result['rows'][0]), ['__primary_id']))),
                array_map(fn ($row) => array_values(array_diff_key($row, ['__primary_id' => true])), $result['rows']),
            ],
            'summary' => [
                $result['groups'] === [] ? ['label', 'count'] : array_keys($result['groups'][0]),
                array_map('array_values', $result['groups']),
            ],
            'pivot' => [
                array_merge(['Row'], $result['column_labels'], ['Total']),
                array_map(
                    fn ($rowLabel, $i) => array_merge([$rowLabel], $result['matrix'][$i], [$result['row_totals'][$i]]),
                    $result['row_labels'],
                    array_keys($result['row_labels']),
                ),
            ],
            'chart' => [
                array_merge(['Label'], array_map(fn ($s) => $s['name'], $result['series'])),
                array_map(
                    fn ($label, $i) => array_merge([$label], array_map(fn ($s) => $s['data'][$i] ?? '', $result['series'])),
                    $result['labels'],
                    array_keys($result['labels']),
                ),
            ],
            default => [[], []],
        };
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }

    private function runAndRespond(string $reportType, string $primaryModule, array $definition, int $tenantId)
    {
        try {
            $result = $this->executor->execute($reportType, $primaryModule, $definition, $tenantId);

            return response()->json(['status' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('report execution failed', ['error' => $e->getMessage(), 'module' => $primaryModule]);

            return response()->json(['status' => false, 'message' => 'This report could not be run.'], 500);
        }
    }

    private function viewableReport(int $id, int $tenantId, int $userId): ?object
    {
        $report = DB::table('reports')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$report) {
            return null;
        }

        $canView = $report->sharingtype === 'Public'
            || (int) $report->owner_id === $userId
            || $this->shares->isSharedWith($id, $userId);

        return $canView ? $report : null;
    }

    private function resource(object $report): array
    {
        return [
            'id' => (int) $report->id,
            'folder_id' => $report->folder_id !== null ? (int) $report->folder_id : null,
            'report_type' => $report->report_type,
            'primary_module' => $report->primary_module,
            'title' => $report->title,
            'description' => $report->description,
            'owner_id' => (int) $report->owner_id,
            'sharingtype' => $report->sharingtype,
            'definition' => json_decode($report->definition, true),
            'last_run_at' => $report->last_run_at,
            'updated_at' => $report->updated_at,
        ];
    }

    /** @return array|\Illuminate\Http\JsonResponse */
    private function validatePayload(Request $request, int $tenantId)
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:' . implode(',', self::SCOPES)],
            'folder_id' => ['nullable', 'integer'],
            'report_type' => ['required', 'string', 'in:' . implode(',', self::TYPES)],
            'primary_module' => ['required', 'string'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'sharingtype' => ['nullable', 'string', 'in:Private,Public'],
            'definition' => ['required', 'array'],
        ]);

        $data['sharingtype'] = $data['sharingtype'] ?? 'Private';

        if (!$this->registry->exists($data['primary_module'])) {
            return response()->json(['status' => false, 'message' => 'Unknown report module.'], 422);
        }

        $error = $this->validateDefinition($data['report_type'], $data['primary_module'], $data['definition'], $tenantId);

        if ($error !== null) {
            return response()->json(['status' => false, 'message' => $error], 422);
        }

        return $data;
    }

    /**
     * Server-side validation of the definition JSON — never trust the
     * client payload as execute-safe, per Docs/reports-engine/PLAN.md §2.
     * Every field reference must resolve via the registry; a chart is
     * capped at 3 data fields (vtiger's own bar/line cap); summary/pivot
     * each require their structural minimums.
     */
    private function validateDefinition(string $reportType, string $primaryModule, array $definition, int $tenantId): ?string
    {
        $primary = $this->registry->get($primaryModule);

        foreach ($definition['secondary_modules'] ?? [] as $secondary) {
            if ($primary->join($secondary) === null) {
                return "{$primaryModule} cannot join to {$secondary}.";
            }
        }

        $checkRef = function (array $ref) use ($tenantId): ?string {
            if (!isset($ref['module'], $ref['field'])) {
                return 'Every field reference needs a module and a field.';
            }

            if ($this->registry->resolve($ref['module'], $ref['field'], $tenantId) === null) {
                return "Unknown field: {$ref['module']}.{$ref['field']}.";
            }

            return null;
        };

        foreach ($definition['columns'] ?? [] as $col) {
            if (($error = $checkRef($col)) !== null) {
                return $error;
            }
        }

        if ($reportType === 'summary' && empty($definition['group_by'])) {
            return 'A summary report needs at least one group-by field.';
        }

        if ($reportType === 'pivot') {
            $pivot = $definition['pivot'] ?? null;

            if (!$pivot || empty($pivot['rows']) || empty($pivot['columns']) || empty($pivot['values'])) {
                return 'A pivot report needs rows, columns and values.';
            }
        }

        if ($reportType === 'chart') {
            $chart = $definition['chart'] ?? null;

            if (!$chart || empty($chart['group_by']) || empty($chart['data_fields'])) {
                return 'A chart report needs a group-by field and at least one data field.';
            }

            if (count($chart['data_fields']) > 3) {
                return 'A chart supports at most 3 data fields.';
            }
        }

        return null;
    }
}
