<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Industry extends Model
{
    use HasFactory;

    // If table name differs from default plural, set it:
    protected $table = 's_industries';

    // allow mass assignment if you will use Industry::create(...)
    protected $fillable = ['industries', 'description']; // adjust columns as needed
}
