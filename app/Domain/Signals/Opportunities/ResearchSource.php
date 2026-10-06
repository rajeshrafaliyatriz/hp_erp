<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class ResearchSource extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    protected $table = 'g2g_research_sources';

    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'date', 'retrieved_at' => 'datetime', 'page_fetched' => 'boolean', 'cited' => 'boolean'];
}

