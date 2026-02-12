<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuggestedCourse extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'suggested_course';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_id',
        'task_id',
        'course_id',
        'course_name',
        'sub_institute_id',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'employee_id' => 'integer',
        'task_id' => 'integer',
        'course_id' => 'integer',
        'course_name' => 'string',
        'sub_institute_id' => 'integer',
        'created_by' => 'integer',
    ];
}
