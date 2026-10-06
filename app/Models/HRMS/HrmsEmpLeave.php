<?php

namespace App\Models\HRMS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\SkipsGuardableColumnCheck;

class HrmsEmpLeave extends Model
{
    use HasFactory, SoftDeletes, SkipsGuardableColumnCheck;
    protected $guarded = ['id'];

    public function leave_type()
    {
        return $this->belongsTo(HrmsLeaveType::class);
    }
}
