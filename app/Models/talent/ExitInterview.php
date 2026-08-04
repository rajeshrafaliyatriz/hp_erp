<?php

namespace App\Models\Talent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExitInterview extends Model
{
    use SoftDeletes;

    protected $table = 'talent_exit_interviews';

    protected $guarded = ['id'];

    protected $casts = [
        'interview_date' => 'date',
        'would_recommend' => 'boolean',
        'rehire_eligibility' => 'boolean',
        'feedback_rating' => 'integer',
    ];

    public function offboardingCase()
    {
        return $this->belongsTo(OffboardingCase::class, 'case_id');
    }
}
