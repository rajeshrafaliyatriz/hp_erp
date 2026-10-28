<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\ContactImporter;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CSV import of contacts. `preview` reads and suggests a column mapping and writes nothing.
 * `import` with dry_run=true reports exactly what a commit would do; dry_run=false commits only
 * the rows that pass, re-validated from the file (the preview is never trusted).
 */
class ContactImportController extends Controller
{
    use ResolvesApiIdentity;

    public function preview(Request $request, ContactImporter $importer): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $v = Validator::make($request->all(), ['csv' => 'required|string']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        try {
            $p = $importer->parse((string) $request->input('csv'));
        } catch (\DomainException $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 1, 'data' => [
            'headers' => $p['headers'], 'sample' => array_slice($p['rows'], 0, 8), 'row_count' => count($p['rows']),
            'suggested_mapping' => $importer->suggestMapping($p['headers']), 'fields' => ContactImporter::FIELDS,
        ]]);
    }

    public function import(Request $request, ContactImporter $importer): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $v = Validator::make($request->all(), [
            'csv' => 'required|string', 'mapping' => 'required|array', 'mapping.*' => 'nullable|integer|min:0|max:200',
            'default_account_id' => 'nullable|integer', 'create_missing_accounts' => 'sometimes|boolean', 'dry_run' => 'sometimes|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $mapping = array_intersect_key((array) $request->input('mapping'), array_flip(ContactImporter::FIELDS));
        $commit = $request->has('dry_run') ? ! $request->boolean('dry_run') : false; // a commit must be asked for explicitly

        try {
            $result = $importer->run(
                $identity['sub_institute_id'], $identity['user_id'], (string) $request->input('csv'), $mapping,
                $request->filled('default_account_id') ? (int) $request->input('default_account_id') : null,
                $request->boolean('create_missing_accounts'), $commit,
            );
        } catch (\DomainException $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 1, 'data' => $result + ['committed' => $commit]]);
    }
}
