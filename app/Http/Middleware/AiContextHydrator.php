<?php

namespace App\Http\Middleware;

use App\Services\Ai\AiScopeResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Puts a resolved `AiRequestScope` on the request.
 *
 * Mirrors LMS K-12's `McpContextHydrator`, including the decision to return 422
 * rather than let the validation exception bubble: a caller whose scope cannot be
 * resolved has made a request that is malformed, not one that failed.
 *
 * Every AI controller reads the scope through `AiController::scope()`, which
 * raises if this middleware did not run. That is what makes it impossible to add
 * an AI route that quietly reads its tenant from request input — the route would
 * have no scope at all.
 */
class AiContextHydrator
{
    public function __construct(private readonly AiScopeResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $auth = $request->attributes->get('ai_auth', []);
            $request->attributes->set('ai_scope', $this->resolver->resolve($request, $auth));
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'The request scope could not be resolved.',
                'data' => null,
                'errors' => $exception->errors(),
            ], 422);
        }

        return $next($request);
    }
}
