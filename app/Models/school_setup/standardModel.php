<?php

namespace App\Models\school_setup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class standardModel extends Model
{
    //
    use HasFactory, SoftDeletes;
    protected $table = "standard";
    protected $softDelete = true;

}
