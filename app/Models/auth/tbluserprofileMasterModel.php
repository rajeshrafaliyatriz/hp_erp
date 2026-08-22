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
        // The STABLE identifier authorization matches on (RequireProfile). It
        // was missing from this list, so signup's create() silently dropped it
        // and every profile ever created here fell back to matching on the
        // editable display name.
        'role_key',
        'sort_order',
        'status',
        'sub_institute_id',
        'client_id',
    ];
}
