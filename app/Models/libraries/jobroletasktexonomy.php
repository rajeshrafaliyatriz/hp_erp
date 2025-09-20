<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class jobroletasktexonomy extends Model
{
    protected $table = 's_user_jobrole_task';

        protected $fillable = ['jobrole_category', 'sub_institute_id'];
}
