<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;

class Jobroleskill extends Model
{
    
     protected $table = 's_user_jobrole';
    public $timestamps = true;
    protected $fillable = [
        'industries',
        'department',
        'sub_department',
        'jobrole',
        'description',
        'job_level',
        'has_vertical_progression',
        'has_lateral_movement',
        'progression_type',
        'jobrole_category',
        'performance_expectation',
        'status',
        'related_jobrole',
        'required_skill_experience',
        'location',
        'salary_range',
        'company_information',
        'benefits',
        'keyword_tags',
        'job_posting_date',
        'application_deadline',
        'contact_information',
        'internal_tracking',
        'education',
        'experience',
        'training',
        'sub_institute_id',
    ];
}
