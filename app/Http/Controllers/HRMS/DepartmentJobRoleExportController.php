<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class DepartmentJobRoleExportController extends Controller
{
    /**
     * Export department and job role data to CSV
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportToCsv($subInstituteId)
    {
        // Execute the SQL query with parameterized sub_institute_id
        $results = DB::select("
            SELECT 
                a.id AS department_id, 
                a.department, 
                b.jobrole,
                b.id AS allocated_standards 
            FROM hrms_departments a
            INNER JOIN s_user_jobrole b 
                ON a.id = b.department_id
            WHERE a.sub_institute_id = ?
        ", [$subInstituteId]);

        // Generate CSV filename with timestamp
        $filename = 'department_jobroles_' . date('Y-m-d_His') . '.csv';

        // Create CSV headers
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        // Create a callback function to generate CSV content
        $callback = function () use ($results) {
            $file = fopen('php://output', 'w');

            // Add CSV headers
            fputcsv($file, ['Department ID', 'Department', 'Job Role', 'Allocated Standards']);

            // Add data rows
            foreach ($results as $row) {
                fputcsv($file, [
                    $row->department_id,
                    $row->department,
                    $row->jobrole,
                    $row->allocated_standards,
                ]);
            }

            fclose($file);
        };

        // Return streamed response for CSV download
        return Response::stream($callback, 200, $headers);
    }
}
