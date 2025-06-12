<?php

namespace App\Models\libraries;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\libraries\userSkills;
use App\Models\auth\tbluserModel;

use Illuminate\Database\Eloquent\Model;

class userJobroleTask extends Model
{
    //\
    use HasFactory, SoftDeletes;

    protected $table = "s_user_jobrole_task";
    protected $softDelete = true;
}
