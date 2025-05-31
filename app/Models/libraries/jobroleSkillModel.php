<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class jobroleSkillModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql_Dev';
    protected $table = "s_jobrole_skills";
    protected $softDelete = true;
}
