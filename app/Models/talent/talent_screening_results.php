<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\school_setupModel;

class talent_screening_results extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'candidate_id',
        'competency_match',
        'cultural_fit',
        'predicted_success',
        'overall_fit_score',
        'ranking_score',
        'skill_gaps',
        'strengths',
        'recommendation',
        'deepseek_analysis',
        'sub_institute_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'skill_gaps' => 'array',
        'strengths' => 'array',
        'deepseek_analysis' => 'array',
    ];

    public function candidate()
    {
        return $this->belongsTo(talent_jobapplication::class, 'candidate_id');
    }

    public function subInstitute()
    {
        return $this->belongsTo(school_setupModel::class, 'sub_institute_id');
    }
}
