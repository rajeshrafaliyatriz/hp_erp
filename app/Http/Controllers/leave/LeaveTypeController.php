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
        
        try {
            $objLeave = HrmsLeaveType::find($request->leave_id) ?? HrmsLeaveType::firstOrNew(['leave_type' => $request->leave_type_name, 'sub_institute_id' => $sub_institute_id]);
            $objLeave->leave_type_id = $objLeave->leave_type_id ?? $objLeave->setLeaveTypeId($sub_institute_id);
            $objLeave->leave_type = $request->leave_type_name;
            $objLeave->sort_order = $request->sort_order;
            $objLeave->status = $request->status;
            $objLeave->sub_institute_id = $sub_institute_id;
            $objLeave->created_by = $userId;
            $objLeave->created_at = now();
            $objLeave->deleted_at = null;

            if ($objLeave->save()) {
                return response()->json(['message' => 'Leave type added successfully !!'], 200);
            }
            return response()->json(['message' => 'Something went wrong !!'], 500);
        } catch (Exception $e) {
            return response()->json($e->getMessage());
        }
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
        try {
            // HrmsLeaveType::find($id)->delete();
            HrmsLeaveType::find($id)->update([
                'deleted_by' => $userId,
                'deleted_at' => now(),
            ]);
            return response()->json(['message' => 'Leave type deleted successfully !!'], 200);
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }
}
