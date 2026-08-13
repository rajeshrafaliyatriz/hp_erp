<?php

namespace App\Http\Controllers\Api\Leave\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

/**
 * Shared request context resolution for the Leave Management API.
 *
 * Every endpoint under /api/leave is token authenticated (Sanctum personal
 * access token passed as `token`), and every query is scoped by
 * sub_institute_id + the April-March leave year.
 */
trait ResolvesLeaveContext
{
    use ResolvesApiIdentity;

    /**
     * @return array{sub_institute_id:int, user_id:int|null, year:int, from:string, to:string}|\Illuminate\Http\JsonResponse
     */
    /**
     * Roles that may act on somebody else's leave. Keyed on role_key (D-010).
     *
     * department_head and reporting_manager are absent for the same reason as in
     * ResolvesCompetencyContext: their scope is "my department" / "my team", and
     * neither is evaluable while tbluser.reporting_manager_id is NULL for every
     * user (G-ORG-02). They return with reporting-line coverage.
     */
    private const LEAVE_ELEVATED = [
        'administrator', 'hr_manager', 'hr_executive',
    ];

    /**
     * The employee a leave request or balance is FOR.
     *
     * G-LEAVE-SEC-01. The pattern replaced here was
     *
     *     $userId = (int) ($request->input('employee_id') ?: $context['user_id']);
     *
     * REQUEST-FIRST WITH A SAFE-LOOKING FALLBACK. It survives review because the
     * caller appears in the expression - a reviewer scanning for "where does the
     * subject come from" sees $context['user_id'] and moves on. The fallback only
     * ever fires for the honest caller; anyone supplying employee_id bypasses it.
     *
     * REACH CHAIN, established before reporting (the boundary rule):
     *   route      routes/api.php:521 `Route::prefix('leave')->group(...)`
     *   middleware NONE on the group - the controller's own context guard is the
     *              entire control
     *   callers    LeaveRequestApiController::store():146 and
     *              LeaveOptionsController::balances():96
     *
     * So nothing upstream supplied the missing check.
     *
     * @return int|\Illuminate\Http\JsonResponse
     */
    protected function leaveSubject(Request $request, array $context)
    {
        $callerId  = (int) ($context['user_id'] ?? 0);
        $requested = $request->input('employee_id');

        if ($requested === null || $requested === '' || (int) $requested === $callerId) {
            return $callerId;
        }

        $subjectId = (int) $requested;

        $inTenant = DB::table('tbluser')
            ->where('id', $subjectId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->exists();

        if (!$inTenant) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        $roleKey = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', $callerId)
            ->value('p.role_key');

        if (in_array((string) $roleKey, self::LEAVE_ELEVATED, true)) {
            return $subjectId;
        }

        return response()->json([
            'status'  => 0,
            'message' => 'You may only act on your own leave.',
        ], 403);
    }

    protected function leaveContext(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $year = $this->normaliseLeaveYear($request->input('syear') ?? $request->input('year'));

        return [
            'sub_institute_id' => $identity['sub_institute_id'],
            'user_id'          => $identity['user_id'],
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
