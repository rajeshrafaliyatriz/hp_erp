<?php

namespace App\Models\school_setup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\auth\tbluserModel;

class sub_std_mapModel extends Model
{
   use HasFactory, SoftDeletes;
    protected $table = "sub_std_map";
    protected $softDelete = true;
}
