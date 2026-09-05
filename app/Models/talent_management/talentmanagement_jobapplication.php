<?php

namespace App\Models\talent_management;


use Illuminate\Database\Eloquent\Model;


class talentmanagement_jobapplication extends Model
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
        'skills',
        'certifications',
        'status',
        'updated_by',
        'updated_at',
        'sub_institute_id',
    ];
}
