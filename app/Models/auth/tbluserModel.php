<?php

namespace App\Models\auth;

use Illuminate\Database\Eloquent\Model;
use App\Models\auth\schoolSetupModel;
use App\Models\auth\tblclientModel;
use App\Models\auth\academicSectionModel;
use App\Models\auth\tbluserprofileMasterModel;
use Laravel\Sanctum\HasApiTokens;

class tbluserModel extends Model
{
    //
    use HasApiTokens;
    protected $table = 'tbluser';
    public function organization()
    {
        return $this->belongsTo(schoolSetupModel::class, 'sub_institute_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(tblclientModel::class, 'client_id', 'id');
    }

    public function yearData()
    {
        return $this->belongsTo(academicSectionModel::class, 'sub_institute_id', 'sub_institute_id');
    }
     public function userProfile()
    {
        return $this->belongsTo(tbluserprofileMasterModel::class, 'user_profile_id', 'id');
    }
    
}
