<?php

namespace Tests\Security\DirectCallers;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A token OWNER that is not the application's user model. It reads the same `tbluser` rows, so Sanctum's own lookup resolves it as a perfectly valid
 * `->tokenable`, which is exactly the "owner-type confusion" the fixed resolver refuses (every real login issues its token on App\Models\auth\tbluserModel).
 * Kept in its own file so it autoloads independently of any test class.
 */
class GroupXForeignOwner extends Model
{
    use HasApiTokens;

    protected $table = 'tbluser';
    public $timestamps = false;
}
