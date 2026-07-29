<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyAssessment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_assessments';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'framework_id' => 'integer',
        'cycle_id' => 'integer',
        'user_id' => 'integer',
        'assessor_id' => 'integer',
        'department_id' => 'integer',
        'score' => 'float',
        'due_date' => 'date',
        'completed_at' => 'datetime',
    ];
}
