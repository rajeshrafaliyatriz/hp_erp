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

    /** The column is varchar(255); a longer progress/summary note must never abort the run's own bookkeeping. */
    public function setStageMessageAttribute(?string $value): void
    {
        $this->attributes['stage_message'] = $value === null ? null : mb_substr($value, 0, 255);
    }
}

