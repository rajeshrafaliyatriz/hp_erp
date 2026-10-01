<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Database\Eloquent\Model;

class ResearchSource extends Model
{
    protected $table = 'g2g_research_sources';

    protected $guarded = ['id'];

    protected $casts = ['published_at' => 'date', 'retrieved_at' => 'datetime', 'page_fetched' => 'boolean', 'cited' => 'boolean'];
}
