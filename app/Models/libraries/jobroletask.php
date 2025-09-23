<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;

class jobroletask extends Model
{
    protected $table = 's_user_jobrole_task';

        protected $fillable = ['task_category', 'sub_institute_id'];
}
