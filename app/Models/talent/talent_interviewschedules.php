<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Model;
use app\Http\Controllers\talent\talent_interviewschedulescontroller;

class talent_interviewschedules extends Model
{
    protected $table = 'talent_interview_schedules';
    protected $fillable = [
        'job_id',
        'applicant_id',
        'round_no',
        'interview_date',
        'interviewer_id',
        'status',
        'rating',
        'feedback',
        'sub_institute_id',
        'created_by'
    ];
}