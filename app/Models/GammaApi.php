<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GammaApi extends Model
{
    use HasFactory;

    protected $table = 'gamma_api';

    protected $fillable = [
        'account',
        'key',
        'status',
        'limit',
        'sub_institute_id',
    ];

    protected $casts = [
        'status' => 'integer',
        'limit' => 'integer',
        'sub_institute_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}