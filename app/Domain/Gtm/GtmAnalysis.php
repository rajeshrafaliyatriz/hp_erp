<?php

namespace App\Domain\Gtm;

use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped (sub_institute_id). Never query this without the caller's tenant. */
class GtmAnalysis extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'gtm_analyses';

    protected $guarded = ['id'];

    protected $casts = ['result' => 'array', 'sources' => 'array', 'is_estimate' => 'boolean'];
}
