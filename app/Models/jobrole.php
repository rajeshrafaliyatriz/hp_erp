<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class jobrole extends Model
{
    use HasFactory;

    // If table name differs from default plural, set it:
    protected $table = 's_jobrole';

    // allow mass assignment if you will use Industry::create(...)
    protected $fillable = ['sector', 'description']; // adjust columns as needed
}
