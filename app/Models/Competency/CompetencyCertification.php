<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyCertification extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_certifications';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'user_id' => 'integer',
        'competency_id' => 'integer',
        'department_id' => 'integer',
        'issued_date' => 'date',
        'expiry_date' => 'date',
    ];
}
