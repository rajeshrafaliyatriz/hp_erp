<?php

namespace App\Models\lms_course_enroll;

use Illuminate\Database\Eloquent\Model;

class LmsCourseEnroll extends Model
{
    protected $table = 'lms_course_enroll';
    public $timestamps = true;
    protected $fillable = [
        'user_id',
        'course_id',
        'status',
        'start_date',
        'end_date',
        'sub_institute_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
