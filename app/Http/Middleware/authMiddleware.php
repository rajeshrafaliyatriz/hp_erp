<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class authMiddleware
{
    /**
     * Allow the request only if the caller is actually authenticated.
     *
     * The previous check was `if (!session()->all())`. Under the `web` group a
     * session always contains at least `_token`, so that array is never empty
     * and the guard never fired - every route in routes/lms.php, user.php,
     * settings.php, hrms.php and the school_setup group was reachable with no
     * credentials at all.
     *
     * Authentication is now satisfied by either:
     *   1. a real Laravel session (authController@login sets `user_id`), which
     *      is how the Blade admin UI authenticates; or
     *   2. a valid Sanctum personal access token, which is how the Next.js
     *      frontends authenticate - they send it as the `token` query/body
     *      parameter, or as a normal Bearer header.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($this->hasSession() || $this->hasValidToken($request)) {
            return $next($request);
        }

        // API-style callers get JSON; browsers get sent to the login page.
        if (
            $request->expectsJson()
            || $request->header('Accept') === 'application/json'
            || $request->input('type') === 'API'
        ) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return redirect()->route('login');
    }

    /** A session is only authenticated once login has stored the user id. */
    private function hasSession(): bool
    {
        return session()->has('user_id') && session()->get('user_id');
    }

    private function hasValidToken(Request $request): bool
    {
        $token = $request->input('token') ?: $request->bearerToken();

        if (!$token) {
            return false;
        }

        return PersonalAccessToken::findToken($token) !== null;
    }
}
