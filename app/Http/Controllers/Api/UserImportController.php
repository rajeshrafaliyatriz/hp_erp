<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;



class UserImportController extends Controller
{
     public function importUsers(Request $request)
    {
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

            // 🔥 Only matching DB columns
            $filteredData = array_intersect_key(
                $rowData,
                array_flip($tableColumns)
            );

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
