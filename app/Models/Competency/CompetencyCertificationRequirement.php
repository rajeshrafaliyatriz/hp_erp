<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyCertificationRequirement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_certification_requirements';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id'      => 'integer',
        'department_id'         => 'integer',
        'competency_id'         => 'integer',
        'is_mandatory'          => 'boolean',
        'validity_months'       => 'integer',
        'renewal_reminder_days' => 'integer',
        'grace_period_days'     => 'integer',
    ];
}
