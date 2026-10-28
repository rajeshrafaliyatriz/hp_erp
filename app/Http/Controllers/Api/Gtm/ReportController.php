<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\Reports\ReportService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Support\RoleKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GTM reports. Viewing is open to Administrators and Executives (an Executive sees personal contact data masked, and
 * never sees the audit trail); EXPORTING is Administrator-only. Every export is audited with the report, its filters and
 * the number of rows, before the first byte is sent.
 */
class ReportController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request, ReportService $svc): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $admin = $this->isAdmin($identity);

        return response()->json(['status' => 1, 'data' => ['reports' => array_values($svc->catalogue($admin)), 'can_export' => $admin, 'max_rows' => ReportService::MAX_ROWS]]);
    }

    public function show(Request $request, string $key, ReportService $svc): JsonResponse
    {
        $prepared = $this->prepare($request, $key, $svc);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$identity, $admin, $filters] = $prepared;
        $r = $svc->run($key, $identity['sub_institute_id'], $filters, $admin);
        $perPage = min(200, max(10, (int) $request->input('per_page', 50)));
        $page = max(1, (int) $request->input('page', 1));
        $def = $svc->definition($key);

        return response()->json(['status' => 1, 'data' => [
            'report' => ['key' => $key, 'title' => $def['title'], 'description' => $def['description'], 'pii' => $def['pii'], 'masked' => $def['pii'] && ! $admin, 'columns' => array_map(fn ($c) => ['label' => $c[0], 'type' => $c[1]], $def['columns'])],
            'filters' => $filters, 'summary' => $r['summary'], 'truncated' => $r['truncated'], 'total' => count($r['rows']), 'page' => $page, 'per_page' => $perPage,
            'rows' => array_slice($r['rows'], ($page - 1) * $perPage, $perPage),
        ]]);
    }

    public function export(Request $request, string $key, ReportService $svc): JsonResponse|StreamedResponse
    {
        $prepared = $this->prepare($request, $key, $svc);
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }
        [$identity, $admin, $filters] = $prepared;
        if (! $admin) {
            return response()->json(['status' => 0, 'code' => 'export_forbidden', 'message' => 'Only administrators can export reports.'], 403);
        }
        $format = (string) $request->input('format', 'csv');
        if (! in_array($format, ['csv', 'json'], true)) {
            return response()->json(['status' => 0, 'message' => "Unsupported format '{$format}'. Use csv or json."], 422);
        }
        $r = $svc->run($key, $identity['sub_institute_id'], $filters, true);
        $def = $svc->definition($key);
        // logged BEFORE any data leaves, so an export that is interrupted is still on record
        GtmAudit::record('gtm.report.exported', $identity['sub_institute_id'], 'gtm_report', null, $identity['user_id'], ['report' => $key, 'format' => $format, 'rows' => count($r['rows']), 'filters' => $filters, 'truncated' => $r['truncated'], 'pii' => $def['pii']]);
        $name = "gtm-{$key}-".now()->format('Ymd-His').".{$format}";

        if ($format === 'json') {
            return response()->streamDownload(function () use ($key, $def, $r, $filters) {
                echo json_encode(['report' => $key, 'title' => $def['title'], 'generated_at' => now()->toIso8601String(), 'filters' => $filters, 'truncated' => $r['truncated'], 'summary' => $r['summary'], 'rows' => $r['rows']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }, $name, ['Content-Type' => 'application/json', 'X-Row-Count' => (string) count($r['rows']), 'X-Truncated' => $r['truncated'] ? '1' : '0']);
        }

        return response()->streamDownload(function () use ($def, $r) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
            fputcsv($out, array_map(fn ($c) => ReportService::safeCell($c[0]), array_values($def['columns'])), ',', '"', '');
            $keys = array_keys($def['columns']);
            foreach ($r['rows'] as $row) {
                fputcsv($out, array_map(fn ($k) => ReportService::safeCell($row[$k] ?? null), $keys), ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Row-Count' => (string) count($r['rows']), 'X-Truncated' => $r['truncated'] ? '1' : '0']);
    }

    /** @return array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>}|JsonResponse */
    private function prepare(Request $request, string $key, ReportService $svc): array|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $admin = $this->isAdmin($identity);
        if (! $svc->exists($key) || ($svc->definition($key)['admin_only'] && ! $admin)) {
            return response()->json(['status' => 0, 'message' => 'Report not found'], 404); // an admin-only report does not admit it exists
        }
        [$filters, $errors] = $svc->filters($key, $request->query());
        if ($errors !== []) {
            return response()->json(['status' => 0, 'message' => 'Invalid filters: '.$errors[0], 'errors' => ['filters' => $errors]], 422);
        }

        return [$identity, $admin, $filters];
    }

    private function isAdmin(array $identity): bool
    {
        $profile = DB::table('tbluserprofilemaster')->where('id', $identity['user']->user_profile_id)->first(['id', 'sub_institute_id', 'role_key', 'name']);

        return $profile !== null && RoleKey::fromProfile($profile) === 'administrator';
    }
}
