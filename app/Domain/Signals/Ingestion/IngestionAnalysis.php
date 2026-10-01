<?php

namespace App\Domain\Signals\Ingestion;

use Illuminate\Database\Eloquent\Model;

class IngestionAnalysis extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_ingestion_analyses';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}

