<?php

namespace App\Http\Controllers\Api\Agentic\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared request context for the Agentic AI API.
 *
 * Mirrors ResolvesCompetencyContext: every /api/agentic/* endpoint is token
 * authenticated (Sanctum personal access token passed as `token`) and tenant
 * scoped by sub_institute_id.
 *
 * The module it replaces had neither - agents lived on a public HuggingFace
 * Space addressed by bare id, so one organisation could read, edit or delete
 * another's agents by guessing a number.
 */
trait ResolvesAgenticContext
{
    /**
     * @return array{sub_institute_id:int, user_id:int|null}|\Illuminate\Http\JsonResponse
     */
    protected function agenticContext(Request $request)
    {
        $token = $request->input('token');

        if (!$token) {
            return response()->json(['status' => 0, 'message' => 'Token not provided'], 401);
        }

        if (!PersonalAccessToken::findToken($token)) {
            return response()->json(['status' => 0, 'message' => 'Invalid token'], 401);
        }

        $subInstituteId = $request->input('sub_institute_id') ?? $request->header('sub_institute_id');

        if (!$subInstituteId || !is_numeric($subInstituteId)) {
            return response()->json(['status' => 0, 'message' => 'sub_institute_id is required'], 400);
        }

        return [
            'sub_institute_id' => (int) $subInstituteId,
            'user_id'          => is_numeric($request->input('user_id')) ? (int) $request->input('user_id') : null,
        ];
    }

    /** Treat 'all', '0' and empty string as "no filter". */
    protected function activeFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }

    protected function fail(string $message, int $status = 400)
    {
        return response()->json(['status' => 0, 'message' => $message], $status);
    }

    protected function ok(string $message, $data = null, ?array $extra = null)
    {
        return response()->json(array_merge(
            ['status' => 1, 'message' => $message, 'data' => $data],
            $extra ?? []
        ));
    }

    /** @return array{0:int, 1:int} page, per_page */
    protected function paging(Request $request, int $default = 25): array
    {
        $perPage = min(max((int) $request->input('per_page', $default), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        return [$page, $perPage];
    }

    protected function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'last_page' => (int) max(ceil($total / max($perPage, 1)), 1),
        ];
    }

    /** The actor's display name, for feeds that read better with a person on them. */
    protected function actorName(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = DB::table('tbluser')->where('id', $userId)->first();
        if (!$user) {
            return null;
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->user_name ?? null);
    }

    /**
     * Decode a json column that may already be an array, a json string, or the
     * legacy comma-separated form the old client posted for `tools`.
     *
     * @return array<int, string>
     */
    protected function decodeList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn ($item) => trim($item) !== ''));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded), fn ($item) => trim($item) !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
    }
}
