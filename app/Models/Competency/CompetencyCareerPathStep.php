<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyCareerPathStep extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_career_path_steps';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'career_path_id' => 'integer',
        'jobrole_id' => 'integer',
        'step_order' => 'integer',
    ];

    public function path()
    {
        return $this->belongsTo(CompetencyCareerPath::class, 'career_path_id');
    }
}
