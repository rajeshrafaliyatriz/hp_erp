<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignupOtp extends Model
{
    protected $table = 'signup_otp';

    protected $fillable = [
        'email',
        'otp',
        'expires_at',
        'is_verified'
    ];
}
