<?php

namespace App\Models\ai_generated_assessment;

use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\ai_generated_assessment\generateQuestionController;

class QuestionMaster extends Model
{
    protected $table = 'lms_question_master';

    protected $fillable = [
        'question_type_id',
        'standard_id',
        'question_title',
        'description',
        'points',
        'paper_category',
        'multiple_answer',
        'sub_institute_id',
    ];

    public function answers()
    {
        return $this->hasMany(AnswerMaster::class, 'question_id');
    }
}
