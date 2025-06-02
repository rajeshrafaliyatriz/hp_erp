<?php

namespace App\Models\school_setup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class subjectModel extends Model
{
    //
    use HasFactory, SoftDeletes;
    protected $table = "subject";
    protected $softDelete = true;
}
