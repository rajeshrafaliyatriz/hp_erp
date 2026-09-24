<?php

namespace App\Models\HRMS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\HRMS\hrmsDepartmentModel;
use App\Models\Concerns\SkipsGuardableColumnCheck;

class HrmsHoliday extends Model
{
    use HasFactory, SoftDeletes, SkipsGuardableColumnCheck;
    protected $guarded = ['id'];
    protected $appends = ['department_name'];

    public function getDepartmentNameAttribute()
    {
        if (!$this->department) {
            return '';
        }
        return hrmsDepartmentModel::whereIn('id', explode(',', $this->department))
            ->pluck('department')
            ->implode(',');
    }
}
