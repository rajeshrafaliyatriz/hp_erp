<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyAssessmentCycle extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_assessment_cycles';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
}
