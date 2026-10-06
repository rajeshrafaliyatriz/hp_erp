<?php

namespace App\Models\hr;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TemplateVersion extends Model
{
    use HasFactory;

    protected $table = 'template_versions';

    protected $fillable = [
        'template_id',
        'content',
        'version',
        'sub_institute_id',
        'created_by'
    ];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}