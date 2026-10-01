<?php

namespace App\Domain\Signals;

use Illuminate\Database\Eloquent\Model;

class SignalRun extends Model
{
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    protected $table = 'g2g_signal_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
