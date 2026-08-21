<?php

namespace App\Http\Controllers\Api\HRITDashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JobroleApiController extends Controller
{
    use ResolvesApiIdentity;

    public function getDepartmentWise(Request $request)
{
    $subInstituteId = $this->apiTenantId($request);
    $departmentId   = $request->department_id;

    if (!$subInstituteId) {
        return response()->json([
            "status" => false,
            "message" => "sub_institute_id is required"
        ], 400);
    }

    // Base query
    //
    // whereNull('j.deleted_at'): s_user_jobrole is soft-deleted, and this
    // listed retired roles alongside live ones. The Department Management job
    // roles tab reads this endpoint, so a deleted role would have shown up as
    // an assignable role for a department.
    $query = DB::table('s_user_jobrole AS j')
        ->leftJoin('hrms_departments AS d', function ($join) use ($subInstituteId) {
            $join->on('j.department_id', '=', 'd.id')
                 // Scoping the joined department to the same tenant stops a
                 // department name from another organisation being rendered
                 // beside this tenant's role.
                 ->where('d.sub_institute_id', '=', $subInstituteId)
                 ->whereNull('d.deleted_at');
        })
        ->where('j.sub_institute_id', $subInstituteId)
        ->whereNull('j.deleted_at')
        ->select(
            'j.id',
            'j.jobrole',
            'j.description',
            'j.jobrole_category',
            'j.department_id',
            'd.department AS department_name'
        );

    // Department filter
    if ($departmentId) {
        $query->where('j.department_id', $departmentId);
    }

    $records = $query
        ->orderBy('d.department', 'ASC')
        ->orderBy('j.jobrole', 'ASC')
        ->get();

    // If department_id given → return simplified list + department_name
    if ($departmentId) {

        // Fetch department name for top level
        $departmentName = DB::table('hrms_departments')
            ->where('id', $departmentId)
            ->value('department');

        return response()->json([
            "status" => true,
            "department_id" => $departmentId,
            "department_name" => $departmentName,
            "data" => $records
        ]);
    }

    // Else group by department_name
    $grouped = $records->groupBy('department_name');

    return response()->json([
        "status" => true,
        "data" => $grouped
    ]);
}
}
