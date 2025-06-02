<?php

namespace App\Models\school_setup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\auth\tbluserModel;

class academic_sectionModel extends Model
{
    //
    use HasFactory, SoftDeletes;
    protected $table = "academic_section";
    protected $softDelete = true;
    public function createdUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id')
            ->select(['id', 'first_name','middle_name','last_name']);
    }
    public function updatedUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id')
            ->select(['id', 'first_name','middle_name','last_name']);
    }
    public function deletedUser()
    {
        return $this->belongsTo(tbluserModel::class, 'created_by', 'id')
            ->select(['id', 'first_name','middle_name','last_name']);
    }
}
