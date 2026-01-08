<?php

namespace App\Models\lms;

use Illuminate\Database\Eloquent\Model;

class lmsQuestionMappingModel extends Model
{
    protected $table = "lms_question_mapping";
 public $timestamps = true;

    protected $fillable = [
        'id',
        'questionmaster_id',
        'mapping_type_id',
        'mapping_value_id',
        'reasons',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'sub_institute_id'
    ];
}
