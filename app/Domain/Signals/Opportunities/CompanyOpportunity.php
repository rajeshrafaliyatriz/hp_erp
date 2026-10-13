<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class CompanyOpportunity extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_company_opportunities';

    protected $guarded = ['id'];

    protected $casts = [
        'sources' => 'array',
        'confirmed_facts' => 'array',
        'unverified_claims' => 'array',
        'source_published_at' => 'date',
        'event_date' => 'date',
        'expires_at' => 'date',
        'observed_at' => 'datetime',
        'candidate_need_codes' => 'array',
        'is_government_track' => 'boolean',
        'is_sample' => 'boolean',
        'first_discovered_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function researchRun()
    {
        return $this->belongsTo(ResearchRun::class, 'research_run_id');
    }

    public function ingestionSource()
    {
        return $this->belongsTo(\App\Domain\Signals\Ingestion\IngestionSource::class, 'ingestion_source_id');
    }
}

