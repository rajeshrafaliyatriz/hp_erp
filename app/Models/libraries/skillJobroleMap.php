<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\libraries\userSkills;
use App\Models\auth\tbluserModel;
use App\Models\libraries\userJobroleModel;
use App\Models\libraries\userKnowledgeAbility;

class skillJobroleMap extends Model
{
    //
    use HasFactory, SoftDeletes;

    // protected $table = "s_skill_jobrole";
    protected $table = "s_user_skill_jobrole";
    protected $softDelete = true;

    public function userSkills()
    {
        return $this->belongsTo(userSkills::class, 'skill', 'title')
            ->select(['id', 'category', 'sub_category', 'title', 'description']);
    }
    public function createdUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id')
            ->select(['id', 'first_name', 'middle_name', 'last_name']);
    }

    public function userJobrole()
    {
        return $this->belongsTo(userJobroleModel::class, 'jobrole', 'jobrole')
            ->select(['id', 'jobrole', 'description']);
    }

    public function knowledgeAbility()
    {
        return $this->belongsTo(userKnowledgeAbility::class, 'skill_id', 'id');
    }
}
