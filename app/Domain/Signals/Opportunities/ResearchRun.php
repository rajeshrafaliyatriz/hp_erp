<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class ResearchRun extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_research_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'report_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}

