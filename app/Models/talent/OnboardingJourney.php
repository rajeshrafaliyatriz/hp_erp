<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One new hire's onboarding journey, from accepted offer to confirmation.
 *
 * Drives the Talent Dashboard's Onboarding KPI card and Onboarding Progress
 * donut, and every row of the Onboarding Center's register.
 */
class OnboardingJourney extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_onboarding_journeys';
    protected $guarded = ['id'];

    /** Journey lifecycle - 'completed' is the only terminal state. */
    public const STATUSES = ['not-started', 'in-progress', 'completed'];

    /** Where the hire is in the journey; 'preboarding' precedes joining_date. */
    public const STAGES = ['preboarding', 'first-day', 'orientation', 'integration', 'probation', 'confirmed'];

    /** confirmation_status - the probation outcome. */
    public const CONFIRMATION_STATUSES = ['pending', 'confirmed', 'extended', 'terminated'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'employee_id'      => 'integer',
        'offer_id'         => 'integer',
        'application_id'   => 'integer',
        'department_id'    => 'integer',
        'buddy_id'         => 'integer',
        'manager_id'       => 'integer',
        'confirmed_by'     => 'integer',
        'joining_date'     => 'date',
        'probation_start'  => 'date',
        'probation_end'    => 'date',
        'extension_end'    => 'date',
        'confirmed_on'     => 'date',
        'completed_at'     => 'date',
        'created_by'       => 'integer',
        'updated_by'       => 'integer',
    ];

    public function tasks()
    {
        return $this->hasMany(OnboardingTask::class, 'journey_id');
    }

    public function documents()
    {
        return $this->hasMany(OnboardingDocument::class, 'journey_id');
    }
}
