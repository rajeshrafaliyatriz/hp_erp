<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\talent\talent_interviewschedulescontroller;
use app\Http\Controllers\talent\candidate\candidateController;
use App\Http\Controllers\talent\feedback\feedbackController;



class talent_interviewschedules extends Model
{
    protected $table = 'talent_interview_schedules';
    protected $fillable = [
        'job_id',
        'applicant_id',
        'round_no',
        'interview_date',
        'time',
        'duration',
        'location',
        'interviewer_id',
        'status',
        'rating',
        'feedback',
        'additional_notes',
        'sub_institute_id',
        'created_by',
        'panel_id'
    ];

    public function jobPosting()
    {
        return $this->belongsTo(talent_jobposting::class, 'job_id');
    }

    public function panel()
    {
        return $this->belongsTo(\App\Models\talent\interview_panel\TalentInterviewPanel::class, 'panel_id');
    }
}