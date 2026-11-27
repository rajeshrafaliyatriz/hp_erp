<?php

namespace App\Domain\Signals\AiSignals;

use Illuminate\Database\Eloquent\Model;

/** One output delivered by a scheduled AI task, kept as received (see the migration for why this table exists). The payload is never exposed by the API. */
class AiDelivery extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_ai_deliveries';

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'results'];

    protected $casts = [
        'received_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    public const MAX_ATTEMPTS = 3;
}
