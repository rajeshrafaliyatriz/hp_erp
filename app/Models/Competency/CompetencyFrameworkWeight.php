<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyFrameworkWeight extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_framework_weights';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'framework_id' => 'integer',
        'weight' => 'float',
    ];
}
