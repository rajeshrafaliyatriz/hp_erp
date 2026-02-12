<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Model;

class TalentOfferTemplate extends Model
{
    protected $table = 'talent_offer_templates';
    protected $fillable = [
        'sub_institute_id',
        'module_name',
        'title',
        'html_content',
        'sort_order',
        'status',
        'created_by',
    ];
}
