<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Database\Eloquent\Model;

/** A saved version of an ICP profile's configuration, kept so a change can be reviewed or undone. */
class IcpProfileVersion extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_icp_profile_versions';

    protected $guarded = ['id'];

    protected $casts = ['config' => 'array', 'version' => 'integer'];
}
