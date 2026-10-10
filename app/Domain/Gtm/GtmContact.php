<?php

namespace App\Domain\Gtm;

use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped (sub_institute_id). Never query this without the caller's tenant. */
class GtmContact extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'gtm_contacts';

    protected $guarded = ['id'];

    protected $casts = [];
}
