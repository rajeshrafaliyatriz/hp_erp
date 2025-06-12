<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\libraries\userSkills;
use App\Models\auth\tbluserModel;

class userJobroleModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "s_user_jobrole";
    protected $softDelete = true;

    // public function userSkills()
    // {
    //     return $this->belongsTo(userSkills::class, 'skill_id', 'id')
    //         ->select(['id', 'category', 'sub_category', 'title']);
    // }

    // public function createdUser()
    // {
    //     return $this->belongsTo(tbluserModel::class, 'created_by', 'id')
    //         ->select(['id', 'first_name','middle_name','last_name']);
    // }

}
