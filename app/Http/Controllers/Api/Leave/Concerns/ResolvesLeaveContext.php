<?php

namespace App\Http\Controllers\Api\Leave\Concerns;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared request context resolution for the Leave Management API.
 *
 * Every endpoint under /api/leave is token authenticated (Sanctum personal
 * access token passed as `token`), and every query is scoped by
 * sub_institute_id + the April-March leave year.
 */
trait ResolvesLeaveContext
{
    /**
     * @return array{sub_institute_id:int, user_id:int|null, year:int, from:string, to:string}|\Illuminate\Http\JsonResponse
     */
    protected function leaveContext(Request $request)
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

        $year = $this->normaliseLeaveYear($request->input('syear') ?? $request->input('year'));

        return [
            'sub_institute_id' => (int) $subInstituteId,
            'user_id'          => is_numeric($request->input('user_id')) ? (int) $request->input('user_id') : null,
            'year'             => $year,
            'from'             => $year . '-04-01',
            'to'               => ($year + 1) . '-03-31',
        ];
    }

    /**
     * The frontend stores syear in several shapes ("2024", "2024-25", "2024-2025").
     * Everything downstream builds date strings from it, so collapse to the
     * 4 digit opening year of the April-March window.
     */
    protected function normaliseLeaveYear($value): int
    {
        if (is_numeric($value) && (int) $value > 1900 && (int) $value < 2200) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/(\d{4})/', $value, $matches)) {
            return (int) $matches[1];
        }

        // Before April the current leave year is still the previous calendar year.
        return (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
    }

    /** Treat 'all', '0' and empty string as "no filter". */
    protected function activeFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = array_values(array_filter($value, fn ($item) => $item !== null && $item !== '' && $item !== '0' && $item !== 'all'));
            return empty($value) ? null : implode(',', $value);
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }

    /** Normalise a repeatable filter into a clean list. */
    protected function filterList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = explode(',', (string) $value);
        }

        return array_values(array_filter(array_map('trim', $value), function ($item) {
            return $item !== '' && $item !== '0' && strtolower($item) !== 'all';
        }));
    }
}
