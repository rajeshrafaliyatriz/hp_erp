<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;


class UserImportController extends Controller
{
    /**
     * Columns a CSV must never be able to set.
     *
     * This endpoint intersected the CSV header with the full tbluser column
     * list, so an uploaded file could write sub_institute_id (creating users
     * inside another institute), is_admin and portal_user (self-elevation),
     * status, or plain_password. None of those belong to the uploader.
     */
    private const FORBIDDEN_IMPORT_COLUMNS = [
        'id', 'sub_institute_id', 'client_id', 'is_admin', 'portal_user',
        'plain_password', 'otp', 'remember_token', 'fcm_token',
        'user_profile_id', 'created_by', 'updated_by', 'deleted_by', 'deleted_at',
    ];

    public function importUsers(Request $request)
    {
        /*
         * This endpoint previously had no authentication of any kind: an
         * anonymous POST could insert users into any institute. A token is now
         * required, and the tenant is taken from that token rather than from
         * the request, so a caller cannot name someone else's institute.
         */
        $token = $request->input('token') ?: $request->bearerToken();
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;

        if (!$accessToken) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication is required to import users.',
            ], 401);
        }

        $subInstituteId = optional($accessToken->tokenable)->sub_institute_id;

        if (!$subInstituteId) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not linked to an institute.',
            ], 403);
        }

        // ❌ Check file
        if (!$request->hasFile('file')) {
            return response()->json([
                'status' => false,
                'message' => 'Please upload CSV file'
            ]);
        }

        $file = fopen($request->file('file')->getRealPath(), 'r');

        // ✅ Read header
        $header = fgetcsv($file);

        if (!$header) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid CSV file'
            ]);
        }

        // ✅ Get DB columns
        $tableColumns = DB::getSchemaBuilder()->getColumnListing('tbluser');

        $data = [];
        $total = 0;

        while (($row = fgetcsv($file)) !== false) {

            $rowData = array_combine($header, $row);

            if (!$rowData) continue;

            // 🔥 FIX: \N and empty → NULL
            $rowData = array_map(function ($value) {
                return ($value === '\N' || $value === '') ? null : $value;
            }, $rowData);

            // 🔥 Only matching DB columns, minus the ones a file may not set.
            $filteredData = array_diff_key(
                array_intersect_key($rowData, array_flip($tableColumns)),
                array_flip(self::FORBIDDEN_IMPORT_COLUMNS)
            );

            // The tenant comes from the caller's token, never the file.
            $filteredData['sub_institute_id'] = $subInstituteId;

            // 🔐 Password hash
            if (isset($filteredData['password']) && !empty($filteredData['password'])) {
                $filteredData['password'] = Hash::make($filteredData['password']);
            }

            // ⏱ timestamps (if exist in table)
            if (in_array('created_at', $tableColumns)) {
                $filteredData['created_at'] = now();
            }

            if (in_array('updated_at', $tableColumns)) {
                $filteredData['updated_at'] = now();
            }

            $data[] = $filteredData;
            $total++;
        }

        fclose($file);

        // 🚀 Insert in chunks (fast + memory safe)
        foreach (array_chunk($data, 500) as $chunk) {
            DB::table('tbluser')->insert($chunk);
        }

        return response()->json([
            'status' => true,
            'message' => 'CSV imported successfully',
            'total_records' => $total
        ]);
    }
}
