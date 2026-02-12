<?php

namespace App\Models\user;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserRatingDetail extends Model
{
     use HasFactory;
    protected $table = 'user_rating_details';
    protected $guarded = [];
}
