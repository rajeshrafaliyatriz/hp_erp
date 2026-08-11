<?php

namespace App\Http\Controllers\settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use function App\Helpers\is_mobile;
use App\Models\settings\discliplinaryManagementModel;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class discliplinaryManagementController extends Controller
{
    use ResolvesApiIdentity;
    use \App\Http\Controllers\Concerns\ResolvesG2gActor;

    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */


    public function index(Request $request)
    {
        $type = $request->type;
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $sub_institute_id = $request->input('sub_institute_id');

        $res = [];
        $res['status_code'] = 0;
        $res['message'] = "Failed To find Data";

        $discliplinaryManagementModel = discliplinaryManagementModel::with([
            'departmentData' => function($query) {
                $query->select('id', 'department as department_name');
            },
            'employeeData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
            'witnessData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
            'reportByData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
        ])
        ->where('sub_institute_id', $sub_institute_id)
        ->whereNull('deleted_at')
        ->get()
        ->map(function ($item) {
            // Flatten the relationships into the main object
            $item->department_name = $item->departmentData->department_name ?? null;
            $item->employee_name = $item->employeeData->employee_name ?? null;
            $item->witness_name = $item->witnessData->employee_name ?? null;
            $item->reported_by_name = $item->reportByData->employee_name ?? null;
            
            // Remove the relationship objects
            unset($item->departmentData);
            unset($item->employeeData);
            unset($item->witnessData);
            unset($item->reportByData);
            
            return $item;
        })
        ->toArray();

        if(!empty($discliplinaryManagementModel)){
            $res['status_code'] = 1;
            $res['message'] = "Data Found";
            $res['data'] = $discliplinaryManagementModel;
        }

        return is_mobile($type, "settings/add_institute_detail", $res, "view");
    }

    public function store(Request $request)
    {
        $type = $request->type;
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $sub_institute_id = $request->input('sub_institute_id');

        $res = [];
        $res['status_code'] = 0;
        $res['message'] = "Failed To Add Data";

        $insertData = $request->except('_token','type','token','user_id');
        $insertData['created_by'] = $request->reported_by;
        $insertData['created_at'] = now();

        $addData = discliplinaryManagementModel::insert([$insertData]);

        if($addData){
            $res['status_code'] = 1;
            $res['message'] = "Data Added Successfully";
        }

        return is_mobile($type, "settings/add_institute_detail", $res, "view");
    }

    public function edit(Request $request,$id)
    {
        $type = $request->type;
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $sub_institute_id = $request->input('sub_institute_id');

        $res = [];
        $res['status_code'] = 0;
        $res['message'] = "Failed To find Data";

        $discliplinaryManagementModel = discliplinaryManagementModel::with([
            'departmentData' => function($query) {
                $query->select('id', 'department as department_name');
            },
            'employeeData' => function($query) {
                $query->select(
                    'id', 
                    DB::raw('CONCAT_WS(" ", COALESCE(first_name,"-"), COALESCE(middle_name,"-"), COALESCE(last_name,"-")) as employee_name')
                );
            },
        ])
        ->where('sub_institute_id', $sub_institute_id)
        ->where('id', $id)
        ->whereNull('deleted_at')
        ->first();
        
        if ($discliplinaryManagementModel) {
            // For single model, just add the properties directly
            $discliplinaryManagementModel->department_name = $discliplinaryManagementModel->departmentData->department_name ?? null;
            $discliplinaryManagementModel->employee_name = $discliplinaryManagementModel->employeeData->employee_name ?? null;
            
            // Remove the relationship objects
            unset($discliplinaryManagementModel->departmentData);
            unset($discliplinaryManagementModel->employeeData);
        }
        
        // Convert to array if needed
        $result = $discliplinaryManagementModel ? $discliplinaryManagementModel->toArray() : null;

        if(!empty($discliplinaryManagementModel) && isset($discliplinaryManagementModel->id)){
            $res['status_code'] = 1;
            $res['message'] = "Data Found";
            $res['data'] = $discliplinaryManagementModel;
        }

        return is_mobile($type, "settings/add_institute_detail", $res, "view");
    }

    public function update(Request $request,$id)
    {
        $type = $request->type;
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $sub_institute_id = $request->input('sub_institute_id');

        $res = [];
        $res['status_code'] = 0;
        $res['message'] = "Failed To Update Data";

        $insertData = $request->except('_token','type','token','user_id','method_field');
        $insertData['updated_by'] = $request->reported_by;
        $insertData['updated_at'] = now();

        $addData = discliplinaryManagementModel::where('id',$id)->update($insertData);

        if($addData){
            $res['status_code'] = 1;
            $res['message'] = "Data Updated Successfully";
        }

        return is_mobile($type, "settings/add_institute_detail", $res, "view");
    }

    public function destroy(Request $request,$id)
    {
        $type = $request->type;
        $token = $request->input('token');  // get token from input field 'token'

        // Check if token is provided
        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);

        // If token is invalid
        if (!$accessToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        // Validate required fields
        $validator = Validator::make($request->all(), [
            'sub_institute_id' => 'required',
        ]);

        // If validation fails
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
        }

        $sub_institute_id = $request->input('sub_institute_id');

        $res = [];
        $res['status_code'] = 0;
        $res['message'] = "Failed To Deleted Data";

        $addData = discliplinaryManagementModel::where('id',$id)->update(['deleted_at'=>now(),'deleted_by'=>$this->g2gActorId($request)]);

        if($addData){
            $res['status_code'] = 1;
            $res['message'] = "Data Deleted Successfully";
        }

        return is_mobile($type, "settings/add_institute_detail", $res, "view");
    }
}
