<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single onboarding checklist item belonging to a journey.
 *
 * Counted individually by the dashboard's "Onboarding Tasks Pending" action
 * item - which is why this is a separate grain from the journey itself.
 */
class OnboardingTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_onboarding_tasks';
    protected $guarded = ['id'];

    public const STATUSES = ['pending', 'in-progress', 'sent', 'completed'];

    public const CATEGORIES = ['documents', 'compliance', 'it', 'personal', 'learning', 'payroll', 'benefits', 'other'];

    protected $casts = [
        'journey_id'       => 'integer',
        'sub_institute_id' => 'integer',
        'owner_id'         => 'integer',
        'due_date'         => 'date',
        'completed_at'     => 'datetime',
        'sort_order'       => 'integer',
        'created_by'       => 'integer',
        'updated_by'       => 'integer',
    ];

    public function journey()
    {
        return $this->belongsTo(OnboardingJourney::class, 'journey_id');
    }
}
