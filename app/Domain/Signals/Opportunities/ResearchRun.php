<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class ResearchRun extends Model
{
    protected $table = 'g2g_research_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'report_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
