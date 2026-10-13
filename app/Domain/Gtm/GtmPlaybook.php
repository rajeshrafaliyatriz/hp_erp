<?php

namespace App\Domain\Gtm;

use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped (sub_institute_id). Never query this without the caller's tenant. */
class GtmPlaybook extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'gtm_playbooks';

    protected $guarded = ['id'];

    protected $casts = ['inputs' => 'array', 'output_schema' => 'array', 'definition' => 'array', 'is_system' => 'boolean'];
}
