<?php

namespace App\Domain\Signals\Market;

use Illuminate\Database\Eloquent\Model;

/** What changed on an opportunity, and when: amendments, escalations, evidence downgrades. */
class OpportunityEvent extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_opportunity_events';

    protected $guarded = ['id'];

    protected $casts = ['changes' => 'array'];
}
