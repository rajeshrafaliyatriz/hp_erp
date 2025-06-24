<?php

namespace App\Models\frontdesk;

use Illuminate\Database\Eloquent\Model;

class taskModel extends Model
{
    protected $table = "task";

    public $timestamps = false;

    public function complaint(){
        return $this->belongsTo('App\Models\frontdesk\complaintModel');
    }

    public function frontdesk(){
        return $this->belongsTo('App\Models\frontdesk\frontdeskModel');
    }

    public function PettycashMaster(){
        return $this->belongsTo('App\Models\frontdesk\pettycashMasterModel');
    }

    public function Pettycash(){
        return $this->belongsTo('App\Models\frontdesk\PettycashModel');
    }

}
