<?php

namespace App\Models\lms\assignment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsAssignment extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $table = 'lms_assignments';

    protected $fillable = [
        'user_id',
        'course_id',
        'assignment_type',
        'due_date',
        'status',
        'progress',
        'assigned_by',
        'assigned_on',
        'sub_institute_id'
    ];
}
