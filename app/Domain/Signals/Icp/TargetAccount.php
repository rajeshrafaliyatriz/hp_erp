<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Database\Eloquent\Model;

class TargetAccount extends Model
{
    // MariaDB 10.1 (live) cannot answer Eloquent's guarded-column introspection; see the trait.
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_target_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'attributes' => 'array',
        'icp_notes' => 'array',
        'last_checked_at' => 'datetime',
        'last_signal_at' => 'datetime',
    ];
}
