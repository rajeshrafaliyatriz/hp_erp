<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\SoftDeletes;

class skillLibraryModel extends Model
{
    // use HasFactory, SoftDeletes;
    use HasFactory;

    // protected $connection = 'mysql_Dev';
    protected $table = "master_skills";
    // protected $softDelete = true;
}
