<?php

namespace App\Models\auth;

use Illuminate\Database\Eloquent\Model;

class tbluserprofileMasterModel extends Model
{
    protected $table = 'tbluserprofilemaster';
    
    protected $fillable = [
        'parent_id',
        'name',
        'description',
        'sort_order',
        'status',
        'sub_institute_id',
        'client_id',
    ];
}
