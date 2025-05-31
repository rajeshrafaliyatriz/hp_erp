<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class userSkills extends Model
{
    //
     use HasFactory, SoftDeletes;
     protected $table = "s_users_skills";
    protected $softDelete = true;
}
