<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompetencyActivityLog extends Model
{
    use HasFactory;

    protected $table = 's_competency_activity_log';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'user_id' => 'integer',
        'subject_id' => 'integer',
        // [{field, label, old, new}, ...] - the Audit Center's Change Summary.
        'changes' => 'array',
    ];
}
