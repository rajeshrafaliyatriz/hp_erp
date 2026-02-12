<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Model;

class TalentOffer extends Model
{
    protected $table = 'talent_offers';
    protected $fillable = [
        'application_id',
        'job_id',
        'template_id',
        'position',
        'salary',
        'start_date',
        'expires_at',
        'status',
        'offer_letter_url',
        'notes',
        'sub_institute_id',
        'created_by',
        'sent_at',
        'rejected_at',
    ];
}