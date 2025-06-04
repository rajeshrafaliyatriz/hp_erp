<?php

namespace App\Models\school_setup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class sub_std_mapModel extends Model
{
     use HasFactory, SoftDeletes;
    protected $table = "sub_std_map";
    protected $softDelete = true;

    protected $fillable = [
        'id',
        'subject_id',
        'standard_id',
        'allow_grades',
        'elective_subject',
        'display_name',
        'load',
        'optional_type',
        'add_content',
        'allow_content',
        'subject_category',
        'display_image',
        'sort_order',
        'sub_institute_id',
        'status',
        'created_at',
        'updated_at'
    ];
}
