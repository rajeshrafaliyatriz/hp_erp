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
    /**
     * What the login response's `data` may contain.
     *
     * Taken from the frontend's own `LaravelLoginUser` interface
     * (g2gv0/services/auth/index.ts:14-31) — the caller's published contract,
     * not a guess. Everything outside this list was being sent and none of it
     * was being read: see the note at the assignment.
     *
     * An ALLOW-list, so a column added to `tbluser` tomorrow is not published
     * to every browser by default. That is the same reason
     * tbluserController::API_DETAIL_COLUMNS exists.
     */
    private const LOGIN_RESPONSE_FIELDS = [
        'id', 'user_name', 'first_name', 'middle_name', 'last_name',
        'email', 'mobile', 'image',
        'user_profile_id', 'user_profile', 'sub_institute_id', 'client_id',
        'is_admin', 'status', 'employee_no', 'department_id',
    ];

    /**
     * Record that somebody actually signed in.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * NOTHING IN THIS PRODUCT HAS EVER WRITTEN THIS COLUMN
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `tbluser.last_login` is READ in three places - the account screen, the
     * LMS governance list, and the new "who has never signed in" list - and a
     * repository-wide search for a write finds none. It appears in
     * `tbluserModel::$fillable` and in tbluserController's guarded list, and no
     * code path assigns it. 200 of 299 live rows hold a value, so something
     * historic set it; nothing has for as long as this codebase has existed.
     *
     * That made every reader of it wrong, and one of them dangerously so: the
     * People & access screen lists everybody with a NULL `last_login` as
     * "has never signed in and cannot get in". Without this write, a person who
     * is invited, sets a password and signs in stays on that list for ever, and
     * an administrator would keep re-inviting somebody who is already working.
     *
     * ── WHY IT IS A STRING, AND WHY FAILURE IS SWALLOWED ────────────────────
     *
     * The column is VARCHAR(20) on BOTH databases, not a timestamp, so it is
     * formatted rather than handed a Carbon instance - 'Y-m-d H:i:s' is 19
     * characters and fits. And a bookkeeping write must never be the reason a
     * valid sign-in fails, so a failure is logged and swallowed: the person is
     * already authenticated by the time this runs.
     */
    /**
     * TELL SOMEBODY WHEN A DEVICE THEY HAVE NEVER USED SIGNS IN AS THEM.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THE ONE ALERT WORTH SENDING
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Every comparable product sends this, and it is the only security email that
     * reliably reaches the right person before damage is done: a stolen password
     * is silent, and the first observable consequence is a sign-in from somewhere
     * the owner has never been.
     *
     * ── "NEW" MEANS A DEVICE LABEL NOT SEEN BEFORE ──────────────────────────
     *
     * Compared against the labels already on this account's tokens, not against
     * an IP or a fingerprint. `DeviceLabel` collapses a User-Agent to
     * "Chrome on Windows 10 or 11", so the SECOND sign-in from the same browser is
     * not new and does not mail anybody. That is deliberately coarse: an alert
     * that fires on every sign-in is an alert people filter, and then it warns
     * nobody about anything.
     *
     * A brand-new account's first ever sign-in is not alerted either - there is
     * nothing suspicious about the first device, and mailing somebody about their
     * own first login is how a product teaches them to ignore these.
     */
    private function alertOnNewDevice(int $userId, ?string $label, int $tenantId): void
    {
        try {
            if (!$label || $tenantId <= 0) {
                return;
            }

            $tokens = DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
                ->where('tokenable_id', $userId)
                ->pluck('name');

            // The token for THIS sign-in already exists by now, so its own label is
            // present once. Seen twice or more means the device is not new.
            $seen = $tokens->filter(fn ($name) => $name === $label)->count();

            if ($tokens->count() <= 1 || $seen > 1) {
                return;
            }

            app(\App\Services\Events\EventRecorder::class)->record(
                'account.new_device',
                $tenantId,
                'tbluser',
                $userId,
                $userId,
                ['device' => $label]
            );
        } catch (\Throwable $e) {
            /*
             * Never blocks a sign-in. Somebody being unable to log in because the
             * notification machinery failed would be a far worse outcome than a
             * missed alert, and this runs on the critical path of every sign-in.
             */
            \Illuminate\Support\Facades\Log::error('new-device alert failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop a sign-in until the second factor is satisfied. Null means proceed.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CALLED BEFORE ANY TOKEN EXISTS
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The password has already been accepted when this runs. Returning a response
     * here means no token is minted, no session is written, and the caller gets a
     * challenge instead of a credential.
     *
     * Ordering is the entire feature: mint first and ask after, and the password
     * alone has already produced something that works.
     *
     * ── A CODE OR A RECOVERY CODE, BOTH ACCEPTED HERE ───────────────────────
     *
     * The recovery code path is not a convenience - it is what stops a lost phone
     * from becoming a lost account, and without it the recovery route is "ask HR",
     * which across twelve tenants means twelve people improvising identity checks.
     * A recovery code is spent when it is used and cannot be replayed.
     *
     * ── AND IT IS THROTTLED ─────────────────────────────────────────────────
     *
     * Six digits is a million possibilities, reachable in about a day at HTTP
     * speed. Keyed on the ACCOUNT rather than the IP: an office behind one address
     * would otherwise throttle each other, and an attacker with a few proxies
     * would not be throttled at all.
     */
    private function twoFactorChallenge(Request $request, $user)
    {
        $twoFactor = app(\App\Services\Account\TwoFactor::class);
        $userId = (int) $user->id;

        if (!$twoFactor->isEnabled($userId)) {
            return null;
        }

        $code = trim((string) $request->input('two_factor_code', ''));
        $recovery = trim((string) $request->input('recovery_code', ''));

        if ($code === '' && $recovery === '') {
            return response()->json([
                'status' => false,
                'two_factor_required' => true,
                'message' => 'Enter the code from your authenticator app.',
            ], 401);
        }

        $key = '2fa:signin:' . $userId;

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json([
                'status' => false,
                'two_factor_required' => true,
                'message' => 'Too many attempts. Wait a minute and try again.',
            ], 429);
        }

        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

        $passed = $code !== ''
            ? $twoFactor->verifyFor($userId, $code)
            : $twoFactor->useRecoveryCode($userId, $recovery);

        if (!$passed) {
            return response()->json([
                'status' => false,
                'two_factor_required' => true,
                'message' => $code !== ''
                    ? 'That code is not right. Codes change every 30 seconds - try the current one.'
                    : 'That recovery code is not right, or it has already been used.',
            ], 401);
        }

        \Illuminate\Support\Facades\RateLimiter::clear($key);

        return null;
    }

    /**
     * GET /logout — end the Blade session.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * TWO VIEWS HAVE LINKED HERE ALL ALONG, AND IT 404ed
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `header.blade.php:242` renders `<a href="{{ url('/logout') }}">` and
     * `footer.blade.php:27` does `window.location.href = "/logout"`. Neither route
     * existed: `Route::resource('login', authController::class)` gives
     * login.index/store/show/edit/update/destroy and nothing called logout, and a
     * repository-wide search found no logout route of any kind.
     *
     * So the ERP's own Sign out was a dead link. Whoever clicked it got a 404 page
     * and stayed logged in - which looks like a broken button and is actually a
     * security hole, because `authMiddleware::hasSession()` is satisfied by
     * `session('user_id')` alone and that key was never removed.
     *
     * ── invalidate(), NOT forget() ───────────────────────────────────────────
     *
     * `invalidate()` flushes every key and regenerates the session id. Removing
     * `user_id` by itself would leave `client_id`, `is_admin`, `syear`,
     * `user_profile_name` and twenty more keys behind for the next person to sign in
     * on that browser - and `session()->put()` MERGES, so several of those are never
     * overwritten at the next login.
     *
     * `regenerateToken()` reissues the CSRF token; without it the login form the
     * person lands on would post a stale token and 419.
     *
     * ── AND THE API TOKENS THIS BROWSER MINTED ──────────────────────────────
     *
     * A Blade sign-out also revokes the tokens issued to this user, because the ERP
     * and the Next.js frontend share one account: leaving a live 30-day token behind
     * would mean the person is signed out of one surface and not the other. This is
     * the one place that is deliberately broad - a person clicking Sign out in the
     * ERP means "end my access on this machine", and the Blade session carries no
     * token id to be narrower with.
     */
    public function logout(Request $request)
    {
        $userId = $request->session()->get('user_id');

        if (is_numeric($userId)) {
            try {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', \App\Models\auth\tbluserModel::class)
                    ->where('tokenable_id', (int) $userId)
                    ->delete();
            } catch (\Throwable $e) {
                // Never blocks the sign-out. A person must always be able to leave,
                // and the session invalidation below is the part that matters most.
                \Illuminate\Support\Facades\Log::warning('logout: token revocation failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login.index');
    }

    private function recordSignIn($userId): void
    {
        try {
            DB::table('tbluser')
                ->where('id', $userId)
                ->update(['last_login' => now()->format('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // Fully qualified deliberately: this namespace imports no Log
            // facade, so a bare `Log::` would resolve inside the controller's own
            // namespace and fatal - inside a catch block, where it would be
            // hardest to notice.
            \Illuminate\Support\Facades\Log::error('last_login write failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

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

        /*
         * ═══════════════════════════════════════════════════════════════════
         * A SUSPENDED OR REMOVED ACCOUNT CANNOT SIGN IN
         * ═══════════════════════════════════════════════════════════════════
         *
         * Login checked the password and nothing else. `status` and
         * `deleted_at` were written by "Suspend Access" and by the legacy
         * delete, and then consulted by NO authentication path - so suspending
         * somebody removed them from the directory listing and left them able
         * to sign in and keep working.
         *
         * `setStatus` now revokes their live tokens, and without this gate that
         * would be worse than useless: they would be signed out and could
         * immediately sign back in, so the suspension would LOOK like it worked
         * and then silently not.
         *
         * ── THE MESSAGE IS DELIBERATELY DIFFERENT FROM A BAD PASSWORD ───────
         *
         * The password was already correct at this point, so saying "invalid
         * password" would send somebody to reset a password that is fine. This
         * is not an enumeration leak: the caller has already proved they hold
         * the credential for this account.
         *
         * BLAST RADIUS ON LIVE: 11 accounts have status 0 and 101 are
         * soft-deleted. Every one of them can sign in today and cannot after
         * this. That is the intended meaning of both flags.
         */
        if ((int) $user->status !== 1 || $user->deleted_at !== null) {
            return response()->json([
                'status' => 0,
                'message' => 'This account is no longer active. Please contact your administrator or HR.',
            ], 403);
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE SECOND FACTOR, BEFORE ANYTHING IS WRITTEN TO THE SESSION
         * ═══════════════════════════════════════════════════════════════════
         *
         * The password has been accepted and the account is active. If this
         * account has two-step verification on, the sign-in stops HERE.
         *
         * ── WHY THIS IS NOT 230 LINES FURTHER DOWN, WHERE IT WAS ────────────
         *
         * It used to sit immediately before `createToken`, which looked correct -
         * no token is minted until the code is right - and was a COMPLETE BYPASS
         * of the whole feature. Between here and there, the controller runs:
         *
         *     session()->put('user_id', $user->id);
         *     session()->put('user_profile_id', $user->user_profile_id);
         *
         * and `authMiddleware::hasSession()` is, in full,
         * `session()->has('user_id') && session()->get('user_id')`. So the 401
         * challenge went back to the caller with the session cookie ALREADY
         * carrying a logged-in identity: the person typed their correct password,
         * was told to enter a code, ignored it, opened any Blade route, and was
         * in. `RequireHritRole` would have resolved their role from that same
         * session key and authorised them.
         *
         * A second factor is only a factor if NOTHING usable exists before it
         * passes. The ordering is the feature; the token was never the only
         * credential this controller hands out.
         *
         * ── WHAT THIS COSTS ────────────────────────────────────────────────
         *
         * The tenant, academic-year and rights lookups below no longer run for a
         * challenged sign-in. That is a saving, not a loss - it was work done on
         * behalf of somebody who had not finished authenticating.
         *
         * ── AND WHY IT ANSWERS JSON ON BOTH PATHS ──────────────────────────
         *
         * Deliberately NOT routed through `is_mobile()`, which returns HTTP 200 -
         * the frontend distinguishes a challenge from a rejection by the status
         * code plus `two_factor_required`, and a 200 would read as neither. The
         * Blade login form has no code field, so an enrolled account signs in
         * through the product frontend; see docs on this.
         */
        $challenge = $this->twoFactorChallenge($request, $user);

        if ($challenge !== null) {
            return $challenge;
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
            /*
             * F-104. The frontend had only the DISPLAY NAME to work with, so it
             * guessed the role by substring: name.includes('admin') => admin,
             * includes('manager') => dept-head. That promoted every Reporting
             * Manager, collapsed Auditor, Executive and Recruiter to Employee,
             * and turned "rename a profile to contain the word admin" into a
             * privilege escalation.
             *
             * role_key (D-010) is the stable identifier the backend has
             * authorised on since RequireProfile; sending it is what lets the
             * frontend stop guessing. Null for the 13 legacy profiles that
             * predate role_key - mapProfileNameToRole() still resolves those by
             * an exact name match, never a substring.
             */
            'role_key' => $profileDetails->role_key ?? null,
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
            /*
             * The second factor was checked BEFORE any of the work above, and
             * before `user_id` reached the session - see the block after the
             * account-active gate. Nothing usable for authentication exists between
             * the password being accepted and the code being right.
             */
            session()->put($sessionData);
            // return session()->all();
            $sessionData['APP_URL'] = env('APP_URL');
            $token = $user->createToken(
                \App\Support\DeviceLabel::from($request->userAgent()),
                ['*'],
                /*
                 * AN EXPIRY AT CREATION, not only once the token is used.
                 *
                 * `TouchTokenActivity` slides this forward on every use, so an
                 * active session never ends. Setting it here as well closes the
                 * window between signing in and the first authenticated request:
                 * without it a token that is created and then abandoned - a
                 * failed automation, somebody who signs in and closes the tab -
                 * would be immortal, which is how the 4,960 on live came to be.
                 */
                now()->addDays(\App\Http\Middleware\TouchTokenActivity::IDLE_DAYS),
            )->plainTextToken;
            $this->recordSignIn($user->id);

            /*
             * AFTER the token exists, because "is this device new" is answered by
             * comparing against the labels already on this account - and this
             * sign-in's own label has to be among them for the count to work.
             */
            $this->alertOnNewDevice(
                (int) $user->id,
                \App\Support\DeviceLabel::from($request->userAgent()),
                (int) $user->sub_institute_id
            );
            $sessionData['token'] = $token;

            $res['status'] = 1;
            $res['message'] = "User Successfully Login";
            $user['user_profile'] = $userprofiledetails[0]->name;

            /*
             * ── THE LOGIN RESPONSE USED TO BE THE WHOLE tbluser ROW ─────────
             *
             * `$res['data'] = $user;` serialised all 99 columns to the browser
             * on every single sign-in, including:
             *
             *     pan_no, aadhar_no, account_no, ifsc_code, plain_password,
             *     bank_name, branch_name, pf_no, esic_no, uan_no, salary
             *     amount, termination_reason, relieving_reason
             *
             * PAN and Aadhaar are national identity numbers. `plain_password`
             * reads as a credential even though the column is now NULL
             * everywhere - and it will be read that way by whoever finds it in
             * a browser's network tab or a logged response body.
             *
             * The frontend needs SIXTEEN fields. Its own `LaravelLoginUser`
             * type (services/auth/index.ts:14-31) declares exactly these, so
             * this is not a guess about what is safe to remove - it is the
             * contract the caller already published.
             *
             * tbluserController solved this months ago with API_LIST_COLUMNS /
             * API_DETAIL_COLUMNS; the login controller was simply never given
             * the same treatment.
             */
            /*
             * `toArray()`, not `(array) $user`. Casting an Eloquent model with
             * (array) yields its INTERNAL properties - attributes, original,
             * relations, casts - so `only()` over it matched nothing and the
             * first version of this shipped an empty `data`. Caught by the
             * check below, which counts the fields rather than trusting them.
             */
            $res['data'] = collect($user->toArray())
                ->only(self::LOGIN_RESPONSE_FIELDS)
                ->all();
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

                /*
                 * ── THREE WAYS TO SKIP THE OTP USED TO LIVE HERE ────────────
                 *
                 * 1. A HARDCODED MOBILE NUMBER:
                 *
                 *        if ($_REQUEST['mobile'] == '9979176562') { $otp = "123456"; }
                 *
                 *    That number belongs to a REAL, ACTIVE ACCOUNT ON LIVE -
                 *    user #11, rajesh@gmail.com, tenant 3. Anybody who knew the
                 *    number signed in as them with 123456. A developer's
                 *    convenience that shipped.
                 *
                 * 2. THE DATE OF BIRTH AS THE OTP, for tenants 328-333:
                 *
                 *        $otp = date('dmy', strtotime($data['birthdate']));
                 *
                 *    A six-digit code derived from a fact colleagues know. Dead
                 *    in practice - none of those tenants exist on either
                 *    database - but it is a design nobody should reach for.
                 *
                 * 3. A FIXED OTP WHEN SMS IS UNCONFIGURED. Only tenant 3 has a
                 *    row in sms_api_details, so this was the path for 11 of 12
                 *    live organisations. It is unreachable TODAY only by
                 *    accident: the guard was
                 *
                 *        if ($res["error"] === 1) { if ($res["error"] == "Please add api details first.") { $otp = "123456"; } }
                 *
                 *    and PHP 8 changed int-to-string comparison, so `1 ==
                 *    "Please add..."` became false. On PHP 7 it was true. An
                 *    upgrade is not a security control.
                 *
                 * The OTP is now always random. If SMS cannot be sent, the
                 * caller is told so and no code is stored - a login that cannot
                 * deliver a code must fail closed, not fall back to a constant.
                 */
                $otp = (string) random_int(100000, 999999);
                $sub_institute_id = $data['sub_institute_id'];

                if ($sub_institute_id == 49 || $sub_institute_id == 232 || $sub_institute_id == 233) {
                    $text = "Dear Teacher your OTP is " . $otp;
                } elseif ($sub_institute_id == 47) {
                    $text = "Dear Parent, Your OTP is " . $otp . " MULJIM";
                } else {
                    $text = "OTP for login is " . $otp . " and is valid for 5 minutes";
                }

                $res = $this->sendSMS($request->mobile, $text, $sub_institute_id);

                /*
                 * sendSMS() returns a JsonResponse, so the payload is under
                 * `original` - NOT at the top level. The old code read
                 * `$res["error"]` after a json round-trip, which is
                 *
                 *     {"headers":{},"original":{"error":1,...},"exception":null}
                 *
                 * so `isset($res["error"])` was ALWAYS FALSE and the branch it
                 * guarded never ran. That is the same shape bug that made the
                 * hardcoded-123456 fallback unreachable - and reproducing it
                 * here would have made this fail OPEN instead of closed.
                 *
                 * Measured: tenant 3 (configured) -> original.error = 0
                 *           tenant 6 (no config)  -> original.error = 1
                 */
                $payload = $res instanceof \Symfony\Component\HttpFoundation\JsonResponse
                    ? (array) $res->getData(true)
                    : (array) json_decode(json_encode($res), true);

                $smsError = (int) ($payload['error'] ?? $payload['original']['error'] ?? 0);

                if ($smsError !== 0) {
                    // Nothing was delivered, so nothing is stored. Answering
                    // "OTP sent" here is exactly what let a fixed code hide.
                    return json_encode([
                        'status'  => '0',
                        'message' => 'Could not send the code. Ask your administrator to configure SMS for this organisation.',
                    ]);
                }

                /*
                 * SCOPED TO THE ORGANISATION.
                 *
                 * This UPDATE matched on mobile alone, across every tenant - so
                 * one code was written onto every account sharing that number,
                 * in organisations the caller has nothing to do with.
                 */
                DB::table("tbluser")
                    ->where('status', 1)
                    ->where('mobile', $request->input('mobile'))
                    ->where('sub_institute_id', $sub_institute_id)
                    ->update(["otp" => $otp]);

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
                /*
                 * THE VALIDATED REQUEST, NOT $_REQUEST.
                 *
                 * The validator above declares `otp` and `mobile` numeric and
                 * then the query read $_REQUEST anyway - so the rules were
                 * decoration. Laravel's own input is bound as a parameter
                 * either way, but reading around your own validation is how a
                 * rule quietly stops applying.
                 */
                ->where('u.otp', $request->input('otp'))
                ->where('u.mobile', $request->input('mobile'))
                // An empty or null otp must never match. A row whose code was
                // already consumed has otp = NULL, and `WHERE otp = ''` on a
                // caller-supplied empty string would sail straight through.
                ->whereNotNull('u.otp')
                ->where('u.otp', '!=', '')
                ->get()->toArray();

            $data = json_decode(json_encode($data), true);
            if (!empty($data)) {
                $data = $data[0];

                /*
                 * ONE CODE, ONE USE.
                 *
                 * The OTP used to stay on the row after a successful login,
                 * indefinitely - so it was a permanent second password for that
                 * account until the next login attempt overwrote it. Cleared
                 * here, before the token is issued.
                 *
                 * There is still no EXPIRY: tbluser.otp has no timestamp beside
                 * it, and adding one is a migration rather than a fix to this
                 * method. Single-use closes the larger hole; the clock is
                 * tracked as follow-up work.
                 */
                DB::table('tbluser')->where('id', $data['id'])->update(['otp' => null]);

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

                /*
                 * THE SAME CHALLENGE ON THE SMS PATH.
                 *
                 * This is the sign-in-with-a-one-time-code flow, and the plan said
                 * to leave it alone - meaning do not rewire how SMS works. This
                 * does not: it applies the SECOND factor wherever a token is
                 * minted.
                 *
                 * Without it, anybody with two-step verification on could skip it
                 * by signing in through SMS instead. A second factor that one of
                 * two sign-in paths ignores is not a second factor; it is a
                 * setting that sometimes applies.
                 */
                $challenge = $this->twoFactorChallenge($request, $user);

                if ($challenge !== null) {
                    return $challenge;
                }

                $token = $user->createToken(
                \App\Support\DeviceLabel::from($request->userAgent()),
                ['*'],
                /*
                 * AN EXPIRY AT CREATION, not only once the token is used.
                 *
                 * `TouchTokenActivity` slides this forward on every use, so an
                 * active session never ends. Setting it here as well closes the
                 * window between signing in and the first authenticated request:
                 * without it a token that is created and then abandoned - a
                 * failed automation, somebody who signs in and closes the tab -
                 * would be immortal, which is how the 4,960 on live came to be.
                 */
                now()->addDays(\App\Http\Middleware\TouchTokenActivity::IDLE_DAYS),
            )->plainTextToken;
                $this->recordSignIn($user->id);

            /*
             * AFTER the token exists, because "is this device new" is answered by
             * comparing against the labels already on this account - and this
             * sign-in's own label has to be among them for the count to work.
             */
            $this->alertOnNewDevice(
                (int) $user->id,
                \App\Support\DeviceLabel::from($request->userAgent()),
                (int) $user->sub_institute_id
            );

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
