<?php

namespace App\Domain\Gtm;

use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped (sub_institute_id). Never query this without the caller's tenant. */
class GtmDeal extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'gtm_deals';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2', 'expected_close_date' => 'date:Y-m-d', 'next_step_due' => 'date:Y-m-d',
        'stage_changed_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public const STAGES = ['discovery', 'qualification', 'proposal', 'negotiation', 'won', 'lost'];
    public const OPEN_STAGES = ['discovery', 'qualification', 'proposal', 'negotiation'];

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }
}
