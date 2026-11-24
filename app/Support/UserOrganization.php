<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which organisation (tenant, `sub_institute_id`) an account belongs to, and what happens to an account that has none.
 *
 * THE RULE: an account belongs to the organisation written on its own row (`tbluser.sub_institute_id`), and to no other. An account with no organisation, or with
 * one that does not exist or has been deleted, is refused with HTTP 400 and MESSAGE. The first organisation in the database is never a default, and neither is the
 * organisation named in the request: both would hand an account without a tenant the data of somebody else's.
 *
 * Used by the sign-in paths (authController::index, GoogleAuthController) and by every gate that resolves the caller from a token (ResolvesApiIdentity, RequireApiToken,
 * RequireProfile, RequireAnyAccess). Tests: tests/Feature/UserOrganizationTest.php.
 */
final class UserOrganization
{
    public const MESSAGE = 'You do not have any organization assigned. Please contact your administrator.';

    /** The organisation id written on the account, or null when it has none (null, 0, blank, not a number). */
    public static function idOf(?object $user): ?int
    {
        $id = (int) ($user->sub_institute_id ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * True when the account has an organisation id AND that organisation exists and is not deleted. Used at sign-in, where one more query is cheap; the per-request
     * gates only ask idOf().
     */
    public static function isValidFor(?object $user): bool
    {
        $id = self::idOf($user);
        if ($id === null) {
            return false;
        }

        if (! Schema::hasTable('school_setup')) {
            return false;
        }

        $query = DB::table('school_setup')->where('id', $id);
        if (ColumnExists::has('school_setup', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->exists();
    }

    /** The one answer for an account without a usable organisation: 400, in the API's usual shape. */
    public static function refusal(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => self::MESSAGE], 400);
    }
}
