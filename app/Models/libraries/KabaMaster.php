<?php

namespace App\Models\libraries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KabaMaster extends Model
{
    protected $table = "";   // will be set dynamically
    protected $softDelete = true;
    protected $fillable = [
        'category',
        'sub_category',
        'title',
        'description'
    ];

    // Set table dynamically
    public static function fromType($type)
    {
        $instance = new self;

        switch ($type) {
            case 'knowledge':
                $instance->table = 's_user_knowledge';
                break;

            case 'ability':
                $instance->table = 's_user_ability';
                break;

            case 'behaviour':
                $instance->table = 's_user_behaviour';
                break;

            case 'attitude':
                $instance->table = 's_user_attitude';
                break;

            case 'skill':
                $instance->table = 's_users_skills';
                break;

            default:
                throw new \Exception("Invalid KABA type: $type");
        }

        return $instance;
    }
}
