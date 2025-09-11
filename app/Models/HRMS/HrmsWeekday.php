<?php

namespace App\Models\HRMS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrmsWeekday extends Model
{
    use HasFactory,SoftDeletes;
    protected $guarded = ['id'];
}
