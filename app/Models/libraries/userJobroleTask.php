<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\libraries\userSkills;
use App\Models\auth\tbluserModel;
use App\Models\libraries\userJobroleModel;
use App\Models\libraries\jobroleSkillModel;

use Illuminate\Database\Eloquent\Model;

class userJobroleTask extends Model
{
    //\
    use HasFactory, SoftDeletes;

    protected $table = "s_user_jobrole_task";
    protected $softDelete = true;
    public function createdUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id')
            ->select(['id', 'first_name', 'middle_name', 'last_name']);
    }

    public function userJobrole()
    {
        return $this->belongsTo(userJobroleModel::class, 'created_by', 'jobrole')
            ->select(['id', 'jobrole', 'description']);
    }

    public function jobroleSkillModel(){
         return $this->belongsTo(jobroleSkillModel::class, 'jobrole', 'jobrole')->select(['id', 'jobrole', 'skill']);
    }
}
