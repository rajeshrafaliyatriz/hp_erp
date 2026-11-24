<?php

namespace App\Http\Middleware;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\IntelligenceDenied;
use App\Domain\Intelligence\Access\MenuRightsChecker;
use App\Domain\Intelligence\Access\RightsChecker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTHENTICATES AND PLACES THE CALLER. FAIL CLOSED, in this order:
 *
 *   1. A Bearer token is required. (The `?token=` query form is NOT accepted: a token in a URL ends up in access logs and Referer headers.)
 *   2. The token must exist, must not be expired, and must belong to a user (IntelligenceTokenAuthenticator: a strict lookup that never provisions).
 *   3. The user must belong to an organisation of their OWN (`sub_institute_id` > 0) and be an active employee (status = 1, not terminated). Otherwise 403.
 *      There is NO fallback: no `sub_institute_id` from the query string, body or a header, and no "first organisation". This is the deliberate difference from
 *      ResolvesApiIdentity, whose fallback lets a tenant-less account choose a tenant.
 *   4. The IntelligenceCaller is built from the token owner's own user row and the role of their own profile, and stored on the request.
 *
 * Anything the client sends named like a tenant, user, role or scope is never read and never forwarded.
 *
 * THE ROUTES ARE OUTSIDE THE `api` MIDDLEWARE GROUP on purpose (routes/intelligence.php): that group's TouchTokenActivity and RequireTwoFactorEnrolment call
 * PersonalAccessTokenResolver::findToken, which provisions an administrator-bound token for an unknown token string. See SanctumIntelligenceTokenAuthenticator.
 */
class IntelligenceIdentity
{
    public const ATTRIBUTE = 'intelligence.caller';

    private const UNAVAILABLE = 'Intelligence is not available for this account.';

    private readonly IntelligenceTokenAuthenticator $tokens;

    /** No container binding is required: unbound, the strict Sanctum lookup is used. A test binds its own. */
    public function __construct(?IntelligenceTokenAuthenticator $tokens = null)
    {
        $this->tokens = $tokens ?? new SanctumIntelligenceTokenAuthenticator;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $plain = trim((string) $request->bearerToken());
        if ($plain === '') {
            return $this->refuse('Token not provided', 401);
        }

        $token = $this->tokens->find($plain);
        $user = $token?->tokenable;
        if ($token === null || ! is_object($user)) {
            return $this->refuse('Invalid token', 401);
        }

        $expiresAt = $token->expires_at ?? null;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            return $this->refuse('Token expired', 401);
        }

        // Only an active employee of an organisation of their own. NULL status is not active (the canonical active-employee rule).
        $active = (int) ($user->status ?? 0) === 1 && empty($user->terminated_date ?? null) && empty($user->deleted_at ?? null);
        if (! $active) {
            return $this->refuse(self::UNAVAILABLE, 403);
        }

        try {
            $caller = IntelligenceCaller::fromUser(
                $user,
                IntelligenceCaller::roleKeyForProfile((int) ($user->user_profile_id ?? 0)),
                app()->bound(RightsChecker::class) ? app(RightsChecker::class) : new MenuRightsChecker,
            );
        } catch (IntelligenceDenied) {
            return $this->refuse(self::UNAVAILABLE, 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $caller);

        return $next($request);
    }

    private function refuse(string $message, int $status): Response
    {
        return response()->json(['status' => 0, 'message' => $message], $status);
    }
}
