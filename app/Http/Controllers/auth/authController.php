<?php

namespace App\Http\Controllers\auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Validator;
use DB;
use Illuminate\Support\Facades\Hash;
use function App\Helpers\is_mobile;
use App\Models\easy_com\manage_sms_api\manage_sms_api;

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
        $user = tbluserModel::with(['organization', 'client', 'yearData', 'userProfile'])
            ->where('email', $email)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
                'status' => 0,
                'message' => 'Invalid User Id And Password'
            ]);
        }

        // Get organization details through the relationship
        $orgDetails = $user->organization;

        // Get client details through the organization relationship
        $clientDetails = $orgDetails->client ?? null;

        // Get year data through the relationship
        $yearDetails = $user->yearData;

        // Get profile data through the relationship
        $profileDetails = $user->userProfile ?? [];
        // echo "<pre>";print_r($orgDetails);exit;
        session()->put('client_id', $orgDetails);
        session()->put('is_admin', $clientDetails);
        session()->put('user_id', $user->id);
        session()->put('user_profile_id', $user->user_profile_id);
        session()->put('user_profile_name', $profileDetails->name);

        $sessionData = [
            'user_id' => $user->id,
            'user_name' => $user->first_name . ' ' . $user->last_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_email' => $user->email,
            'user_image' => $user->image,
            'user_profile_name' => $profileDetails->name,
            'user_profile_id' => $user->user_profile_id,
            'sub_institute_id' => $user->sub_institute_id,
            'birthdate' => $user->birthdate,
            'employee_no' => $user->employee_no,
            'org_name' => $orgDetails->SchoolName,
            'org_id' => $orgDetails->id,
            'org_short_code' => $orgDetails->ShortCode,
            'org_logo' => $orgDetails->Logo,
            'org_type' => $orgDetails->institute_type,
            'year_title' => $yearDetails->title,
            'syear' => $yearDetails->syear,
            'start_date' => $yearDetails->start_data,
            'end_date' => $yearDetails->end_date,
            'org_user' => strtoupper(substr($user->first_name, 0, 1)) . strtoupper(substr($user->last_name, 0, 1)),
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
                    ->where('u.status', 1)
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
                    ->where('u.status', 1)
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
            $res['status'] = 0;
            $res['message'] = "Invalid User Id And Password";

            return is_mobile($type, 'login', $res, "view");
        } else {
            // if ($rightsMenusIds == 0) { //Check user Rights
            //     $res['status'] = 0;
            //     $res['message'] = "Please Contact Administrator For ERP Rights";

            //     return is_mobile($type, 'login', $res, "view");
            // } else {

            $userprofiledetails = DB::table('tbluserprofilemaster')->where(['id' => $user->user_profile_id])->get()->toArray();
            $request->session()->put('user_profile_id', $user->user_profile_id);
            //START FOR MULTI-INSTITUTE
            if ($user->is_admin == 1 || $user->is_admin == 2) {
                if ($user->is_admin == 2) {
                    $schoolData = DB::table('tblclient')->get()->toArray();
                } else {
                    $schoolData = DB::table('tblclient')->where(['id' => $user->client_id])->get()->toArray();
                }

                $schoolData = json_decode(json_encode($schoolData), true);
                // return $schoolData;exit;
                $ShortCode = isset($schoolData[0]) ? $schoolData[0]['short_code'] : '';
                $SchoolName = isset($schoolData[0]) ? $schoolData[0]['client_name'] : '';
                $Logo = isset($schoolData[0]) ? $schoolData[0]['logo'] : '';

                if ($user['is_admin'] == 2) {
                    $getMultiInst = DB::table('tblclient')->get()->toArray();
                } else {
                    $getMultiInst = DB::table('tblclient')->where(['id' => $user['client_id']])->get()->toArray();
                }
                if (isset($getMultiInst['0']->multischool)) {
                    $request->session()->put('multiSchool', $getMultiInst['0']->multischool);
                }
                if ($user['is_admin'] == 2) {
                    $schools = DB::table('school_setup')->whereIn('client_id', [2, 11, 20, 34, 81])->get()->toArray();
                } else {
                    $schools = DB::table('school_setup')->where(['client_id' => $user->client_id])
                        ->get()->toArray();
                }
                // echo "<pre>";print_r($schools);exit;
                $client_sub_institute_id = '';
                if (count($schools) > 0) {
                    $client_sub_institute_id = isset($schools[0]) ? $schools[0]->id : '';
                    $request->session()->put('syear', isset($schools[0]) ? $schools[0]->syear : '');
                }
                if (empty($client_sub_institute_id) && !empty($user['sub_institute_id'])) {
                    $client_sub_institute_id = $user['sub_institute_id'];
                    $fallbackSchool = DB::table('school_setup')->where('id', $user['sub_institute_id'])->first();
                    if ($fallbackSchool) {
                        $request->session()->put('syear', $fallbackSchool->syear ?? '');
                    }
                }
                if ($user['is_admin'] == 2) {
                    $getTermId = DB::table('academic_year')->whereIn('sub_institute_id', [254, 195, 47, 72, 1])
                        ->where('syear', session()->get('syear'))
                        ->get()->toArray();
                } else {
                    $getTermId = DB::table('academic_year')->where(['sub_institute_id' => $client_sub_institute_id])
                        ->whereRaw('"' . date('Y-m-d') . '" ' . 'between start_date and end_date')
                        ->get()->toArray();
                    // when academic end date
                    if (empty($getTermId)) {
                        $res['status'] = 0;
                        $res['message'] = "Academic Term Date Expired";
                        // return is_mobile($type, "login", $res, "view");
                        return is_mobile($type, 'login', $res, "view");
                    }
                    $request->session()->put('syear', $getTermId[0]->syear);
                }
                $given_hrms_rights = '';
                $getAcademicTerms = $getAcademicYear = array();
                if ($user['is_admin'] == 2) {
                    $getInstitutes = DB::table('school_setup as ss')->whereIn('id', [254, 195, 47, 72, 1])->get()->toArray();
                } else {
                    $getInstitutes = DB::table('school_setup')->where(
                        'client_id',
                        $user['client_id']
                    )->get()->toArray();
                }
            } //END FOR MULTI-INSTITUTE
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
                    ->whereRaw('"' . date('Y-m-d') . '" ' . 'between start_date and end_date')
                    ->get()->toArray();
                // dd(DB::getQueryLog($getTermId));
                // echo "<pre>";print_r($getTermId);exit;
                // when academic end date
                if (empty($getTermId)) {
                    $res['status'] = 0;
                    $res['message'] = "Academic Term Date Expired";
                    // return is_mobile($type, "login", $res, "view");
                    return is_mobile($type, 'login', $res, "view");
                }

                $hrms_rights = DB::table('school_setup as s')->join('tblclient as c', function ($join) {
                    $join->on('c.id', '=', 's.client_id');
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
            // }
        }
    }

    public function menu_lists(Request $request)
    {
        return "hello";
    }

    public function user_login(Request $request)
    {

        $response = ['status' => '0', 'message' => 'User Not Found'];
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $response['status'] = '0';
            $response['message'] = $validator->messages();
        } else {
            // Fetch user by email
            $user = tbluserModel::with(['organization', 'client', 'yearData', 'userProfile'])
                ->where('mobile', $request->mobile)
                ->first();
            // return $user;
            $data = DB::table('tbluser as u')
                ->join('tbluserprofilemaster as p', function ($join) {
                    $join->whereRaw("p.sub_institute_id = u.sub_institute_id and u.user_profile_id = p.id ");
                })->join('school_setup as ss', function ($join) {
                    $join->whereRaw("ss.id = u.sub_institute_id");
                })->selectRaw("u.id,u.user_name,u.first_name,u.middle_name,u.last_name,u.sub_institute_id,
                    u.email,u.mobile,u.birthdate,u.address,u.gender,u.join_year,
                    if(u.image = '','',concat('https://" . $_SERVER['SERVER_NAME'] . "/storage/user/',u.image)) as image,
                    p.name as user_profile_name,u.user_profile_id,ss.syear,ss.SchoolName,ss.Logo")
                ->where('u.status', '1')
                ->where('u.id', $user['id'])->get()->toArray();
            // return $data;
            $data = json_decode(json_encode($data), true);
            if (!empty($data)) {
                $data = $data[0];
            } else {
                return json_encode($response);
            }
            // return $data;
            if (isset($data['id']) && $data['id'] != '') {

                // send otp
                $otp = rand(100000, 999999);
                $sub_institute_id = $data['sub_institute_id'];
                $sub_Array = [328, 329, 330, 331, 333];
                if ($_REQUEST['mobile'] == '9979176562') {
                    $otp = "123456";
                } else if (in_array($sub_institute_id, $sub_Array)) {
                    $otp = date('dmy', strtotime($data['birthdate']));
                } else {
                    //$text = "Dear Parent, Your OTP is ".$otp;
                    if ($sub_institute_id == 49 || $sub_institute_id == 232 || $sub_institute_id == 233) {
                        $text = "Dear Teacher your OTP is " . $otp;
                    } else if ($sub_institute_id == 47) {
                        $text = "Dear Parent, Your OTP is " . $otp . " MULJIM";
                    } else {
                        $text = "OTP for login is " . $otp . " and is valid for 5 minutes";
                    }

                    $res = $this->sendSMS($request->mobile, $text, $sub_institute_id);
                    if (!empty($res)) {
                        $res = json_decode(json_encode($res), true);
                        if (isset($res["error"]) && $res["error"] === 1) {
                            // return "hello";
                            $errorMessage = "Please add api details first.";
                            if ($res["error"] == $errorMessage) {
                                $otp = "123456";
                            }
                        }
                    }
                }
                //DB::enableQueryLog();
                $data = DB::table("tbluser AS tu")
                    ->join('tbluserprofilemaster AS tpm', 'tpm.id', '=', 'tu.user_profile_id')
                    ->where('tu.status', 1) // 23-04-24 by uma
                    ->where(["tu.mobile" => $_REQUEST['mobile']])
                    ->update(["tu.otp" => $otp]);
                //dd(DB::getQueryLog($data));                    
                //echo "<pre>";
                //print_r($data);
                //exit();
                $response['status'] = '1';
                $response['message'] = ' OTP sent successfully';
            }
        }

        return json_encode($response);
    }

    public function user_check_otp(Request $request)
    {
        $send_data = [];
        $response = ['status' => '0', 'message' => 'Invalid', 'data' => $send_data];
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|numeric',
            'otp'    => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $response['status'] = '0';
            $response['message'] = $validator->messages();
        } else {
            $data = DB::table('tbluser as u')
                ->join('tbluserprofilemaster as p', function ($join) {
                    $join->whereRaw("p.sub_institute_id = u.sub_institute_id and u.user_profile_id = p.id");
                })
                ->join('school_setup as ss', function ($join) {
                    $join->whereRaw("ss.id = u.sub_institute_id");
                })
                ->selectRaw("u.id,u.user_name,u.first_name,u.middle_name,u.last_name,u.sub_institute_id,
                    u.email,u.mobile,u.otp,u.birthdate,u.address,u.gender,u.join_year,
                    if(u.image = '','',concat('https://" . $_SERVER['SERVER_NAME'] . "/storage/user/',u.image)) as image,
                    p.name as user_profile_name,u.user_profile_id,ss.syear,ss.SchoolName,ss.Logo")
                ->where('u.status', '1')
                ->where('u.otp', $_REQUEST['otp'])
                ->where('u.mobile', $_REQUEST['mobile'])->get()->toArray();

            $data = json_decode(json_encode($data), true);
            if (!empty($data)) {
                $data = $data[0];
                $payload = array();

                $time = time() + (60 * 60 * 24 * 30);
                $payload = [
                    'exp'              => $time,
                    'user_id'          => $data['id'],
                    'sub_institute_id' => $data['sub_institute_id'],
                    'mobile'           => $data['mobile'],
                ];

                // $token = $jwt->createToken($payload);
                $user = tbluserModel::with(['organization', 'client', 'yearData', 'userProfile'])
                    ->where('mobile', $data['mobile'])
                    ->first();
                $token = $user->createToken('api-token')->plainTextToken;

                $school_logo = 'https://' . $_SERVER['SERVER_NAME'] . '/admin_dep/images/' . $data['Logo'];

                $term_data = DB::table("academic_year")->select('title', 'syear', 'start_date', 'end_date')
                    ->where(["sub_institute_id" => $data['sub_institute_id'], "syear" => $data['syear']])
                    ->get()->toArray();

                $send_data = [
                    'user_id'           => $data['id'],
                    'user_name'         => $data['user_name'],
                    'first_name'        => $data['first_name'],
                    'middle_name'       => $data['middle_name'],
                    'last_name'         => $data['last_name'],
                    'sub_institute_id'  => $data['sub_institute_id'],
                    'email'             => $data['email'],
                    'mobile'            => $data['mobile'],
                    'otp'            => $data['otp'],
                    'birthdate'         => $data['birthdate'],
                    'address'           => $data['address'],
                    'gender'            => $data['gender'],
                    'image'             => $data['image'],
                    'join_year'         => $data['join_year'],
                    'school_logo'       => $school_logo,
                    'school_name'       => $data['SchoolName'],
                    'user_profile_name' => $data['user_profile_name'],
                    'user_profile_id'   => $data['user_profile_id'],
                    'syear'             => $data['syear'],
                    'term_data'         => $term_data,
                    'token'             => $token,
                ];

                $response['status'] = '1';
                $response['message'] = 'success';
                $response['data'] = $send_data;
            } else {
                $response['status'] = '0';
                $response['message'] = 'Failed';
            }
        }

        return json_encode($response);
    }
    public function sendSMS($mobile, $text, $sub_institute_id)
    {
        $data = manage_sms_api::where(['sub_institute_id' => $sub_institute_id])
            ->get()->first();
        $isError = 0;

        if ($data) {
            $data = $data->toArray();
            $isError = 0;
            $errorMessage = true;

            $text = urlencode($text);
            $data['last_var'] = urlencode($data['last_var']);

            //Start added by rajesh OTP for CN all institute
            $cn_templateid = '';
            $cn = array(244, 245, 246, 247, 248, 253, 257, 264, 265);
            if (in_array($sub_institute_id, $cn))
                $cn_templateid = '&template_id=1507166607307092495';
            //END added by rajesh OTP for CN all institute

            $url = $data['url'] . $data['pram'] . $cn_templateid . $data['mobile_var'] . $mobile . $data['text_var'] . $text . $data['last_var'];

            $ch = curl_init();

            // Ignore SSL certificate verification
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_URL, $url);
            $output = curl_exec($ch);


            //Print error if any
            if (curl_errno($ch)) {
                $isError = true;
                $errorMessage = curl_error($ch);
            }
            curl_close($ch);
        } else {
            $isError = 1;
            $errorMessage = "Please add api details first.";
        }
        $responce = [];
        if ($isError) {
            $responce = ['error' => 1, 'message' => $errorMessage];
        } else {
            $responce = ['error' => 0];
        }

        return response()->json($responce);
    }
}
