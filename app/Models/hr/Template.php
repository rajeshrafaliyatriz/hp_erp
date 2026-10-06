<?php

namespace App\Models\hr;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Template extends Model
{
    use HasFactory;

    protected $keyType = 'int';
    public $incrementing = true;

    protected $fillable = [
        'name',
        'content',
        'version',
        'status',
        'sub_institute_id',
        'created_by',
        'updated_by'
    ];

    // Auto-generate UUID when creating a new template
    // protected static function boot()
    // {
    //     parent::boot();

    //     static::creating(function ($model) {
    //         if (empty($model->{$model->getKeyName()})) {
    //             $model->{$model->getKeyName()} = (string) Str::uuid();
    //         }
    //     });
    // }

    public function versions()
    {
        return $this->hasMany(TemplateVersion::class, 'template_id');
    }
}