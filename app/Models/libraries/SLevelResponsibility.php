<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class SLevelResponsibility extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_level_responsibility';

    protected $fillable = [
        'level',
        'guiding_phrase',
        'essence_level',
        'attribute_code',
        'attribute_name',
        'attribute_type',
        'attribute_overall_description',
        'attribute_guidance_notes',
        'attribute_description',
    ];

    protected $softDelete = true;

    protected static function newFactory()
    {
        return \Database\Factories\SLevelResponsibilityFactory::new();
    }
}
