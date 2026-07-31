<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TalentOffboardingCase extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_offboarding_cases';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'employee_id' => 'integer',
        'department_id' => 'integer',
        'notice_date' => 'date',
        'last_working_day' => 'date',
        'exit_interview_done' => 'boolean',
        'exit_interview_date' => 'date',
        'manager_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];
}
