<?php

namespace App\Models\school_setup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class academic_sectionModel extends Model
{
    //
    use HasFactory, SoftDeletes;
     protected $table = "academic_section";
    protected $softDelete = true;

}
