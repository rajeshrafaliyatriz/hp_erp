<?php

namespace App\Http\Controllers\Api\Dashboard\Concerns;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CONTEXT AND ENVELOPE FOR THE HOME DASHBOARD.
 *
 * Builds on ResolvesApiIdentity, which owns the one rule that matters here:
 * THE TOKEN DECIDES. The request is never trusted for identity, and that
 * includes profile_id — the frontend sends it on every call from localStorage,
 * so it is a UI hint about which view to render, never a statement about what
 * data the caller may see. Authorisation is the `profile:admin,hr` middleware
 * on the route, which reads role_key from the TOKEN OWNER's profile.
 *
 * THE ENVELOPE IS PART OF THE CONTRACT, not decoration:
 *
 *   { status: 1, message, data }               status is an INT
 *   + empty_is_expected / empty_reason         siblings, OUTSIDE data
 *   + truncated / scope / meta
 *
 * Two legacy controllers in this codebase answer `'status' => true` and
 * `'status' => 'success'` instead. They are the exception being avoided, not a
 * precedent — a client that checks `status === 1` silently fails against them.
 */
trait ResolvesDashboardContext
{
    use ResolvesApiIdentity;

    /**
     * @return array{sub_institute_id:int, user_id:int|null}|JsonResponse
     */
    protected function dashboardContext(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        // resolveApiIdentity returns a JsonResponse on failure rather than
        // throwing, so the caller must check the type before using it.
        if (!is_array($identity)) {
            return $identity;
        }

        return [
            'sub_institute_id' => (int) $identity['sub_institute_id'],
            'user_id'          => isset($identity['user_id']) ? (int) $identity['user_id'] : null,
        ];
    }

    /**
     * The dashboard's filter dimensions.
     *
     * department_id is the only one that can filter people-shaped widgets.
     * location and business_unit are collected because the competency widgets
     * accept them, but they describe JOB ROLES (s_user_jobrole.location), not
     * employees — tbluser has no such column — so they cannot filter headcount,
     * attendance, tasks or departments. `filters()` reports that to the client
     * rather than letting a control look live and do nothing.
     *
     * @return array{department_id:?string, location:?string, business_unit:?string, from:?string, to:?string, syear:?string}
     */
    protected function dashboardFilters(Request $request): array
    {
        return [
            'department_id' => $this->activeFilter($request->input('department_id')),
            'location'      => $this->activeFilter($request->input('location')),
            'business_unit' => $this->activeFilter($request->input('business_unit')),
            'from'          => $this->activeFilter($request->input('from')),
            'to'            => $this->activeFilter($request->input('to')),
            'syear'         => $this->activeFilter($request->input('syear')),
        ];
    }

    /**
     * Collapse the several spellings of "no filter" to null.
     *
     * '', '0', 'all', 'All', [] all mean unfiltered. Without this, `where(...)`
     * gets the literal string 'all' and matches nothing, which reads on screen
     * as an empty organisation rather than as a filter bug.
     */
    protected function activeFilter($value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '0' || strcasecmp($value, 'all') === 0) {
            return null;
        }

        return $value;
    }

    /**
     * The success envelope.
     *
     * $extra carries the siblings that live OUTSIDE data — empty_is_expected,
     * empty_reason, truncated, scope, meta — so a caller cannot accidentally
     * bury them inside the payload where the client does not look for them.
     */
    protected function dashboardOk(array $data, string $message, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'status'            => 1,
            'message'           => $message,
            'data'              => $data,
            'empty_is_expected' => false,
            'empty_reason'      => null,
            'truncated'         => false,
            'scope'             => 'organisation',
        ], $extra));
    }

    /**
     * The failure envelope.
     *
     * NEVER pass $e->getMessage() in here. TalentDashboardController does, and
     * it hands the client SQL fragments, table names and file paths on any
     * unexpected error. Log the exception; tell the caller what failed.
     */
    protected function dashboardFail(string $message, int $code = 400, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'status'  => 0,
            'message' => $message,
        ], $extra), $code);
    }
}
