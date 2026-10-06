<?php

namespace App\Services\Ai;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Turns an authenticated Sanctum caller into an `AiRequestScope`.
 *
 * WHY THIS IS NOT A COPY OF LMS K-12's `McpContextResolver`
 *
 * That class reads a GenTux JWT payload and an `academic_year` table, and G2G has
 * neither: it authenticates with Laravel Sanctum, and it has no academic terms
 * because it is not a school ERP. Copying the resolver would have meant shipping a
 * dependency that is not installed and a query against a table that does not exist.
 *
 * What *is* copied is the rule, which is the part that matters:
 *
 *   THE TOKEN DECIDES. THE REQUEST IS NEVER TRUSTED FOR IDENTITY.
 *
 * That is already how the rest of this application resolves a tenant — see
 * `App\Http\Controllers\Api\Concerns\ResolvesApiIdentity` — so this resolver agrees
 * with the other 689 API routes rather than inventing a second convention beside
 * them. The only difference is that it returns a scope object rather than an array,
 * because the AI controllers are written against LMS K-12's `scope()` contract and
 * keeping that identical is what makes the two implementations comparable.
 *
 * THE ONE PLACE A REQUEST-SUPPLIED INSTITUTE IS HONOURED
 *
 * A user whose `tbluser.sub_institute_id` is null — historical rows predate tenant
 * assignment — has no organisation of their own, so `X-AI-Institute-Id` (or the
 * legacy `sub_institute_id` input) is the only source left. A platform owner may
 * also name one, because a platform owner is by definition permitted to act across
 * tenants. Everyone else's request value is ignored rather than refused: a stale
 * value in localStorage is common, must not lock a legitimate user out, and is
 * equally safe ignored because it never reaches a query.
 */
class AiScopeResolver
{
    /**
     * @param  array<string, mixed>  $auth  What `AiAuth` put on the request.
     */
    public function resolve(Request $request, array $auth): AiRequestScope
    {
        $userId = (int) ($auth['user_id'] ?? 0);

        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'context' => ['The request scope could not be resolved.'],
            ]);
        }

        $isPlatformOwner = (bool) ($auth['is_platform_owner'] ?? false);
        $ownInstitute = (int) ($auth['sub_institute_id'] ?? 0);

        $selected = $this->selectInstitute($request, $ownInstitute, $isPlatformOwner);

        $allowed = $ownInstitute > 0 ? [$ownInstitute] : [];

        if (! in_array($selected, $allowed, true)) {
            $allowed[] = $selected;
        }

        return new AiRequestScope(
            userId: $userId,
            role: (string) ($auth['role'] ?? 'staff'),
            selectedInstituteId: $selected,
            allowedInstituteIds: $allowed,
            userProfileId: isset($auth['user_profile_id']) && $auth['user_profile_id'] !== null
                ? (int) $auth['user_profile_id']
                : null,
            clientId: isset($auth['client_id']) && $auth['client_id'] !== null
                ? (int) $auth['client_id']
                : null,
            isAdmin: (bool) ($auth['is_admin'] ?? false),
            isPlatformOwner: $isPlatformOwner,
        );
    }

    /**
     * The organisation this request acts on.
     *
     * The token owner's own organisation wins. A request may only name a different
     * one when the caller has none of their own, or is a platform owner.
     */
    private function selectInstitute(Request $request, int $ownInstitute, bool $isPlatformOwner): int
    {
        $requested = (int) (
            $request->header('X-AI-Institute-Id')
            ?: $request->header('X-MCP-Institute-Id')
            ?: $request->input('sub_institute_id')
            ?: 0
        );

        if ($ownInstitute > 0 && ! $isPlatformOwner) {
            return $ownInstitute;
        }

        if ($requested > 0) {
            return $requested;
        }

        if ($ownInstitute > 0) {
            return $ownInstitute;
        }

        throw ValidationException::withMessages([
            'institute' => ['No organisation scope was resolved for the authenticated user.'],
        ]);
    }

    /**
     * The authentication facts `AiAuth` reads off a Sanctum token's owner.
     *
     * Kept here beside the resolver rather than in the middleware because the two
     * halves have to agree about the shape of `$auth`, and one file is easier to
     * keep honest than two.
     *
     * @return array<string, mixed>
     */
    public function identityFor(object $user): array
    {
        $userId = (int) ($user->id ?? 0);

        return [
            'user_id' => $userId,
            'sub_institute_id' => (int) ($user->sub_institute_id ?? 0),
            'client_id' => $user->client_id ?? null,
            'user_profile_id' => $user->user_profile_id ?? null,
            'is_admin' => ((int) ($user->is_admin ?? 0)) >= 1,
            'is_platform_owner' => $this->isPlatformOwner($userId),
            'role' => $this->roleFor($user),
        ];
    }

    /**
     * Membership of `platform_owners`, which is the one authority that acts above a
     * tenant. A revoked row does not count — the table keeps history rather than
     * deleting, so the revocation has to be read.
     */
    private function isPlatformOwner(int $userId): bool
    {
        if ($userId <= 0 || ! Schema::hasTable('platform_owners')) {
            return false;
        }

        return DB::table('platform_owners')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * The caller's role as a word, for audit rows and rate-limit keys.
     *
     * `tbluserprofilemaster.role_key` is the stable identifier this application
     * already uses; the display name is the fallback for profiles that predate it.
     */
    private function roleFor(object $user): string
    {
        $profileId = $user->user_profile_id ?? null;

        if ($profileId === null || ! Schema::hasTable('tbluserprofilemaster')) {
            return ((int) ($user->is_admin ?? 0)) >= 1 ? 'admin' : 'staff';
        }

        $profile = DB::table('tbluserprofilemaster')->where('id', $profileId)->first();

        if ($profile === null) {
            return ((int) ($user->is_admin ?? 0)) >= 1 ? 'admin' : 'staff';
        }

        $key = trim((string) ($profile->role_key ?? ''));

        return $key !== '' ? $key : (string) ($profile->name ?? 'staff');
    }
}
