<?php

namespace App\Models\build_with_AI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiCourseOutline extends Model
{
    use SoftDeletes;

    protected $table = 'ai_course_outlines';
    protected $fillable = [
        'course_type',
        'input_fields',
        'configure_fields',
        'outline',
        'sub_institute_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    // JSON Casts
    protected $casts = [
        // 'input_fields' => 'array',
        // 'configure_fields' => 'array',
        // 'outline' => 'array',
    ];

    /*--------------------------
        RELATIONSHIPS
    ---------------------------*/

    // One outline → Many generated courses
    public function generatedCourses()
    {
        return $this->hasMany(AiGeneratedCourse::class, 'outline_id');
    }

    // Belongs to institute
    public function subInstitute()
    {
        return $this->belongsTo(SubInstitute::class, 'sub_institute_id');
    }

    // Created / Updated / Deleted by user
    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedByUser()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}