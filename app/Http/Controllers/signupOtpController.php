<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SignupOtp;
use Carbon\Carbon;
use Mail;

class signupOtpController extends Controller
{
   // Send OTP
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;

        $otp = rand(1000,9999);

        SignupOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp' => $otp,
                'expires_at' => Carbon::now()->addMinutes(10),
                'is_verified' => false
            ]
        );

        // THE GATE. This is the ONLY anonymous send path in the codebase - the
        // pre-authentication signup flow, which cannot hold a token by nature. It
        // sends a fixed four-digit OTP to one address, so the blast radius is
        // small, but it consulted the flag zero times and "email is off" was
        // believed to cover it.
        if (!\App\Support\MailGate::allowed()) {
            return response()->json(['status' => false, 'message' => \App\Support\MailGate::reason()], 503);
        }

        // Send email
        Mail::raw("Your OTP is: $otp", function ($message) use ($email) {
            $message->to($email)
                    ->subject('Your OTP Code');
        });

        return response()->json([
            'status' => true,
            'message' => 'OTP sent successfully'
        ]);
    }

    // Verify OTP
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required'
        ]);

        $record = SignupOtp::where('email', $request->email)
                    ->where('otp', $request->otp)
                    ->first();

        if(!$record){
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP'
            ]);
        }

        if(Carbon::now()->gt($record->expires_at)){
            return response()->json([
                'status' => false,
                'message' => 'OTP expired'
            ]);
        }

        $record->update([
            'is_verified' => true
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully'
        ]);
    }
}
