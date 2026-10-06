<?php

namespace App\Domain\Signals\Ingestion;

use Illuminate\Database\Eloquent\Model;

class IngestionSource extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_ingestion_sources';

    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'segments'];

    protected $casts = [
        'truncated' => 'boolean',
        'discovered_news' => 'array',
        'identified_entities' => 'array',
        'retrieved_at' => 'datetime',
        'last_analyzed_at' => 'datetime',
    ];

    public function findings()
    {
        return $this->hasMany(IngestionFinding::class, 'source_id');
    }

    public function linkedOpportunities()
    {
        return $this->hasMany(\App\Domain\Signals\Opportunities\CompanyOpportunity::class, 'ingestion_source_id');
    }
}

