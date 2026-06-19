<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\auth\tbluserModel;
use Google\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoogleAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $client = new Client([
            'client_id' => env('GOOGLE_CLIENT_ID'),
        ]);

        $payload = $client->verifyIdToken($request->token);

        if (! $payload || empty($payload['email'])) {
            return response()->json([
                'success' => false,
                'status' => 0,
                'message' => 'Invalid Google token',
            ], 401);
        }

        $email = strtolower(trim($payload['email']));

        $user = tbluserModel::with(['organization', 'client', 'yearData', 'userProfile'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'status' => 0,
                'message' => 'No active ERP user found for this Google account',
            ], 404);
        }

        return $this->buildLoginResponse($user);
    }

    private function buildLoginResponse(tbluserModel $user)
    {
        $orgDetails = $user->organization;
        $clientDetails = $orgDetails->client ?? null;
        $yearDetails = $user->yearData;
        $profileDetails = $user->userProfile ?? null;

        session()->put('client_id', $orgDetails);
        session()->put('is_admin', $clientDetails);
        session()->put('user_id', $user->id);
        session()->put('user_profile_id', $user->user_profile_id);
        session()->put('user_profile_name', $profileDetails->name ?? null);

        $sessionData = [
            'user_id' => $user->id,
            'user_name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_email' => $user->email,
            'user_image' => $user->image,
            'user_profile_name' => $profileDetails->name ?? null,
            'user_profile_id' => $user->user_profile_id,
            'sub_institute_id' => $user->sub_institute_id,
            'birthdate' => $user->birthdate,
            'employee_no' => $user->employee_no,
            'org_name' => $orgDetails->SchoolName ?? null,
            'org_id' => $orgDetails->id ?? null,
            'org_short_code' => $orgDetails->ShortCode ?? null,
            'org_logo' => $orgDetails->Logo ?? null,
            'org_type' => $orgDetails->institute_type ?? null,
            'year_title' => $yearDetails->title ?? null,
            'syear' => $yearDetails->syear ?? null,
            'start_date' => $yearDetails->start_data ?? null,
            'end_date' => $yearDetails->end_date ?? null,
            'org_user' => strtoupper(substr($user->first_name ?? '', 0, 1)).strtoupper(substr($user->last_name ?? '', 0, 1)),
        ];

        $getAcademicTerms = [];
        $getAcademicYear = [];

        if ($user->is_admin == 1 || $user->is_admin == 2) {
            if ($user->is_admin == 2) {
                $schoolData = DB::table('tblclient')->get()->toArray();
            } else {
                $schoolData = DB::table('tblclient')->where(['id' => $user->client_id])->get()->toArray();
            }

            if ($user->is_admin == 2) {
                $getMultiInst = DB::table('tblclient')->get()->toArray();
            } else {
                $getMultiInst = DB::table('tblclient')->where(['id' => $user->client_id])->get()->toArray();
            }

            if (isset($getMultiInst[0]->multischool)) {
                session()->put('multiSchool', $getMultiInst[0]->multischool);
            }

            if ($user->is_admin == 2) {
                $schools = DB::table('school_setup')->whereIn('client_id', [2, 11, 20, 34, 81])->get()->toArray();
            } else {
                $schools = DB::table('school_setup')->where(['client_id' => $user->client_id])->get()->toArray();
            }

            $client_sub_institute_id = '';
            if (count($schools) > 0) {
                $client_sub_institute_id = $schools[0]->id ?? '';
                session()->put('syear', $schools[0]->syear ?? '');
            }
            if (empty($client_sub_institute_id) && !empty($user->sub_institute_id)) {
                $client_sub_institute_id = $user->sub_institute_id;
                $fallbackSchool = DB::table('school_setup')->where('id', $user->sub_institute_id)->first();
                if ($fallbackSchool) {
                    session()->put('syear', $fallbackSchool->syear ?? '');
                }
            }

            if ($user->is_admin == 2) {
                $getTermId = DB::table('academic_year')
                    ->whereIn('sub_institute_id', [254, 195, 47, 72, 1])
                    ->where('syear', session()->get('syear'))
                    ->get()
                    ->toArray();
            } else {
                $getTermId = DB::table('academic_year')
                    ->where(['sub_institute_id' => $client_sub_institute_id])
                    ->whereRaw('? between start_date and end_date', [date('Y-m-d')])
                    ->get()
                    ->toArray();

                if (empty($getTermId)) {
                    return response()->json([
                        'success' => false,
                        'status' => 0,
                        'message' => 'Academic Term Date Expired',
                    ]);
                }

                session()->put('syear', $getTermId[0]->syear ?? null);
            }

            if ($user->is_admin == 2) {
                $getInstitutes = DB::table('school_setup')->whereIn('id', [254, 195, 47, 72, 1])->get()->toArray();
            } else {
                $getInstitutes = DB::table('school_setup')->where('client_id', $user->client_id)->get()->toArray();
            }
        } else {
            $schoolData = DB::table('school_setup')->where(['id' => $user->sub_institute_id])->get()->toArray();

            if (isset($schoolData[0]->client_id)) {
                $getMultiInst = DB::table('tblclient')->where(['id' => $schoolData[0]->client_id])->get()->toArray();
                if (isset($getMultiInst[0]->multischool)) {
                    session()->put('multiSchool', $getMultiInst[0]->multischool);
                }
            }

            $getTermId = DB::table('academic_year')
                ->where(['sub_institute_id' => $user->sub_institute_id])
                ->whereRaw('? between start_date and end_date', [date('Y-m-d')])
                ->get()
                ->toArray();

            if (empty($getTermId)) {
                return response()->json([
                    'success' => false,
                    'status' => 0,
                    'message' => 'Academic Term Date Expired',
                ]);
            }

            $getAcademicTerms = DB::table('academic_year')
                ->where('sub_institute_id', $user->sub_institute_id)
                ->where('syear', $getTermId[0]->syear)
                ->orderBy('sort_order')
                ->get()
                ->toArray();

            $getAcademicYear = DB::table('academic_year')
                ->select('syear', DB::raw('MAX(id) as id'))
                ->where('sub_institute_id', $user->sub_institute_id)
                ->groupBy('syear')
                ->get()
                ->toArray();
        }

        session()->put($sessionData);

        $token = $user->createToken('api-token')->plainTextToken;
        $sessionData['APP_URL'] = env('APP_URL');
        $sessionData['token'] = $token;

        $userprofiledetails = DB::table('tbluserprofilemaster')
            ->where(['id' => $user->user_profile_id])
            ->get()
            ->toArray();

        $responseUser = $user->toArray();
        $responseUser['user_profile'] = $userprofiledetails[0]->name ?? null;

        return response()->json([
            'success' => true,
            'status' => 1,
            'message' => 'User Successfully Login',
            'user' => $responseUser,
            'token' => $token,
            'data' => $responseUser,
            'academicTerms' => $getAcademicTerms,
            'academicYears' => $getAcademicYear,
            'sessionData' => $sessionData,
        ]);
    }
}
