<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SLibraryMap extends Model
{
    use HasFactory;
    protected $table = 's_library_map';

    protected $fillable = [
        'type',
        'type_id',
        'jobrole_ids',
        'task_ids',
        'skill_ids',
        'knowledge_ids',
        'ability_ids',
        'attitude_ids',
        'behaviour_ids',
        'sub_institute_id'
    ];

    // Hide empty or null fields from JSON
    protected $hidden = [
        'created_at',
        'updated_at'
    ];

    protected $appends = [];

    // Automatically remove NULL or empty string fields from JSON
    public function toArray()
    {
        $array = parent::toArray();

        return array_filter($array, function ($value) {
            return !is_null($value) && $value !== "" && $value !== "null";
        });
    }
}
