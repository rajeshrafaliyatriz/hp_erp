<?php

namespace App\Models\ai_generated_assessment;

use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\ai_generated_assessment\generateQuestionController;

class AnswerMaster extends Model
{
     protected $table = 'answer_master';

    protected $fillable = [
        'question_id',
        'answer',
        'correct_answer',
        'sub_institute_id',
    ];
}
