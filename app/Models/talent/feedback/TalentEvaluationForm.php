<?php

namespace App\Models\talent\feedback;

use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\talent\feedback\feedbackController;

class TalentEvaluationForm extends Model
{
    protected $table = 'talent_evaluation_form';

    protected $fillable = [
        'job_id',
        'candidate_id',
        'panel_id',
        'evaluation_criteria',
        'recommendation',
        'key_strengths',
        'areas_of_concern',
        'additional_comments',
        'sub_institute_id',
        'notes',
        'status',
    ];

    protected $casts = [
        'evaluation_criteria' => 'array',
    ];
}
