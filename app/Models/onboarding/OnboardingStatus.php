<?php

namespace App\Models\onboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnboardingStatus extends Model
{
    use SoftDeletes;

    protected $table = 'user_onboarding_status';

    protected $fillable = [
        'user_id',
        'feature',
        'completed_at',
        'sub_institute_id',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $dates = ['deleted_at'];
}
