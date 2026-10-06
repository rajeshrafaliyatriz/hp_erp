<?php

namespace App\Models\auth;

use Illuminate\Database\Eloquent\Model;
use App\Models\auth\schoolSetupModel;
use App\Models\auth\tblclientModel;
use App\Models\auth\academicSectionModel;
use App\Models\auth\tbluserprofileMasterModel;
use Laravel\Sanctum\HasApiTokens;
use App\Models\talent\feedback\feedbackController;

class tbluserModel extends Model
{
    //
    use HasApiTokens;
    protected $table = 'tbluser';

    /** Never serialised - see App\Models\user\tbluserModel. F-92, HRIT Sprint 1. */
    protected $hidden = ['password', 'remember_token', 'otp'];
    protected $fillable = [
        'user_name',
        'password',
        'first_name',
        'last_name',
        'email',
        'mobile',
        'user_profile_id',
        'sub_institute_id',
        'client_id',
        'is_admin',
        'status',
        'allocated_standard',
        'department_id',
        'employee_id',
    ];
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
