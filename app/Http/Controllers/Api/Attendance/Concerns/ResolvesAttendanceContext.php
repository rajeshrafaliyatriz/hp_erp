<?php

namespace App\Http\Controllers\Api\Attendance\Concerns;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared request context resolution for the Attendance Management API.
 *
 * The legacy attendance screens live on stateful web routes and read
 * sub_institute_id / user_id out of the session. The endpoints under
 * /api/attendance are stateless, so everything is resolved from the request
 * and authenticated with a Sanctum personal access token passed as `token`,
 * exactly like App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveContext.
 */
trait ResolvesAttendanceContext
{
    /**
     * @return array{sub_institute_id:int, user_id:int|null, syear:string|null}|\Illuminate\Http\JsonResponse
     */
    protected function attendanceContext(Request $request)
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
            'syear'            => $request->input('syear'),
        ];
    }

    /** Treat 'all', '0' and empty string as "no filter". */
    protected function activeFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = array_values(array_filter($value, fn ($item) => $item !== null && $item !== '' && $item !== '0' && $item !== 'all'));

            return empty($value) ? null : (string) reset($value);
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }
}
