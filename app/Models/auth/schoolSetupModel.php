<?php

namespace App\Models\auth;

use Illuminate\Database\Eloquent\Model;

class schoolSetupModel extends Model
{
    //
    protected $table = 'school_setup';
    public function client()
    {
        return $this->belongsTo(TblClient::class, 'client_id', 'id');
    }
}
