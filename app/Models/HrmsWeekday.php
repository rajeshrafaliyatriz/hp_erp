<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\SkipsGuardableColumnCheck;

class HrmsWeekday extends Model
{
    use HasFactory,SoftDeletes, SkipsGuardableColumnCheck;
    protected $guarded = ['id'];
}
