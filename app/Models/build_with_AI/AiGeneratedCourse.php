<?php

namespace App\Models\build_with_AI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiGeneratedCourse extends Model
{
    use SoftDeletes;

    protected $table = 'ai_generated_courses';
    protected $fillable = [
        'outline_id',
        'title',
        'description',
        'export_url',
        'presentation_platform',
        'status',
        'course_pdf',
        'sub_institute_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /*--------------------------
        RELATIONSHIPS
    ---------------------------*/

    // Each generated course belongs to one outline
    public function outline()
    {
        return $this->belongsTo(AiCourseOutline::class, 'outline_id');
    }

    // Institute
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