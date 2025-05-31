<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class authMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        if (!session()->all()) {
            // Check if request type is API (you can check header or param)
            if ($request->header('Accept') === 'application/json' || $request->type === 'API') {
                // API response - unauthenticated
                return response()->json(['message' => 'Unauthorized'], 401);
            } else {
                // Web response - redirect to login page
                return redirect()->route('login');
            }
        }

        return $next($request);
    }
}
