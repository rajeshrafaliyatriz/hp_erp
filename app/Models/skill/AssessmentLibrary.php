<?php

namespace App\Models\skill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentLibrary extends Model
{
    use HasFactory;
    protected $table = "question_paper";
    protected $fillable = [
        'grade_id',
        'standard_id',
        'subject_id',
        'paper_name',
        'paper_desc',
        'open_date',
        'close_date',
        'timelimit_enable',
        'time_allowed'
    ];

    public $timestamps = false;
}