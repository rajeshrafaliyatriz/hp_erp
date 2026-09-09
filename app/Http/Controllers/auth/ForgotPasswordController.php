<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\user\tbluserModel;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use function App\Helpers\is_mobile;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\AJAXController;
use Illuminate\Support\Facades\Hash;

class ForgotPasswordController extends Controller
{
    /**
     * How long a reset link stays usable.
     *
     * A constant, not config: this is a security property, and an organisation
     * widening its own reset window is not a setting worth offering.
     */
    public const TOKEN_HOURS = 24;

    /**
     * Write code on Method
     *
     * @return Application|Factory|View()
     */

    // public function showForgetPasswordForm()
    // {
    //     return view('auth.forgetPassword');
    // }


    /**
     * Write code on Method
     *
     * @return RedirectResponse()
     */

    public function submitForgetPasswordForm(Request $request)
    {
        // return $request;
        $type = $request->type;
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            /*
             * `reset_url` IS NO LONGER ACCEPTED, and that is the fix.
             *
             * It used to be `required` and was interpolated straight into the
             * outbound email:
             *
             *     <a href="' . $reset_url . '?token=' . $token . '&email=' . $email . '">
             *
             * This endpoint is UNAUTHENTICATED. So anybody could POST a real
             * user's email address with a reset_url pointing at a host they
             * control, and this system would mail that person a VALID RESET
             * TOKEN aimed at the attacker's site. No allow-list, no scheme
             * check, no escaping.
             *
             * The destination now comes from config, where an operator sets it
             * and a request cannot.
             */
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' =>  $validator->errors()->first(),
            ], 422);
        }

        $checkUser = tbluserModel::where('email', $request->email)->first();
        if (! $checkUser) {
            return response()->json([
               'status' => false,
               'message' =>  'Email does not exist!',
            ], 422);
        } //end if

        $token = Str::random(64);
        $email = $request->email;

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if ($frontend === '') {
            // No configured destination means no safe link to send. Refusing is
            // the only honest answer - the alternative was accepting one from
            // the caller, which is the hole being closed.
            return response()->json([
                'status' => false,
                'message' => 'Password reset is not configured for this installation. Ask your administrator.',
            ], 503);
        }

        $reset_url = $frontend . '/set-password';

        $checkEmailExists = DB::table('password_reset_tokens')->where('email', $email)->first();
        if ($checkEmailExists) {    
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        } //end if
        $insert = DB::table('password_reset_tokens')->insert([
            'email'      => $email,
            'token'      => $token,
            'created_at' => Carbon::now(),
        ]);

        $htmlContent = '
        <html>
            <head>
                <title>Reset Password</title>
            </head>
            <body>
                <p>Dear User,</p>
                <p>We have received a request to reset your password. Please click on the following link to reset your password:</p>
                <p><a href="' . htmlspecialchars($reset_url . '?token=' . $token . '&email=' . rawurlencode($email), ENT_QUOTES, 'UTF-8') . '">Reset Password</a></p>
                <p>If you did not request a password reset, no further action is required.</p>
                <p>Thank you,</p>
            </body>
        </html>';

        $emailController  = new AJAXController();
        $newRequest = request()->merge([
            'email' => $request->email,
            'token' => $token,
            /*
             * THE USER'S OWN ORGANISATION, not a hardcoded 1.
             *
             * AJAXController::sendEmail() looks SMTP credentials up per tenant
             * in `smtp_details`. Passing 1 meant every organisation's password
             * resets went out through tenant 1's mail account - and only tenant
             * 1 has a row, so for everybody else the lookup found nothing.
             */
            'sub_institute_id' => $checkUser->sub_institute_id,
            'example_subject' => 'Reset Password',
            'all_email' => $email,
            'content' => $htmlContent,
        ]);

        $emailSent = $emailController->sendEmail($newRequest);
        // return $emailSent;
        // Mail::send(
        //     'email.forgetPassword',
        //     ['token' => $token, 'email' => $request->email],
        //     function ($message) use ($request) {
        //         $message->to($request->email);
        //         $message->subject('Reset Password');
        //     }
        // );

        /*
         * ── THE RESULT THAT WAS THROWN AWAY ─────────────────────────────────
         *
         * This block used to key on `$insert` - the TOKEN ROW INSERT - so it
         * answered "We have e-mailed your password reset link!" whenever the
         * database write succeeded, whether or not any mail left the building.
         *
         * And it always succeeded: AJAXController::sendEmail() returns
         * `status_code => 1` even on its no-SMTP branch, whose message is
         * literally "You did not setup mail client." So the one honest signal
         * in the whole path was both wrong AND discarded.
         *
         * Now the send is what decides. A person who is told their link is on
         * the way and waits for an email that was never sent is worse off than
         * one who is told to ask an administrator.
         */
        $sendResult = json_decode(json_encode($emailSent), true);
        $delivered = is_array($sendResult)
            && (int) ($sendResult['status_code'] ?? 0) === 1
            && !str_contains(strtolower((string) ($sendResult['message'] ?? '')), 'did not setup');

        if ($insert && $delivered) {
            $res = [
                'message' => 'We have e-mailed your password reset link!',
                'status'  => 1,
            ];
        } else {
            $res = [
                'message' => $insert
                    ? 'Your organisation has no email set up, so the link could not be sent. Ask your administrator to reset your password.'
                    : 'Failed to Find Email!',
                'status'  => 0,
            ];
        }
        // return back()->with('message', 'We have e-mailed your password reset link!');
        return is_mobile($type, 'login', $res, "view");
    }

    /**
     * Write code on Method
     *
     * @return Application|Factory|View()
     */

    public function showResetPasswordForm($token, $email)
    {
        return view('auth.forgetPasswordLink', ['token' => $token, 'email' => $email]);
    }

    /**
     * Write code on Method
     *
     * @return Application|Factory|View|RedirectResponse
     */

    public function submitResetPasswordForm(Request $request)
    {
    //    return $request;
        $type = $request->type;
        $validator = Validator::make($request->all(),[
            'email'                 => 'required|email',
            'password'              => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' =>  $validator->errors()->first(),
            ], 422);
        }
        
        $updatePassword = DB::table('password_reset_tokens')
            ->where([
                'email' => $request->email,
                'token' => $request->token,
            ])->first();

        /*
         * ── THE CLOCK NOBODY READ ───────────────────────────────────────────
         *
         * `password_reset_tokens.created_at` has been written since the table
         * existed and checked NOWHERE. A reset link was therefore valid
         * forever: an old email in a mailbox, a link in a chat history, a token
         * from a device somebody no longer owns - all still worked.
         *
         * Live holds tokens from July. Every one of them was live until now.
         *
         * 24 hours, and it is a constant here rather than config because a
         * reset link is not a setting a customer should be able to widen.
         */
        if ($updatePassword && $updatePassword->created_at !== null
            && \Carbon\Carbon::parse($updatePassword->created_at)->addHours(self::TOKEN_HOURS)->isPast()) {

            // Spent tokens are removed, not left to accumulate.
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return response()->json([
                'status' => false,
                'message' => 'That link has expired. Ask for a new one.',
            ], 422);
        }

        if (!$updatePassword) {
             return response()->json([
                'status' => false,
                'message' =>  'Failed to fetch Email! please retry !',
            ], 422);
        }

        $user = tbluserModel::where('email', $request->email)->update([
            'password' => Hash::make($request->password),
        ]);

        DB::table('password_reset_tokens')->where(['email' => $request->email])->delete();

        // return view('login')->with('successMsg', 'Your password has been changed!');
        if ($user) {
            $res = [
                'message' => 'Your password has been changed!',
                'status'  => 1,
            ];
        } else {
            $res = [
                'message' => 'Failed to change password!',
                'status'  => 0,
            ];
        }
        // return back()->with('message', 'We have e-mailed your password reset link!');
        return is_mobile($type, 'login', $res, "view");
    }

    
}
