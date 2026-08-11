<?php

namespace App\Http\Controllers\leave;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Models\HRMS\hrmsDepartmentModel;
use App\Models\HRMS\HrmsHoliday;
use App\Models\HRMS\HrmsWeekday;
use Exception;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use App\Traits\Helpers;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class HolidayController extends Controller
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


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $type = $request->type;
        $sub_institute_id = session()->get('sub_institute_id');
        if($type=="API"){
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
       
            $sub_institute_id = $request->get('sub_institute_id');
        }

        $res['holidayList'] = HrmsHoliday::where('sub_institute_id',$sub_institute_id)->whereNull('deleted_at')->get();
        $res['weekDayList'] = HrmsWeekday::where('sub_institute_id',$sub_institute_id)->whereNull('deleted_at')->get();
        $res['status_code'] = 1;
        // return view('leave.holiday_master', compact('weekdays', 'departments'));
        return is_mobile($type, "leave.holiday_master", $res, "view");
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
                'user_id' => 'required',
                'holiday_name'  => 'required|string|max:255',
                'from_date'     => 'required|date',
                // 'to_date'    => 'required|date|after_or_equal:from_date',
                // 'day_type'   => 'required|in:full,half',
                'department'    => 'required|array',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
            }
       
            $sub_institute_id = $request->get('sub_institute_id');
            $user_id = $request->get('user_id');
            $insert = DB::table('hrms_holidays')->updateOrInsert(
                [
                    'from_date'        => $request->from_date,
                    'to_date'          => $request->from_date,
                    'sub_institute_id' => $sub_institute_id,
                    'department'       => implode(',', $request->department),
                ],
                [
                    'holiday_name' => $request->holiday_name,
                    'day_type'     => 'Full',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                    'created_by'   => $user_id,
                    'updated_by'   => $user_id,
                    'deleted_at'   => null,
                ]
            );

        $res['status_code'] = 0;
            $res['message'] = "Failed To Add !";

        if($insert){
            $res['status_code'] = 1;
            $res['message'] = "Added Successfully !";
        }

        return response()->json($res);

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
        //
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
                'user_id' => 'required',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
            }
       
            $sub_institute_id = $request->get('sub_institute_id');
            $user_id = $request->get('user_id');


        try {
            HrmsHoliday::whereIn('id', explode(',', $id))->update(['deleted_at'=>now(),'deleted_by'=>$user_id]);
            return response()->json(['status_code'=>1,'message'=>'Deleted Successfully']);
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }

    public function storeWeekdays(Request $request)
    {
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
                'user_id' => 'required',
                'monday' => 'required|in:full,half,weekend',
                'tuesday' => 'required|in:full,half,weekend',
                'wednesday' => 'required|in:full,half,weekend',
                'thursday' => 'required|in:full,half,weekend',
                'friday' => 'required|in:full,half,weekend',
                'saturday' => 'required|in:full,half,weekend',
                'sunday' => 'required|in:full,half,weekend',
            ]);

            // If validation fails
            if ($validator->fails()) {
                return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 400);
            }
        

           $weekdays = [
                'monday'    => $request->monday,
                'tuesday'   => $request->tuesday,
                'wednesday' => $request->wednesday,
                'thursday'  => $request->thursday,
                'friday'    => $request->friday,
                'saturday'  => $request->saturday,
                'sunday'    => $request->sunday,
            ];

            $status =0;
            
            foreach ($weekdays as $day => $dayType) {
                // Try to update first
                $checked = DB::table('hrms_weekdays')
                    ->where('day', $day)
                    ->where('sub_institute_id',$request->sub_institute_id)
                    ->whereNull('deleted_at')
                    ->first();

                if (isset($checked->id)) {
                      $updated = DB::table('hrms_weekdays')
                    ->where('day', $day)
                    ->where('sub_institute_id',$request->sub_institute_id)
                    ->update(['day_type' => $dayType,'updated_at'=>now(),'updated_by'=>$this->g2gActorId($request)]);
                    $status=2;
                } else {
                    DB::table('hrms_weekdays')->insert([
                        'day'      => $day,
                        'day_type' => $dayType,
                        'sub_institute_id' => $request->sub_institute_id,
                        'created_at'=>now(),
                        'created_by'=>$this->g2gActorId($request),
                    ]);
                    $status=1;
                }
            }

            // Fetch sorted result (assuming you want Monday -> Sunday order)
            $sorted = DB::table('hrms_weekdays')
                ->orderByRaw("FIELD(day, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
                ->get();

            $res['status_code'] = 0;
            $res['message'] = 'Failed to Add Data';
            if($status>0){
                $res['status_code'] = 1;
                $res['message'] = 'Added Successfully';
            }
            return response()->json($res);
    }
}
