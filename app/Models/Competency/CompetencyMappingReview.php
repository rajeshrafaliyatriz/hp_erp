<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyMappingReview extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_mapping_reviews';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'framework_id' => 'integer',
        'department_id' => 'integer',
        'submitted_by' => 'integer',
        'reviewer_id' => 'integer',
        'changes_count' => 'integer',
        'reviewed_at' => 'datetime',
    ];
}
