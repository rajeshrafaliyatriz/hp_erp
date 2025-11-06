<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Model;
use app\Http\Controllers\talent\talent_jobapplicationcontroller;

class talent_jobapplication extends Model
{
	protected $table = 'talent_job_applications';
    protected $fillable = [
        'job_id',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'mobile',
        'current_location',
        'employment_type',
        'experience',
        'education',
        'expected_salary',
        'skills',
        'certifications',
        'resume_path',
        'applied_date',
        'status',
        'sub_institute_id',
        'created_by',
    ];
}


