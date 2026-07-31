<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document collected during onboarding (ID proof, signed agreement, ...).
 *
 * Separate from OnboardingTask because a document row carries a file plus its
 * own request -> submit -> verify lifecycle, which a checklist item does not.
 */
class OnboardingDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_onboarding_documents';
    protected $guarded = ['id'];

    public const STATUSES = ['pending', 'requested', 'submitted', 'verified', 'rejected'];

    protected $casts = [
        'journey_id'       => 'integer',
        'sub_institute_id' => 'integer',
        'document_type_id' => 'integer',
        'is_mandatory'     => 'boolean',
        'due_date'         => 'date',
        'requested_at'     => 'datetime',
        'submitted_at'     => 'datetime',
        'verified_at'      => 'datetime',
        'verified_by'      => 'integer',
        'sort_order'       => 'integer',
        'created_by'       => 'integer',
        'updated_by'       => 'integer',
    ];

    public function journey()
    {
        return $this->belongsTo(OnboardingJourney::class, 'journey_id');
    }
}
