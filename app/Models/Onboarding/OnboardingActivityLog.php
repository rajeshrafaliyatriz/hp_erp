<?php

namespace App\Models\Onboarding;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\SkipsGuardableColumnCheck;

/**
 * Append-only feed behind the Lifecycle Timeline tab and the module audit trail.
 *
 * Same shape as s_performance_activity_log so both feeds render identically;
 * `changes` holds the field-level diff [{field,label,old,new}].
 */
class OnboardingActivityLog extends Model
{
    use SkipsGuardableColumnCheck;

    protected $table = 'talent_onboarding_activity_log';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'user_id'          => 'integer',
        'subject_id'       => 'integer',
        'journey_id'       => 'integer',
        'changes'          => 'array',
    ];
}
