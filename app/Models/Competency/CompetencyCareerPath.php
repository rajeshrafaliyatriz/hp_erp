<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyCareerPath extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_career_paths';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'department_id' => 'integer',
    ];

    public function steps()
    {
        return $this->hasMany(CompetencyCareerPathStep::class, 'career_path_id')
            ->orderBy('step_order');
    }
}
