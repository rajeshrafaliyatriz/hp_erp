<?php

namespace App\Models\auth;

use Illuminate\Database\Eloquent\Model;

class tblclientModel extends Model
{
    protected $table = 'tblclient';
    
    protected $fillable = [
        'client_name',
        'created_at',
        'updated_at',
    ];
}
