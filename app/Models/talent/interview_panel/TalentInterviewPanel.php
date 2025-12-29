<?php

namespace App\Models\talent\interview_panel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\talent\feedback\feedbackController;

class TalentInterviewPanel extends Model
{
    use SoftDeletes;

    protected $table = 'talent_Interview_Panel';

    protected $fillable = [
        'sub_institute_id',
        'panel_name',
        'target_positions',
        'description',
        'available_interviewers',
        'status'
    ];

    public function schedules()
    {
        return $this->hasMany(\App\Models\talent\talent_interviewschedules::class, 'panel_id');
    }
}
