<?php

namespace App\Domain\Signals;

use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    public const STATUS_NEW = 'New';
    public const STATUS_REVIEWED = 'Reviewed';
    public const STATUS_DISMISSED = 'Dismissed';

    public const PRIORITIES = ['High', 'Medium', 'Low'];
    public const STATUSES = [self::STATUS_NEW, self::STATUS_REVIEWED, self::STATUS_DISMISSED];

    protected $table = 'g2g_signals';

    protected $guarded = ['id'];

    protected $casts = [
        'evidence' => 'array',
        'sources' => 'array',
        'generated_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}

