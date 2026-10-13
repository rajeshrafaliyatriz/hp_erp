<?php

namespace App\Domain\Signals\Market;

use Illuminate\Database\Eloquent\Model;

/** One import / daily scan: how many records arrived and what happened to them. */
class ScanLog extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_signal_scan_log';

    protected $guarded = ['id'];

    protected $casts = ['ran_at' => 'datetime', 'notes' => 'array'];
}
