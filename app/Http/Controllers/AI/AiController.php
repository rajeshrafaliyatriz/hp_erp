<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiRequestScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared base for the AI & Intelligence API.
 *
 * The response envelope is `{success, message, data, errors}` — the same shape LMS
 * K-12's AI API returns, so a frontend written against one product's endpoint needs
 * no second parser for the other. It is deliberately *not* this application's
 * `{status, message, data}` shape: the AI screens are ported wholesale from LMS
 * K-12, and giving them a third envelope to handle would mean rewriting every client
 * for no gain.
 *
 * `scope()` IS THE IMPORTANT METHOD
 *
 * It returns the `AiRequestScope` that `AiContextHydrator` put on the request, and
 * raises if there is none. Controllers never build a scope from request input, which
 * is what stops a caller naming someone else's organisation — and because the only
 * source is the middleware, an AI route added without it fails loudly instead of
 * quietly reading a tenant from a query string.
 */
abstract class AiController extends Controller
{
    protected function scope(Request $request): AiRequestScope
    {
        $scope = $request->attributes->get('ai_scope');

        if (! $scope instanceof AiRequestScope) {
            throw ValidationException::withMessages([
                'context' => ['The request scope could not be resolved.'],
            ]);
        }

        return $scope;
    }

    protected function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    protected function failure(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }

    /**
     * Turns an exception into an honest response without leaking internals.
     *
     * Validation and authorisation failures keep their own status and message;
     * anything else is reported as a server error with the detail logged rather than
     * returned.
     */
    protected function handle(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return $this->failure('The request was not valid.', 422, $exception->errors());
        }

        if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return $this->failure($exception->getMessage(), 403);
        }

        if ($exception instanceof \RuntimeException) {
            // Domain-level refusals — governance, ownership, template state — are
            // meaningful to the caller and safe to show.
            return $this->failure($exception->getMessage(), 422);
        }

        report($exception);

        return $this->failure('The request could not be completed.', 500);
    }

    /** A bounded page size. Callers may ask for more, but not for everything. */
    protected function limit(Request $request, int $default = 50, int $max = 200): int
    {
        $limit = (int) $request->input('limit', $default);

        return max(1, min($limit, $max));
    }
}
