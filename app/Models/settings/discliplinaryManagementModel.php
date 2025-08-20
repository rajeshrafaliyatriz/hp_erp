<?php

namespace App\Models\settings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\auth\tbluserModel;
use App\Models\HRMS\hrmsDepartmentModel;

class discliplinaryManagementModel extends Model
{
    use HasFactory;
    protected $table = "discliplinary_management";

    public function departmentData()
    {
        return $this->belongsTo(hrmsDepartmentModel::class, 'department_id', 'id');
    }

    public function employeeData()
    {
        return $this->belongsTo(tbluserModel::class, 'employee_id', 'id');
    }

    public function createdUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id');
    }

    public function updatedUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id');
    }

    public function deletedUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id');
    }
}
