<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\SkipsGuardableColumnCheck;

class CompetencyFramework extends Model
{
    use HasFactory, SoftDeletes, SkipsGuardableColumnCheck;

    protected $table = 's_competency_frameworks';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'department_id' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(CompetencyFrameworkItem::class, 'framework_id');
    }
}
