<?php

namespace App\Http\Controllers\Api\TaskManagement\Concerns;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared request context for /api/task-management/*.
 *
 * Mirrors what WorkspaceController and MyTasksController already do - token
 * from bearer header or `token` param, tenant from the token's user with the
 * request as fallback, mandatory syear - extracted so the fourteen smaller
 * controllers do not each carry their own slightly different copy.
 */
trait ResolvesTaskContext
{
    /**
     * @return array{user_id:int, sub_institute_id:int, syear:string}|\Illuminate\Http\JsonResponse
     */
    protected function taskContext(Request $request)
    {
        $token = trim((string) ($request->bearerToken() ?: $request->input('token')));
        if ($token === '') {
            return response()->json(['status' => 0, 'message' => 'Token is required.'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;
        if (!$user) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated.'], 401);
        }

        $syear = trim((string) $request->input('syear'));
        if ($syear === '') {
            return response()->json(['status' => 0, 'message' => 'syear is required.'], 422);
        }

        return [
            'user_id' => (int) $user->id,
            // User-first, NOT request-first. Taking sub_institute_id from the
            // request when present let any authenticated user read another
            // organisation's data by changing one query parameter. The token's
            // own user decides the tenant; the request is only a fallback for
            // accounts that carry no tenant of their own.
            'sub_institute_id' => (int) ($user->sub_institute_id ?: $request->input('sub_institute_id', 0)),
            'syear' => $syear,
        ];
    }

    protected function ok(string $message, $data = null, int $code = 200)
    {
        $payload = ['status' => 1, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $code);
    }

    protected function fail(string $message, int $code = 422, ?array $errors = null)
    {
        $payload = ['status' => 0, 'message' => $message];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }
}
