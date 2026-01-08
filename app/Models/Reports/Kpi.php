<?php

namespace App\Models\Reports;

use Illuminate\Database\Eloquent\Model;

class Kpi extends Model
{
    protected $table = 'tbluser';

    protected $fillable = [
        'id',
        'first_name',
        'middle_name',
        'last_name',
        'joined_date',
        'terminated_date',
        'status'
    ];
}
