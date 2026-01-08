<?php

namespace App\Models\front_desk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class taskModel extends Model
{
    use SoftDeletes;

    protected $table = "task";
    protected $guarded = [];
    public $timestamps = false;

}
