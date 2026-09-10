<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Organization\TenantSettings;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ORGANISATION DEFAULTS, SECURITY POLICY, AND THE AUDIT TRAIL.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THREE SECTIONS, ONE CONTROLLER, AND WHY THAT IS RIGHT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * All three answer "what is true of this ORGANISATION", they share one store
 * and one tenancy rule, and splitting them would mean three copies of the same
 * identity resolution. What they do NOT share is who may see them, so the audit
 * reader carries its own check: an auditor may read the trail and may not touch
 * a setting.
 *
 * ── THE SETTINGS ARE NOT ALL ENFORCED YET, AND THE API SAYS SO ──────────────
 *
 * `enforced` is returned per group. It is a fact about the product, not about
 * the organisation: the password rules are read by `PasswordController::rule()`
 * today, and the working week is stored and read by nothing yet.
 *
 * Reporting it is what lets the screen tell somebody which of their choices is
 * live and which is being kept for later, instead of implying all of them are.
 * This product has a documented history of controls that look live and do
 * nothing, and a settings screen is the worst possible place to repeat it.
 */
class OrganizationSettingsController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/organization/settings */
    public function show(Request $request, TenantSettings $settings)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = (int) $identity['sub_institute_id'];
        $all = $settings->all($tenantId);

        return response()->json([
            'status' => 1,
            'data' => [
                'settings' => $all,
                'working_days' => TenantSettings::workingDayNames($all['org.working_days']),
                'choices' => [
                    'date_format' => TenantSettings::DATE_FORMATS,
                    'number_format' => TenantSettings::NUMBER_FORMATS,
                    'week_start' => TenantSettings::WEEK_STARTS,
                ],
                /*
                 * WHICH OF THESE ACTUALLY DO ANYTHING TODAY.
                 *
                 * Read by the screen so it can label a group rather than let
                 * somebody believe a stored choice is being applied. Each flag
                 * is the answer to "does a reader for this exist yet", and each
                 * one flips to true in the change that adds the reader.
                 */
                'enforced' => [
                    // PasswordController::rule() is the single shared rule every
                    // password path uses, so raising the minimum takes effect
                    // at once, everywhere.
                    'password_policy' => true,
                    // InviteService::TOKEN_HOURS is still a class constant.
                    'invite_hours' => false,
                    // No login path consults this yet.
                    'otp_login' => false,
                    // Nothing reads the working week or the financial year.
                    'calendar' => false,
                    // The formatters across the frontend still hardcode a locale.
                    'formats' => false,
                ],
            ],
        ]);
    }

    /** PUT /api/organization/settings */
    public function update(Request $request, TenantSettings $settings)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE REQUEST KEYS HAVE NO DOTS IN THEM, AND THAT IS NOT COSMETIC
         * ═══════════════════════════════════════════════════════════════════
         *
         * The stored keys are prefixed - `security.password_min_length` - so one
         * tenant's rows stay legible when several modules keep settings side by
         * side. But a DOT IN A VALIDATION KEY MEANS NESTED ARRAY ACCESS in
         * Laravel: `'security.password_min_length' => ['min:8']` looks for
         * `$data['security']['password_min_length']`, finds nothing, and
         * `sometimes` then skips it silently.
         *
         * So the first version of this method validated NOTHING while saving
         * perfectly. A tenant could set its password minimum to 4 and get a 200.
         * Caught by an evidence check that asserted the refusal rather than
         * assuming it, and it is exactly the failure that reads as working.
         *
         * Flat request keys, mapped here to the prefixed storage keys. The map
         * is also an allow-list: a key not in it cannot be written.
         */
        $data = $request->validate([
            'week_start' => ['sometimes', Rule::in(TenantSettings::WEEK_STARTS)],
            // Seven characters, each 0 or 1. Rejected rather than coerced: a
            // malformed mask would silently make every day non-working.
            'working_days' => ['sometimes', 'string', 'regex:/^[01]{7}$/'],
            'financial_year_start_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'date_format' => ['sometimes', Rule::in(TenantSettings::DATE_FORMATS)],
            'number_format' => ['sometimes', Rule::in(TenantSettings::NUMBER_FORMATS)],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],

            /*
             * THE MINIMUM CANNOT GO BELOW 8.
             *
             * `PasswordController::rule()` is built on `Password::min(8)`, and
             * it is what every password path shares. An organisation may make
             * its own rule stricter; it may not make the product's rule weaker,
             * because the same rule protects the invite and reset flows that
             * reach accounts across the whole platform. `rule()` clamps it a
             * second time, so a direct database edit cannot weaken it either.
             */
            'password_min_length' => ['sometimes', 'integer', 'min:8', 'max:64'],
            'password_require_symbol' => ['sometimes', 'boolean'],
            'invite_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'otp_login_enabled' => ['sometimes', 'boolean'],
        ], [
            'password_min_length.min' =>
                'The minimum password length cannot be set below 8 - that is the product-wide rule.',
            'working_days.regex' =>
                'Working days must be seven digits, one per day from Monday, each 0 or 1.',
        ]);

        /** Request key => stored key. Also the allow-list. */
        $map = [
            'week_start' => 'org.week_start',
            'working_days' => 'org.working_days',
            'financial_year_start_month' => 'org.financial_year_start_month',
            'currency' => 'org.currency',
            'date_format' => 'org.date_format',
            'number_format' => 'org.number_format',
            'timezone' => 'org.timezone',
            'password_min_length' => 'security.password_min_length',
            'password_require_symbol' => 'security.password_require_symbol',
            'invite_hours' => 'security.invite_hours',
            'otp_login_enabled' => 'security.otp_login_enabled',
        ];

        $booleans = ['password_require_symbol', 'otp_login_enabled'];
        $values = [];

        foreach ($map as $from => $to) {
            if (!array_key_exists($from, $data)) {
                continue;
            }

            // Booleans arrive as true/false and are stored as '1'/'0', so a
            // screen reading them back gets the same shape it sent.
            $values[$to] = in_array($from, $booleans, true)
                ? (filter_var($data[$from], FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : (string) $data[$from];
        }

        if ($values !== []) {
            $settings->save((int) $identity['sub_institute_id'], $values);
        }

        return $this->show($request, $settings);
    }

    /**
     * GET /api/organization/audit
     *
     * ── READ-ONLY, AND THAT IS THE WHOLE POINT ──────────────────────────────
     *
     * There is no write path here and there must not be one. An audit trail
     * that can be edited from the product it audits is not an audit trail.
     * `g2g_audit_log` is written by `AuditLogProjector` from the event stream,
     * never by a request.
     *
     * ── AN AUDITOR MAY READ THIS AND MAY NOT TOUCH THE SETTINGS ─────────────
     *
     * Which is why this method carries its own role check rather than sharing
     * the route group above. `RoleKey::forUserId` is the same resolution every
     * other guard uses, so the vocabulary cannot drift.
     */
    public function audit(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $role = RoleKey::forUserId((int) $identity['user_id']);

        if (!in_array($role, ['administrator', 'auditor'], true)) {
            return response()->json([
                'status' => 0,
                'message' => 'The audit trail is available to administrators and auditors.',
            ], 403);
        }

        $tenantId = (int) $identity['sub_institute_id'];

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE FILTER IS `event_type`, NOT `type` - AND THAT IS NOT COSMETIC
         * ═══════════════════════════════════════════════════════════════════
         *
         * `type=API` is this product's transport marker. Every frontend service
         * sends it on every call, and a dozen controllers read it. So an
         * endpoint with a FILTER called `type` is a landmine: the audit screen's
         * own auth helper sent `type: 'api'` in the query string, the filter
         * matched it, and every default page load ran `where a.type = 'api'`.
         *
         * `g2g_audit_log.type` holds dotted event names copied from the event
         * stream - `rights.changed`, `leave.decided` - so 'api' matches nothing.
         * The screen reported "Nothing recorded yet" on an organisation with a
         * full history, which is the most misleading failure an audit trail can
         * have: it under-reports, and it looks correctly wired while doing it.
         *
         * Renaming the filter fixes it for every caller at once, rather than
         * relying on each one remembering not to send a reserved word.
         */
        $request->validate([
            'event_type' => ['sometimes', 'string', 'max:64'],
            'actor_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'cursor' => ['sometimes', 'integer', 'min:0'],
        ]);

        $perPage = 50;

        $query = DB::table('g2g_audit_log as a')
            ->leftJoin('tbluser as u', 'u.id', '=', 'a.actor_id')
            ->where('a.sub_institute_id', $tenantId)
            ->when($request->filled('event_type'), fn ($q) => $q->where('a.type', $request->input('event_type')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('a.actor_id', (int) $request->input('actor_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('a.occurred_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('a.occurred_at', '<=', $request->input('to') . ' 23:59:59'));

        // Counted before the page is taken, so "showing 50 of 1,204" is true.
        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('a.occurred_at')
            ->orderByDesc('a.id')
            ->when($request->filled('cursor'), fn ($q) => $q->where('a.id', '<', (int) $request->input('cursor')))
            ->limit($perPage)
            ->get([
                'a.id', 'a.type', 'a.entity_type', 'a.entity_id',
                'a.actor_id', 'a.detail', 'a.occurred_at',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as actor_name"),
            ]);

        return response()->json([
            'status' => 1,
            'data' => [
                'entries' => $rows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'type' => $row->type,
                    'entity_type' => $row->entity_type,
                    'entity_id' => $row->entity_id,
                    'actor_id' => $row->actor_id ? (int) $row->actor_id : null,
                    // Null when the actor's account has since been removed. The
                    // entry survives them, which is the point of a trail.
                    'actor_name' => $row->actor_name ?: null,
                    'detail' => $row->detail,
                    'occurred_at' => $row->occurred_at,
                ])->values(),
                'total' => $total,
                'next_cursor' => $rows->count() === $perPage ? (int) $rows->last()->id : null,
                // The types actually present for this tenant, so the filter
                // offers what exists rather than a hardcoded list that may name
                // events this organisation has never produced.
                'types' => DB::table('g2g_audit_log')
                    ->where('sub_institute_id', $tenantId)
                    ->distinct()
                    ->orderBy('type')
                    ->pluck('type'),
            ],
        ]);
    }
}
