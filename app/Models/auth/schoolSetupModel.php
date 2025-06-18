<?php

namespace App\Models\auth;

use Illuminate\Database\Eloquent\Model;
use App\Models\auth\tblclientModel as TblClient;

class schoolSetupModel extends Model
{
    //
    protected $table = 'school_setup';
    public function client()
    {
        return $this->belongsTo(TblClient::class, 'client_id', 'id');
    }
}
