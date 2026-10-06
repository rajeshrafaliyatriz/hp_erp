<?php

namespace App\Domain\Signals\Ingestion;

use Illuminate\Database\Eloquent\Model;

class IngestionFinding extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_ingestion_findings';

    protected $guarded = ['id'];

    protected $casts = [
        'evidence' => 'array',
        'reviewed_at' => 'datetime',
    ];
}

