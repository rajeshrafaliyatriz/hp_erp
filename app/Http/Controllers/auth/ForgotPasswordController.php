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
            'reset_url'=>'required',
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
        $reset_url = $request->reset_url;
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
                <p><a href="' . $reset_url . '?token=' . $token . '&email=' . $email . '">Reset Password</a></p>
                <p>If you did not request a password reset, no further action is required.</p>
                <p>Thank you,</p>
            </body>
        </html>';
        
        $emailController  = new AJAXController();
        $newRequest = request()->merge([
            'email' => $request->email,
            'token' => $token,
            'sub_institute_id' => 1,
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

        if ($insert) {
            $res = [
                'message' => 'We have e-mailed your password reset link!',
                'status'  => 1,
            ];
        } else {
            $res = [
                'message' => 'Failed to Find Email!',
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
