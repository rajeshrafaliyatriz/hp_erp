<?php

namespace App\Domain\Signals\Market;

use Illuminate\Database\Eloquent\Model;

/** An alternative name for a buyer organisation (g2g_companies). Unique per tenant. */
class CompanyAlias extends Model
{
    use \App\Models\Concerns\SkipsGuardableColumnCheck;

    protected $table = 'g2g_company_aliases';

    protected $guarded = ['id'];
}
