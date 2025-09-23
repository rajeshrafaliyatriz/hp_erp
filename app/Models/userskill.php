<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class userskill extends Model
{
    protected $table = 's_users_skills';
    protected $fillable = [
      'department','sub_department','category','sub_category','micro_category',
      'title','description','skill_code','related_skill','business_link',
      'custom_tag','proficiency_level','job_title','learing_resources',
      'assesment_method','certification_qualification','experience_project',
      'skill_maps','skill_status','legal_compliance_relevance','sop_practice_link',
      'performance_metrics','common_error_tips','sme_contact','sub_skill',
      'skill_flow','tasklist','status','approve_id','sub_institute_id'
    ];

    protected $casts = [
      'related_skill' => 'array',
      'custom_tag' => 'array',
      'tasklist' => 'array'
    ];
}


