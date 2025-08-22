<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Validator;
use DB;
use Illuminate\Support\Facades\Hash;
use function App\Helpers\is_mobile;

class authController extends Controller
{
    //
    public function index(Request $request)
    {
        $type = $request->type;
        // Validation
        $validator = Validator::make($request->all(), [
            'email' => 'required|string',
            'password' => 'required|string',
            'type' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' =>  $validator->errors()->first(),
            ], 422);
        }

        $email = $request->input('email');
        $password = $request->input('password');

        // Fetch user by email
        $user = tbluserModel::where('email', $email)->first();
        // echo "<pre>";print_r($user);exit;
        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
            'status_code' => 0,
            'message' => 'Invalid User Id And Password'
            ]);
        }

        $password = $user->password; // For further usage in the code
        $orgData = $user::with('organization')->find($user->sub_institute_id);
        $orgDetails =$orgData['organization'];
        $clientData = $user::with('client')->find($orgDetails->client_id);
        $clientDetails =$clientData['client'];
        $yearData = $user::with('yearData')->first();
        $yearDetails =$yearData['yearData'];
        $profileData = $user::with('userProfile')->find($user->id);
        $profileDetails =$profileData['userProfile'] ?? [];
        // echo "<pre>";print_r($profileDetails);exit;
        session()->put('client_id',$orgDetails);
        session()->put('is_admin',$clientDetails);
        session()->put('user_id',$user->id);
        session()->put('user_profile_id',$user->user_profile_id);
        session()->put('user_profile_name',$profileDetails->name);

        $sessionData = [
            'user_id' => $user->id,
            'user_name' => $user->first_name . ' ' . $user->last_name,
            'first_name'=>$user->first_name,
            'last_name'=>$user->last_name,
            'user_email' => $user->email,
            'user_image'=> $user->image,
            'user_profile_name'=>$profileDetails->name,
            'user_profile_id'=>$user->user_profile_id,
            'sub_institute_id'=> $user->sub_institute_id,
            'birthdate'=> $user->birthdate,
            'employee_no'=> $user->employee_no,
            'org_name'=> $orgDetails->SchoolName,
            'org_id'=> $orgDetails->id,
            'org_short_code'=> $orgDetails->ShortCode,
            'org_logo'=> $orgDetails->Logo,
            'org_type'=> $orgDetails->institute_type,
            'year_title'=>$yearDetails->title,
            'syear'=>$yearDetails->syear,
            'start_date'=>$yearDetails->start_data,
            'end_date'=>$yearDetails->end_date,
            'org_user'=> strtoupper(substr($user->first_name, 0, 1)) . strtoupper(substr($user->last_name, 0, 1)),
        ];
        // echo "<pre>";print_r($sessionData);exit;
        
        $rightsMenusIds = 0;
        if (!empty($user)) {
            //START FOR MULTI-INSTITUTE
            if ($user->sub_institute_id == 0 && $user->client_id != '' && $user->is_admin == 1) {
                $rightsQuery = DB::table('tbluser as u')
                    ->leftJoin('tblindividual_rights as i', function ($join) {
                        $join->on('u.id', '=', 'i.user_id')->on('u.sub_institute_id', '=', 'i.sub_institute_id');
                    })->leftJoin('tblgroupwise_rights as g', function ($join) {
                        $join->on('u.user_profile_id', '=', 'g.profile_id')->on('u.sub_institute_id', '=', 'g.sub_institute_id');
                    })->join('tblmenumaster as m', function ($join) use ($user) {
                        $join->whereRaw("(i.menu_id = m.id OR g.menu_id = m.id) AND FIND_IN_SET(" . $user->client_id . ",
                    m.client_id)");
                    })->selectRaw('GROUP_CONCAT(distinct m.id) AS MID')
                    ->whereIn('u.sub_institute_id', explode(',', $user->sub_institute_id))
                    ->where('u.status',1) 
                    ->where('u.id', $user->id)->get()->toArray();
                //END FOR MULTI-INSTITUTE
            } else {
                $rightsQuery = DB::table('tbluser as u')
                    ->leftJoin('tblindividual_rights as i', function ($join) {
                        $join->on('u.id', '=', 'i.user_id')->on('u.sub_institute_id', '=', 'i.sub_institute_id');
                    })->leftJoin('tblgroupwise_rights as g', function ($join) {
                        $join->on('u.user_profile_id', '=', 'g.profile_id')->on('u.sub_institute_id', '=', 'g.sub_institute_id');
                    })->join('tblmenumaster as m', function ($join) use ($user) {
                        $join->whereRaw("(i.menu_id = m.id OR g.menu_id = m.id) AND FIND_IN_SET(" . $user->sub_institute_id . ",
                    m.sub_institute_id)");
                    })->selectRaw('GROUP_CONCAT(distinct m.id) AS MID')
                    ->whereIn('u.sub_institute_id', explode(',', $user->sub_institute_id))
                    ->where('u.status',1) 
                    ->where('u.id', $user->id)->get()->toArray();
            }
            $rightsQuery = array_map(function ($value) {
                return (array)$value;
            }, $rightsQuery);
            if (isset($rightsQuery['0']['MID'])) {
                $rightsMenusIds = $rightsQuery['0']['MID'];
            }
        }
        // echo "<pre>";print_r($rightsMenusIds);exit;
        if (empty($user)) {
            $res['status_code'] = 0;
            $res['message'] = "Invalid User Id And Password";

             return is_mobile($type, 'login', $res, "view");
        } else {
            if ($rightsMenusIds == 0) { //Check user Rights
                $res['status_code'] = 0;
                $res['message'] = "Please Contact Administrator For ERP Rights";

                return is_mobile($type, 'login', $res, "view");
            } else {
                
                $userprofiledetails = DB::table('tbluserprofilemaster')->where(['id' => $user['user_profile_id']])->get()->toArray();
                $request->session()->put('user_profile_id', $user['user_profile_id']);
                //START FOR MULTI-INSTITUTE
                if ($user['is_admin'] == 1 || $user['is_admin']==2) {
                    if($user['is_admin']==2){
                        $schoolData = DB::table('tblclient')->get()->toArray();
                    }else{
                        $schoolData = DB::table('tblclient')->where(['id' => $user['client_id']])->get()->toArray();
                    }
                     
                    $schoolData = json_decode(json_encode($schoolData), true);
                    $ShortCode = $schoolData[0]->short_code;
                    $SchoolName = $schoolData[0]->client_name;
                    $Logo = $schoolData[0]->logo;
                    if($user['is_admin']==2){
                        $getMultiInst = DB::table('tblclient')->get()->toArray();
                    }else{
                        $getMultiInst = DB::table('tblclient')->where(['id' => $user['client_id']])->get()->toArray();
                    }
                    if (isset($getMultiInst['0']->multischool)) {
                        $request->session()->put('multiSchool', $getMultiInst['0']->multischool);
                    }
                    if($user['is_admin']==2){
                        $schools = DB::table('school_setup')->whereIn('client_id',[2,11,20,34,81])->get()->toArray();
                    }else{
                        $schools = DB::table('school_setup')->where(['client_id' => $user['client_id']])->get()->toArray();
                    }
                    // echo "<pre>";print_r($schools);exit;    /
                    $client_sub_institute_id = '';
                    if (count($schools) > 0) {
                        $client_sub_institute_id = $schools[0]['Id'];
                        $request->session()->put('syear', $schools[0]['syear']);
                    }
                    if($user['is_admin']==2){
                        $getTermId = DB::table('academic_year')->whereIn('sub_institute_id',[254,195,47,72,1])
                        ->where('syear',session()->get('syear'))
                        ->get()->toArray();
                    
                    }else{
                        $getTermId = DB::table('academic_year')->where(['sub_institute_id' => $client_sub_institute_id])
                            ->whereRaw('"'.date('Y-m-d').'" '.'between start_date and end_date')
                            ->get()->toArray();
                        // when academic end date
                        if(empty($getTermId)){
                            $res['status_code'] = 0;
                            $res['message'] = "Academic Term Date Expired";
                            // return is_mobile($type, "login", $res, "view");
                            return is_mobile($type, 'login', $res, "view");
                        }
                        $request->session()->put('syear', $getTermId[0]->syear);

                    }
                    $given_hrms_rights = '';
                    $getAcademicTerms = $getAcademicYear = array();
                    if($user['is_admin']==2){
                        $getInstitutes = DB::table('school_setup as ss')->whereIn('id',[254,195,47,72,1])->get()->toArray();
                    }else{
                        $getInstitutes = DB::table('school_setup')->where('client_id',
                        $user['client_id'])->get()->toArray();
                    }
                    
                }//END FOR MULTI-INSTITUTE
                else {
                    $schoolData = DB::table('school_setup')->where(['id' => $user['sub_institute_id']])->get()->toArray();
                    // echo "<pre>";print_r($schoolData);exit;
                    $ShortCode = $schoolData[0]->ShortCode;
                    $SchoolName = $schoolData[0]->SchoolName;
                    $institute_type = $schoolData[0]->institute_type;                        
                    $Logo = $schoolData[0]->Logo;
                    // return $schoolData;exit;
                    if (isset($schoolData[0]->client_id)) {
                        $getMultiInst = DB::table('tblclient')->where(['id' => $schoolData[0]->client_id])->get()->toArray();
                        if (isset($getMultiInst['0']->multischool)) {
                            $request->session()->put('multiSchool', $getMultiInst['0']->multischool);
                        }
                    }
                    // DB::enableQueryLog();
                    $getTermId = DB::table('academic_year')->where(['sub_institute_id' => $user['sub_institute_id']])
                        ->whereRaw('"'.date('Y-m-d').'" '.'between start_date and end_date')
                        ->get()->toArray();
                        // dd(DB::getQueryLog($getTermId));
                        // echo "<pre>";print_r($getTermId);exit;
                        // when academic end date
                    if(empty($getTermId)){
                        $res['status_code'] = 0;
                        $res['message'] = "Academic Term Date Expired";
                        // return is_mobile($type, "login", $res, "view");
                        return is_mobile($type, 'login', $res, "view");
                    }
                        
                    $hrms_rights = DB::table('school_setup as s')->join('tblclient as c', function ($join) {
                        $join->on('c.id','=', 's.client_id');
                    })->selectRaw('if(db_hrms is null,0,1) as rights')
                        ->where('s.Id', $user['sub_institute_id'])->get()->toArray();
                    $given_hrms_rights = $hrms_rights[0]->rights;

                    $getAcademicTerms = DB::table('academic_year')
                        ->where('sub_institute_id', $user['sub_institute_id'])
                        ->where('syear', $getTermId[0]->syear)
                        ->orderBy('sort_order')
                        ->get()->toArray();

                    $getAcademicYear = DB::table('academic_year')
                        ->select('syear', DB::raw('MAX(id) as id')) // Adjust columns as needed
                        ->where('sub_institute_id', $user['sub_institute_id'])
                        ->groupBy('syear')
                        ->get()
                        ->toArray();
                }
                session()->put($sessionData);
                // return session()->all();
                $sessionData['APP_URL'] = env('APP_URL');
                $token = $user->createToken('api-token')->plainTextToken;
                $sessionData['token'] = $token;

                $res['status'] = 1;
                $res['message'] = "User Successfully Login";
                $user['user_profile'] = $userprofiledetails[0]->name;
                $res['data'] = $user;
                $res['academicTerms'] = $getAcademicTerms;
                $res['academicYears'] = $getAcademicYear;
                $res['sessionData'] = $sessionData;
                // Get server hostname and IP address
                $hostname = gethostname();
                $ip = gethostbyname($hostname);
                
                // Check if multi-login is enabled
                // $check_multilogin = DB::table('general_data')
                //     ->where(['fieldname' => 'multi_login', 'sub_institute_id' => $user['sub_institute_id']])
                //     ->value('fieldvalue');

                // // If multi-login is disabled, update user's login IP
                // if ($check_multilogin === "No") {
                //     DB::table('tbluser')
                //         ->where(['sub_institute_id' => $user['sub_institute_id'], 'id' => $user['id']])
                //         ->update(["login_ip" => $user_token]);
                // }
                // echo "<pre>";print_r(session()->all());exit;
               return is_mobile($type, 'login', $res, "view");
            }
        }
    }

    public function menu_lists(Request $request)
    {
        return "hello";
    }
}
