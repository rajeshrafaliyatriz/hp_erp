<?php

namespace App\Http\Controllers\leave;

use App\Http\Controllers\Controller;
use App\Models\HrmsLeaveType;
use Exception;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use function App\Helpers\is_mobile;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LeaveTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $userId = session()->get('user_id');
        if ($type == "API") {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $sub_institute_id = $request->get('sub_institute_id');
            $userId = $request->get('user_id');

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                $res['status'] = 0;
                $res['message'] = $validator->messages()->first();
                return is_mobile($type, "hrms_inout_time.index", $res, "redirect");
            }
        }
        $res['LeaveTypeLists'] = HrmsLeaveType::where('sub_institute_id',$sub_institute_id)->whereNull('deleted_at')->get();
        return response()->json($res);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'leave_type_name' => 'required',
        ]);
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $userId = session()->get('user_id');
        if ($type == "API") {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $sub_institute_id = $request->get('sub_institute_id');
            $userId = $request->get('user_id');

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'user_id' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                $res['status'] = 0;
                $res['message'] = $validator->messages()->first();
                return is_mobile($type, "hrms_inout_time.index", $res, "redirect");
            }
        }
        
           $leaveId   = $request->leave_id;
            $leaveTypeId = $request->leave_type_id;
            $leaveType = $request->leave_type_name;
            $sortOrder = $request->sort_order;
            $status    = $request->status;

            // check if record exists
            $existingLeave = DB::table('hrms_leave_types')
                ->where(function ($q) use ($leaveId, $leaveType, $sub_institute_id) {
                    if (!empty($leaveId)) {
                        $q->where('id', $leaveId);
                    } else {
                        $q->where('leave_type', $leaveType)
                        ->where('sub_institute_id', $sub_institute_id);
                    }
                })
                ->first();

        $data = [
            'leave_type_id'     => $leaveTypeId,
            'leave_type'     => $leaveType,
            'sort_order'     => $sortOrder,
            'status'         => $status,
            'sub_institute_id' => $sub_institute_id,
            'created_by'     => $userId,
            'created_at'     => now(),
            'deleted_at'     => null,
        ];

        // If record exists → update, else insert
        if ($existingLeave) {
            DB::table('hrms_leave_types')
                ->where('id', $existingLeave->id)
                ->update($data);

            $message = 'Leave type updated successfully !!';
        } else {
            // Generate leave_type_id if needed

            DB::table('hrms_leave_types')->insert($data);

            $message = 'Leave type added successfully !!';
        }

        return response()->json(['message' => $message], 200);

        
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        try {
            $data = HrmsLeaveType::find($id);
            return response()->json(['data' => $data], 200);
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request,$id)
    {
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        $userId = session()->get('user_id');
        if ($type == "API") {
            $token = $request->input('token');  // get token from input field 'token'

            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Find the token in the database
            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            $sub_institute_id = $request->get('sub_institute_id');
            $userId = $request->get('user_id');

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required|numeric',
                'user_id' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                $res['status'] = 0;
                $res['message'] = $validator->messages()->first();
                return is_mobile($type, "hrms_inout_time.index", $res, "redirect");
            }
        }
       
            // HrmsLeaveType::find($id)->delete();
            HrmsLeaveType::where('id',$id)->update([
                'deleted_by' => $userId,
                'deleted_at' => now(),
            ]);
            return response()->json(['message' => 'Leave type deleted successfully !!'], 200);
       
    }
}
