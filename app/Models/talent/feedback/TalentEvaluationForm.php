<?php

namespace App\Models\talent\feedback;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Http\Controllers\talent\feedback\feedbackController;

class TalentEvaluationForm extends Model
{
    /**
     * talent_evaluation_form has carried a deleted_at column all along; the model
     * simply never used it, so any delete() would have been permanent.
     *
     * Interview feedback is the evidence behind a hiring decision. It has to be
     * removable - there is a confirmed Delete button in the interview drawer - but
     * it must not be destroyable, or the record of why somebody was rejected can
     * be erased without trace.
     *
     * No row is currently soft-deleted, so adding this changes no existing read.
     */
    use SoftDeletes;

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
