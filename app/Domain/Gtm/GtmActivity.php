<?php

namespace App\Domain\Gtm;

use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped (sub_institute_id). Never query this without the caller's tenant. */
class GtmActivity extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'gtm_activities';

    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime'];
}
