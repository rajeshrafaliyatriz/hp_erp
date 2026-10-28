<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Database\Eloquent\Model;

/**
 * One ICP an organisation has defined. The configuration is generic data (see IcpProfileValidator), so the
 * same engine serves a school, a hospital or a manufacturer. Only an `active` profile is researched.
 */
class IcpProfile extends Model
{
    // MariaDB 10.1 (live) cannot answer Eloquent's guarded-column introspection; see the trait.
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_icp_profiles';

    protected $guarded = ['id'];

    protected $casts = [
        'config' => 'array',
        'is_default' => 'boolean',
        'version' => 'integer',
    ];
}
