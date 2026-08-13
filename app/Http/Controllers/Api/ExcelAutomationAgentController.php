<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

class ExcelAutomationAgentController extends Controller
{
    private const DEFAULT_HEADERS = [
        'Platform',
        'Topic',
        'Full Post Copy',
        'Image Brief',
        'Post Date',
        'Status',
        'Image/Video',
        'Formate',
    ];

    public function upload(Request $request)
    {
        $file = $this->uploadedExcelFile($request);

        $validator = Validator::make(
            array_merge($request->all(), ['file' => $file]),
            [
                'token' => 'required|string',
                'sub_institute_id' => 'nullable|integer',
                'template_id' => 'nullable|integer',
                'sheet_name' => 'nullable|string|max:255',
                'file' => 'required|file|mimes:xlsx|max:10240',
                // Dry run: parse and check the file, report what would be
                // written, then stop before touching the Google Sheet.
                'validate_only' => 'nullable|boolean',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUser = $this->tokenUser($request->input('token'));

        if (!$tokenUser) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid token',
            ], 401);
        }

        try {
            $subInstituteId = $this->resolveSubInstituteId($request, $tokenUser);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        $templateConfig = $this->templateConfig($subInstituteId, $request->input('template_id'));
        $expectedHeaders = $templateConfig['headers'];

        try {
            $excelRows = $this->readXlsxRows($file->getRealPath());
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to read Excel file.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $headerRowNumber = $this->firstNonEmptyRowNumber($excelRows);

        if (!$headerRowNumber) {
            return response()->json([
                'status' => false,
                'message' => 'Excel file is empty.',
            ], 422);
        }

        $headerRow = $excelRows[$headerRowNumber] ?? [];
        $headerCheck = $this->compareHeaders($expectedHeaders, $headerRow);

        if (!$headerCheck['matches']) {
            return response()->json([
                'status' => false,
                'message' => 'Excel file columns do not match the Google Sheet template.',
                'expected_headers' => $expectedHeaders,
                'received_headers' => $headerCheck['received_headers'],
                'extra_headers' => $headerCheck['extra_headers'],
                'mismatches' => $headerCheck['mismatches'],
                'header_row' => $headerRowNumber,
            ], 422);
        }

        $prepared = $this->prepareDataRows(
            $excelRows,
            $headerRowNumber,
            $expectedHeaders,
            $templateConfig['validation_rules']
        );

        if (!empty($prepared['errors'])) {
            return response()->json([
                'status' => false,
                'message' => 'Excel file has validation errors.',
                'errors' => $prepared['errors'],
            ], 422);
        }

        if (empty($prepared['rows'])) {
            return response()->json([
                'status' => false,
                'message' => 'Excel file has no data rows after the header.',
            ], 422);
        }

        // Everything above is validation. A dry run stops here and reports what
        // would happen, so the UI can preview a file before anything is written
        // to the customer's Google Sheet.
        if ($request->boolean('validate_only')) {
            return response()->json([
                'status' => true,
                'validated_only' => true,
                'message' => 'File is valid and ready to upload.',
                'template_id' => $templateConfig['template_id'],
                'expected_headers' => $expectedHeaders,
                'received_headers' => $headerCheck['received_headers'],
                'header_row' => $headerRowNumber,
                'rows_ready' => count($prepared['rows']),
                'skipped_empty_rows' => $prepared['skipped_empty_rows'],
                // A short preview so the user recognises their own data.
                'preview_rows' => array_slice($prepared['rows'], 0, 5),
            ]);
        }

        try {
            $credential = $this->activeGoogleCredential($subInstituteId);
            $serviceAccount = $this->serviceAccountFromCredential($credential->service_account_key);
            $accessToken = $this->googleAccessToken($serviceAccount, 'https://www.googleapis.com/auth/spreadsheets');
            $sheetTitle = $this->sheetTitle($accessToken, $credential->google_sheet_id, $request->input('sheet_name'));

            $masterRows = $this->googleSheetValues($accessToken, $credential->google_sheet_id, $sheetTitle, 'A1:H');
            $masterHeaderCheck = $this->compareHeaders($expectedHeaders, $masterRows[0] ?? []);

            if (!$masterHeaderCheck['matches']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Google Sheet columns do not match the Excel template.',
                    'expected_headers' => $expectedHeaders,
                    'received_headers' => $masterHeaderCheck['received_headers'],
                    'extra_headers' => $masterHeaderCheck['extra_headers'],
                    'mismatches' => $masterHeaderCheck['mismatches'],
                    'sheet_title' => $sheetTitle,
                ], 422);
            }

            $nextRow = $this->nextWritableRow($masterRows);
            $endRow = $nextRow + count($prepared['rows']) - 1;
            $writtenRange = $this->writeGoogleSheetRows(
                $accessToken,
                $credential->google_sheet_id,
                $sheetTitle,
                "A{$nextRow}:H{$endRow}",
                $prepared['rows']
            );
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload Excel data to Google Sheet.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $summary = [
            'sub_institute_id' => $subInstituteId,
            'template_id' => $templateConfig['template_id'],
            'google_sheet_id' => $credential->google_sheet_id,
            'sheet_title' => $sheetTitle,
            'rows_uploaded' => count($prepared['rows']),
            'skipped_empty_rows' => $prepared['skipped_empty_rows'],
            'written_range' => $writtenRange,
        ];

        // Record it as an agent run so this upload shows up in the agent's
        // History like every other agent's work, rather than being invisible
        // because it happens to go through its own endpoint.
        $this->recordAgentRun($subInstituteId, $file->getClientOriginalName(), $summary);

        return response()->json([
            'status' => true,
            'message' => 'Excel data uploaded to Google Sheet successfully.',
        ] + $summary);
    }

    /**
     * Log a completed upload against the Excel automation agent.
     *
     * Best effort on purpose: the rows are already in the customer's sheet by
     * the time this runs, so a logging failure must not turn a successful
     * upload into an error response.
     */
    private function recordAgentRun(int $subInstituteId, string $fileName, array $summary): void
    {
        try {
            $agent = DB::table('agentic_agents')
                ->where('slug', 'social-media-automation')
                ->whereNull('deleted_at')
                ->first();

            if (!$agent) {
                return;
            }

            DB::table('agentic_agent_runs')->insert([
                'sub_institute_id' => $subInstituteId,
                'agent_id'         => $agent->id,
                'status'           => 'success',
                'trigger'          => 'manual',
                'input'            => 'Uploaded ' . $fileName,
                'output'           => json_encode($summary),
                'started_at'       => now(),
                'completed_at'     => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (Throwable $e) {
            // Nothing to recover: the upload itself succeeded.
        }
    }

    public function credentialStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'sub_institute_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUser = $this->tokenUser($request->input('token'));

        if (!$tokenUser) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid token',
            ], 401);
        }

        try {
            $subInstituteId = $this->resolveSubInstituteId($request, $tokenUser);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        $credential = DB::table('institute_google_credentials')
            ->where('sub_institute_id', $subInstituteId)
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();

        $template = DB::table('excel_templates')
            ->where('sub_institute_id', $subInstituteId)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'status' => true,
            'sub_institute_id' => $subInstituteId,
            'has_google_credentials' => (bool) $credential,
            'google_credential' => $credential ? [
                'id' => $credential->id,
                'google_sheet_id' => $credential->google_sheet_id,
                'is_active' => (bool) $credential->is_active,
                'created_at' => $credential->created_at,
                'updated_at' => $credential->updated_at,
            ] : null,
            'template' => $template ? [
                'id' => $template->id,
                'name' => $template->name,
                'columns' => array_values(json_decode((string) $template->column_mapping, true) ?: []),
                'updated_at' => $template->updated_at,
            ] : null,
        ]);
    }

    public function saveCredentials(Request $request)
    {
        $credentialFile = $request->file('service_account_key_file')
            ?: $request->file('credentials_file')
            ?: $request->file('json_file');

        $validator = Validator::make(
            array_merge($request->all(), ['service_account_key_file' => $credentialFile]),
            [
                'token' => 'required|string',
                'sub_institute_id' => 'nullable|integer',
                'google_sheet_id' => 'nullable|string|max:255',
                'google_sheet_url' => 'nullable|string|max:1000',
                'service_account_key' => 'nullable|string',
                'service_account_key_file' => 'nullable|file|max:512',
                'sheet_name' => 'nullable|string|max:255',
                'test_connection' => 'nullable|boolean',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUser = $this->tokenUser($request->input('token'));

        if (!$tokenUser) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid token',
            ], 401);
        }

        // O-03. THE TENANT GUARD GETS ITS OWN TRY, AHEAD OF THE WORK.
        //
        // It used to be the first statement inside the main try, whose single
        // catch rewrote every failure as a 500 with one generic message. A tenant
        // REFUSAL and a Google OUTAGE came back byte-identical, so:
        //   - the caller was told "server error" when it was actually "forbidden";
        //   - and the route could not be MEASURED. Probing it cross-tenant
        //     returned the same 500 the own-tenant call returned, which is no
        //     evidence in either direction.
        //
        // The refusal was real - proven by reachability, not by the message: with
        // no google_sheet_id an own-tenant call reaches the 422 below, a
        // cross-tenant call never does. This does not add a guard. It stops a
        // catch from disguising the one that was already there, and matches
        // credentialStatus and downloadTemplate, which already answer 403.
        try {
            $subInstituteId = $this->resolveSubInstituteId($request, $tokenUser);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        try {
            $sheetId = $this->extractSheetId($request->input('google_sheet_id') ?: $request->input('google_sheet_url'));

            if (!$sheetId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Google Sheet ID or URL is required.',
                ], 422);
            }

            $keyJson = $credentialFile
                ? file_get_contents($credentialFile->getRealPath())
                : (string) $request->input('service_account_key', '');

            $serviceAccount = json_decode($keyJson, true);

            if (!is_array($serviceAccount) || ($serviceAccount['type'] ?? '') !== 'service_account') {
                return response()->json([
                    'status' => false,
                    'message' => 'Service account key must be valid Google service account JSON.',
                ], 422);
            }

            if (empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Service account JSON must include client_email and private_key.',
                ], 422);
            }

            $encryptedKey = Crypt::encryptString($keyJson);
            $now = now();
            $existing = DB::table('institute_google_credentials')
                ->where('sub_institute_id', $subInstituteId)
                ->where('is_active', 1)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                DB::table('institute_google_credentials')
                    ->where('id', $existing->id)
                    ->update([
                        'google_sheet_id' => $sheetId,
                        'service_account_key' => $encryptedKey,
                        'updated_by' => $tokenUser->id ?? null,
                        'updated_at' => $now,
                    ]);

                $credentialId = $existing->id;
                $action = 'updated';
            } else {
                $credentialId = DB::table('institute_google_credentials')->insertGetId([
                    'sub_institute_id' => $subInstituteId,
                    'google_sheet_id' => $sheetId,
                    'service_account_key' => $encryptedKey,
                    'is_active' => 1,
                    'created_by' => $tokenUser->id ?? null,
                    'updated_by' => $tokenUser->id ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $action = 'created';
            }

            $template = $this->ensureDefaultTemplate($subInstituteId, $tokenUser->id ?? null);
            $testResult = null;

            if ($request->boolean('test_connection')) {
                $accessToken = $this->googleAccessToken($serviceAccount, 'https://www.googleapis.com/auth/spreadsheets');
                $sheetTitle = $this->sheetTitle($accessToken, $sheetId, $request->input('sheet_name'));
                $masterRows = $this->googleSheetValues($accessToken, $sheetId, $sheetTitle, 'A1:H');
                $headerCheck = $this->compareHeaders(self::DEFAULT_HEADERS, $masterRows[0] ?? []);

                $testResult = [
                    'success' => $headerCheck['matches'],
                    'sheet_title' => $sheetTitle,
                    'expected_headers' => self::DEFAULT_HEADERS,
                    'received_headers' => $headerCheck['received_headers'],
                    'mismatches' => $headerCheck['mismatches'],
                ];
            }
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to save Google Sheet credentials.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => "Google Sheet credentials {$action} successfully.",
            'action' => $action,
            'credential_id' => $credentialId,
            'sub_institute_id' => $subInstituteId,
            'google_sheet_id' => $sheetId,
            'client_email' => $serviceAccount['client_email'],
            'template_id' => $template->id,
            'test_connection' => $testResult,
        ], $action === 'created' ? 201 : 200);
    }

    /**
     * Download a blank workbook using this organisation's own template headers.
     *
     * Built here rather than in the browser for two reasons: the headers live
     * in the database next to the validation rules, so a server-built file is
     * always the one `upload()` will accept; and the client would otherwise
     * need a spreadsheet library just to emit eight strings.
     *
     * The file is a minimal OOXML package written with ZipArchive - the same
     * component readXlsxRows() uses to read one back.
     */
    public function downloadTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'sub_institute_id' => 'nullable|integer',
            'template_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $tokenUser = $this->tokenUser($request->input('token'));

        if (!$tokenUser) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 401);
        }

        try {
            $subInstituteId = $this->resolveSubInstituteId($request, $tokenUser);
        } catch (Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 403);
        }

        $headers = $this->templateConfig($subInstituteId, $request->input('template_id'))['headers'];

        try {
            $path = $this->buildTemplateWorkbook($headers);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Could not build the template file.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->download($path, 'content_plan_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Write a one-row .xlsx containing just the header cells.
     *
     * Inline strings are used so the package needs no sharedStrings part,
     * which keeps this to the four entries a reader actually requires.
     *
     * @param  array<int, string>  $headers
     * @return string  path to the generated file
     */
    private function buildTemplateWorkbook(array $headers): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the workbook archive.');
        }

        $cells = '';
        foreach (array_values($headers) as $index => $header) {
            $reference = $this->columnName($index) . '1';
            $cells .= sprintf(
                '<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $reference,
                htmlspecialchars((string) $header, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Content Plan" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');

        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData><row r="1">' . $cells . '</row></sheetData>'
            . '</worksheet>');

        $zip->close();

        return $path;
    }

    public function testConnection(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'sub_institute_id' => 'nullable|integer',
            'sheet_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokenUser = $this->tokenUser($request->input('token'));

        if (!$tokenUser) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid token',
            ], 401);
        }

        // O-03. THE TENANT GUARD GETS ITS OWN TRY, AHEAD OF THE WORK.
        //
        // It used to be the first statement inside the main try, whose single
        // catch rewrote every failure as a 500 with one generic message. A tenant
        // REFUSAL and a Google OUTAGE came back byte-identical, so:
        //   - the caller was told "server error" when it was actually "forbidden";
        //   - and the route could not be MEASURED. Probing it cross-tenant
        //     returned the same 500 the own-tenant call returned, which is no
        //     evidence in either direction.
        //
        // The refusal was real - proven by reachability, not by the message: with
        // no google_sheet_id an own-tenant call reaches the 422 below, a
        // cross-tenant call never does. This does not add a guard. It stops a
        // catch from disguising the one that was already there, and matches
        // credentialStatus and downloadTemplate, which already answer 403.
        try {
            $subInstituteId = $this->resolveSubInstituteId($request, $tokenUser);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        try {
            $credential = $this->activeGoogleCredential($subInstituteId);
            $serviceAccount = $this->serviceAccountFromCredential($credential->service_account_key);
            $accessToken = $this->googleAccessToken($serviceAccount, 'https://www.googleapis.com/auth/spreadsheets');
            $sheetTitle = $this->sheetTitle($accessToken, $credential->google_sheet_id, $request->input('sheet_name'));
            $masterRows = $this->googleSheetValues($accessToken, $credential->google_sheet_id, $sheetTitle, 'A1:H');
            $expectedHeaders = $this->templateConfig($subInstituteId, null)['headers'];
            $headerCheck = $this->compareHeaders($expectedHeaders, $masterRows[0] ?? []);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Google Sheet connection test failed.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => $headerCheck['matches'],
            'message' => $headerCheck['matches']
                ? 'Google Sheet connection is ready.'
                : 'Google Sheet connected, but headers do not match the template.',
            'sub_institute_id' => $subInstituteId,
            'google_sheet_id' => $credential->google_sheet_id,
            'sheet_title' => $sheetTitle,
            'client_email' => $serviceAccount['client_email'],
            'expected_headers' => $expectedHeaders,
            'received_headers' => $headerCheck['received_headers'],
            'extra_headers' => $headerCheck['extra_headers'],
            'mismatches' => $headerCheck['mismatches'],
        ], $headerCheck['matches'] ? 200 : 422);
    }

    private function uploadedExcelFile(Request $request): ?UploadedFile
    {
        return $request->file('file') ?: $request->file('excel_file');
    }

    private function tokenUser(string $token): ?object
    {
        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable;
    }

    private function resolveSubInstituteId(Request $request, object $tokenUser): int
    {
        $requestedSubInstituteId = $request->filled('sub_institute_id')
            ? (int) $request->input('sub_institute_id')
            : null;
        $tokenSubInstituteId = isset($tokenUser->sub_institute_id)
            ? (int) $tokenUser->sub_institute_id
            : null;

        // G-SEC-28. THE SUPER-ADMIN BRANCH IS GONE.
        //
        // It returned the REQUESTED tenant when the caller was is_admin=1 AND
        // their own sub_institute_id was 0 or NULL. Measured: 0 of 2 admin
        // accounts qualify, and 0 of 401 accounts have a null or zero tenant -
        // the branch was unreachable.
        //
        // WHAT MUST NOT GO WITH IT: everything below. This helper is STRICTER
        // than the shared trait - resolveApiIdentity silently IGNORES a
        // mismatched tenant, this one THROWS. That strictness is the reason this
        // controller was cleared during the G-SEC-26 triage, and removing an
        // unreachable branch must not soften it.

        if (!$tokenSubInstituteId) {
            throw new RuntimeException('Unable to resolve organization from token.');
        }

        if ($requestedSubInstituteId && $requestedSubInstituteId !== $tokenSubInstituteId) {
            throw new RuntimeException('Invalid sub institute access.');
        }

        return $tokenSubInstituteId;
    }

    private function extractSheetId(?string $sheetInput): ?string
    {
        $sheetInput = trim((string) $sheetInput);

        if ($sheetInput === '') {
            return null;
        }

        if (preg_match('/\/spreadsheets\/d\/([^\/?#]+)/', $sheetInput, $matches)) {
            return $matches[1];
        }

        return $sheetInput;
    }

    private function ensureDefaultTemplate(int $subInstituteId, ?int $userId): object
    {
        $name = 'Content Plan Google Sheet Template';
        $mapping = [
            'platform' => 'Platform',
            'topic' => 'Topic',
            'full_post_copy' => 'Full Post Copy',
            'image_brief' => 'Image Brief',
            'post_date' => 'Post Date',
            'status' => 'Status',
            'image_video' => 'Image/Video',
            'formate' => 'Formate',
        ];
        $rules = [
            'required' => ['Platform', 'Topic', 'Full Post Copy', 'Post Date', 'Status'],
            'date_columns' => ['Post Date'],
        ];
        $now = now();
        $existing = DB::table('excel_templates')
            ->where('sub_institute_id', $subInstituteId)
            ->where('name', $name)
            ->orderByDesc('id')
            ->first();
        $data = [
            'column_mapping' => json_encode($mapping, JSON_UNESCAPED_SLASHES),
            'validation_rules' => json_encode($rules, JSON_UNESCAPED_SLASHES),
            'updated_by' => $userId,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('excel_templates')
                ->where('id', $existing->id)
                ->update($data);

            return DB::table('excel_templates')->where('id', $existing->id)->first();
        }

        $id = DB::table('excel_templates')->insertGetId(array_merge($data, [
            'sub_institute_id' => $subInstituteId,
            'name' => $name,
            'created_by' => $userId,
            'created_at' => $now,
        ]));

        return DB::table('excel_templates')->where('id', $id)->first();
    }

    private function templateConfig(int $subInstituteId, ?int $templateId): array
    {
        $query = DB::table('excel_templates')
            ->where('sub_institute_id', $subInstituteId);

        if ($templateId) {
            $query->where('id', $templateId);
        }

        $template = $query->orderByDesc('id')->first();
        $mapping = $template ? json_decode((string) $template->column_mapping, true) : null;
        $rules = $template ? json_decode((string) $template->validation_rules, true) : null;

        return [
            'template_id' => $template->id ?? null,
            'headers' => is_array($mapping) && !empty($mapping)
                ? array_values($mapping)
                : self::DEFAULT_HEADERS,
            'validation_rules' => is_array($rules) ? $rules : [],
        ];
    }

    private function compareHeaders(array $expectedHeaders, array $receivedRow): array
    {
        $receivedHeaders = array_slice(array_values($receivedRow), 0, count($expectedHeaders));
        $extraHeaders = array_values(array_filter(
            array_slice(array_values($receivedRow), count($expectedHeaders)),
            fn ($value) => $this->cleanCellValue($value) !== ''
        ));
        $mismatches = [];

        foreach ($expectedHeaders as $index => $expected) {
            $received = $receivedHeaders[$index] ?? '';

            if ($this->normalizeHeader($expected) !== $this->normalizeHeader($received)) {
                $mismatches[] = [
                    'column' => $this->columnName($index),
                    'expected' => $expected,
                    'received' => $received,
                ];
            }
        }

        return [
            'matches' => empty($mismatches) && empty($extraHeaders),
            'received_headers' => $receivedHeaders,
            'extra_headers' => $extraHeaders,
            'mismatches' => $mismatches,
        ];
    }

    private function prepareDataRows(array $excelRows, int $headerRowNumber, array $headers, array $rules): array
    {
        $requiredHeaders = $rules['required'] ?? ['Platform', 'Topic', 'Full Post Copy', 'Post Date', 'Status'];
        $dateHeaders = $rules['date_columns'] ?? ['Post Date'];
        $allowedStatuses = $rules['allowed_status'] ?? [];
        $errors = [];
        $rows = [];
        $skippedEmptyRows = 0;

        foreach ($excelRows as $rowNumber => $row) {
            if ($rowNumber <= $headerRowNumber) {
                continue;
            }

            $values = [];
            $rowAssoc = [];

            foreach ($headers as $index => $header) {
                $value = $this->cleanCellValue($row[$index] ?? '');

                if (in_array($header, $dateHeaders, true)) {
                    $value = $this->normalizeDateValue($value);
                }

                $values[] = $value;
                $rowAssoc[$header] = $value;
            }

            if ($this->rowIsEmpty($values)) {
                $skippedEmptyRows++;
                continue;
            }

            foreach ($requiredHeaders as $requiredHeader) {
                if (($rowAssoc[$requiredHeader] ?? '') === '') {
                    $errors[] = [
                        'row' => $rowNumber,
                        'column' => $requiredHeader,
                        'message' => "{$requiredHeader} is required.",
                    ];
                }
            }

            if (!empty($allowedStatuses) && ($rowAssoc['Status'] ?? '') !== '') {
                $normalizedAllowed = array_map(fn ($status) => strtolower((string) $status), $allowedStatuses);

                if (!in_array(strtolower($rowAssoc['Status']), $normalizedAllowed, true)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'column' => 'Status',
                        'message' => 'Status is not allowed.',
                    ];
                }
            }

            $rows[] = $values;
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'skipped_empty_rows' => $skippedEmptyRows,
        ];
    }

    private function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open XLSX archive.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException("Worksheet XML not found: {$sheetPath}");
            }

            $sheet = simplexml_load_string($sheetXml, SimpleXMLElement::class, LIBXML_NOERROR | LIBXML_NOWARNING);

            if (!$sheet) {
                throw new RuntimeException('Worksheet XML is invalid.');
            }

            $namespaces = $sheet->getNamespaces(true);
            $mainNamespace = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rows = [];

            foreach ($sheet->children($mainNamespace)->sheetData->children($mainNamespace)->row as $row) {
                $rowNumber = (int) ($row->attributes()['r'] ?? 0);

                if (!$rowNumber) {
                    $rowNumber = count($rows) + 1;
                }

                foreach ($row->children($mainNamespace)->c as $cell) {
                    $reference = (string) ($cell->attributes()['r'] ?? '');
                    $columnIndex = $reference !== ''
                        ? $this->columnIndexFromReference($reference)
                        : count($rows[$rowNumber] ?? []);

                    $rows[$rowNumber][$columnIndex] = $this->cellValue($cell, $mainNamespace, $sharedStrings);
                }

                if (isset($rows[$rowNumber])) {
                    ksort($rows[$rowNumber]);
                }
            }

            ksort($rows);

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (!$workbook || !$rels) {
            return 'xl/worksheets/sheet1.xml';
        }

        $namespaces = $workbook->getNamespaces(true);
        $mainNamespace = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationshipNamespace = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $firstSheet = $workbook->children($mainNamespace)->sheets->children($mainNamespace)->sheet[0] ?? null;

        if (!$firstSheet) {
            return 'xl/worksheets/sheet1.xml';
        }

        $relationshipId = (string) $firstSheet->attributes($relationshipNamespace)->id;

        foreach ($rels->Relationship as $relationship) {
            $attributes = $relationship->attributes();

            if ((string) $attributes['Id'] !== $relationshipId) {
                continue;
            }

            $target = (string) $attributes['Target'];

            if (str_starts_with($target, '/xl/')) {
                return ltrim($target, '/');
            }

            return 'xl/'.ltrim($target, '/');
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStringsXml = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOERROR | LIBXML_NOWARNING);

        if (!$sharedStringsXml) {
            return [];
        }

        $namespaces = $sharedStringsXml->getNamespaces(true);
        $mainNamespace = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $strings = [];

        foreach ($sharedStringsXml->children($mainNamespace)->si as $stringItem) {
            $strings[] = $this->textFromXml($stringItem);
        }

        return $strings;
    }

    private function cellValue(SimpleXMLElement $cell, string $mainNamespace, array $sharedStrings): string
    {
        $type = (string) ($cell->attributes()['t'] ?? '');
        $children = $cell->children($mainNamespace);
        $value = isset($children->v) ? (string) $children->v : '';

        if ($type === 's') {
            return (string) ($sharedStrings[(int) $value] ?? '');
        }

        if ($type === 'inlineStr' && isset($children->is)) {
            return $this->textFromXml($children->is);
        }

        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        return $value;
    }

    private function textFromXml(SimpleXMLElement $node): string
    {
        $text = '';
        $textNodes = $node->xpath('.//*[local-name()="t"]') ?: [];

        foreach ($textNodes as $textNode) {
            $text .= (string) $textNode;
        }

        return $text !== '' ? $text : (string) $node;
    }

    private function firstNonEmptyRowNumber(array $rows): ?int
    {
        foreach ($rows as $rowNumber => $row) {
            if (!$this->rowIsEmpty($row)) {
                return (int) $rowNumber;
            }
        }

        return null;
    }

    private function activeGoogleCredential(int $subInstituteId): object
    {
        $credential = DB::table('institute_google_credentials')
            ->where('sub_institute_id', $subInstituteId)
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();

        if (!$credential) {
            throw new RuntimeException('Active Google Sheet credentials not found for this organization.');
        }

        return $credential;
    }

    private function serviceAccountFromCredential(string $storedKey): array
    {
        try {
            $json = Crypt::decryptString($storedKey);
        } catch (Throwable) {
            $json = $storedKey;
        }

        $serviceAccount = json_decode($json, true);

        if (!is_array($serviceAccount) || ($serviceAccount['type'] ?? '') !== 'service_account') {
            throw new RuntimeException('Stored Google credential is not a valid service account JSON key.');
        }

        return $serviceAccount;
    }

    private function googleAccessToken(array $serviceAccount, string $scope): string
    {
        $now = time();
        $tokenUri = $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64UrlEncode(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => $scope,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $unsignedJwt = "{$header}.{$claim}";

        if (!openssl_sign($unsignedJwt, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign Google service account JWT.');
        }

        $jwt = $unsignedJwt.'.'.$this->base64UrlEncode($signature);
        $response = Http::asForm()
            ->withoutVerifying()
            ->timeout(30)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (!$response->successful() || !$response->json('access_token')) {
            throw new RuntimeException('Google access token request failed: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    private function sheetTitle(string $accessToken, string $spreadsheetId, ?string $requestedTitle): string
    {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'?fields=sheets.properties.title';
        $response = $this->googleGet($accessToken, $url);
        $titles = array_map(
            fn ($sheet) => $sheet['properties']['title'] ?? null,
            $response['sheets'] ?? []
        );
        $titles = array_values(array_filter($titles));

        if (empty($titles)) {
            throw new RuntimeException('No sheet tabs found in Google Spreadsheet.');
        }

        if ($requestedTitle) {
            if (!in_array($requestedTitle, $titles, true)) {
                throw new RuntimeException("Sheet tab '{$requestedTitle}' was not found.");
            }

            return $requestedTitle;
        }

        return $titles[0];
    }

    private function googleSheetValues(string $accessToken, string $spreadsheetId, string $sheetTitle, string $range): array
    {
        $sheetRange = $this->sheetRange($sheetTitle, $range);
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
            .rawurlencode($spreadsheetId)
            .'/values/'
            .rawurlencode($sheetRange)
            .'?majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE';

        $response = $this->googleGet($accessToken, $url);

        return $response['values'] ?? [];
    }

    private function writeGoogleSheetRows(
        string $accessToken,
        string $spreadsheetId,
        string $sheetTitle,
        string $range,
        array $rows
    ): string {
        $sheetRange = $this->sheetRange($sheetTitle, $range);
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
            .rawurlencode($spreadsheetId)
            .'/values/'
            .rawurlencode($sheetRange)
            .'?valueInputOption=USER_ENTERED';

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->withoutVerifying()
            ->timeout(60)
            ->put($url, [
                'range' => $sheetRange,
                'majorDimension' => 'ROWS',
                'values' => $rows,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Google Sheet write failed: '.$response->body());
        }

        return (string) ($response->json('updatedRange') ?? $sheetRange);
    }

    private function googleGet(string $accessToken, string $url): array
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->withoutVerifying()
            ->timeout(60)
            ->get($url);

        if (!$response->successful()) {
            throw new RuntimeException('Google Sheets API request failed: '.$response->body());
        }

        return $response->json() ?? [];
    }

    private function nextWritableRow(array $rows): int
    {
        $lastNonEmptyRow = 1;

        foreach ($rows as $index => $row) {
            if (!$this->rowIsEmpty($row)) {
                $lastNonEmptyRow = $index + 1;
            }
        }

        return max(2, $lastNonEmptyRow + 1);
    }

    private function sheetRange(string $sheetTitle, string $range): string
    {
        return "'".str_replace("'", "''", $sheetTitle)."'!{$range}";
    }

    private function normalizeHeader(mixed $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return mb_strtolower($value);
    }

    private function cleanCellValue(mixed $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);

        return trim($value);
    }

    private function normalizeDateValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }

        return $value;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->cleanCellValue($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function columnIndexFromReference(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(ord('A') + $remainder).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
