<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;

class jobroletexonomy extends Model
{
    protected $table = 's_user_jobrole';

        protected $fillable = ['jobrole_category', 'sub_institute_id'];
    
}
