<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_companies';

    protected $guarded = ['id'];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];
}

