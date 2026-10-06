<?php

namespace App\Http\Controllers\Api\Onboarding;

use App\Http\Controllers\Api\Onboarding\Concerns\ResolvesOnboardingContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The data behind the five onboarding workstream cards.
 *
 * ── WHY ONE CONTROLLER FOR FIVE AREAS ───────────────────────────────────────
 *
 * They are one screen and one decision - "is this person ready to start?" - and
 * the panels read together. Five controllers would mean five round trips to
 * render one tab, and five places for the tenant guard to drift.
 *
 * ── THREE OF THE FIVE STORE HERE; TWO DELIBERATELY DO NOT ───────────────────
 *
 *   assets / benefits / acknowledgements  -> the tables created by
 *       2026_09_04_130000_create_onboarding_capture_tables. Nothing existed.
 *
 *   payroll   -> writes the columns tbluser ALREADY has (bank_name, account_no,
 *       ifsc_code, pan_no, aadhar_no, pf_no, esic_no, uan_no, and the deduction
 *       flags). A second table would be a second truth about somebody's UAN, and
 *       the Employee Directory reads these same columns.
 *
 *   learning  -> READS lms_course_enroll. LearningAssigner already writes those
 *       rows when employee.role_assigned fires, which now happens on hire. A
 *       parallel list of "what this person must learn" is precisely the drift
 *       EmployeeFactory and OnboardingJourneyFactory exist to prevent.
 *
 * Tenant comes from the token's owner via talentContext(); a journey belonging
 * to another organisation returns 404, never 403.
 */
class OnboardingWorkstreamController extends Controller
{
    use ResolvesOnboardingContext;

    /** VARCHAR + const, never ENUM - adding a type must not be an ALTER on live. */
    public const ASSET_TYPES = [
        'laptop'            => 'Laptop',
        'desktop'           => 'Desktop',
        'monitor'           => 'Monitor',
        'phone'             => 'Phone',
        'sim'               => 'SIM card',
        'access_card'       => 'Access card',
        'headset'           => 'Headset',
        'software_licence'  => 'Software licence',
        'other'             => 'Other',
    ];

    public const ASSET_STATUSES = ['issued', 'returned', 'lost', 'damaged'];

    public const BENEFIT_TYPES = [
        'health'    => 'Group health cover',
        'life'      => 'Group life cover',
        'accident'  => 'Personal accident cover',
        'gratuity'  => 'Gratuity',
        'meal_card' => 'Meal card',
        'transport' => 'Transport allowance',
        'other'     => 'Other',
    ];

    public const BENEFIT_STATUSES = ['enrolled', 'pending', 'declined', 'ended'];

    /**
     * The nine tbluser columns that ARE payroll setup for a new hire.
     *
     * Enumerated rather than taken from the request, so this endpoint can never
     * be used to write an arbitrary column on tbluser - it sits behind the same
     * token as everything else and tbluser has 99 columns.
     */
    public const PAYROLL_FIELDS = [
        'bank_name', 'branch_name', 'account_no', 'ifsc_code',
        'pan_no', 'aadhar_no', 'pf_no', 'esic_no', 'uan_no',
    ];

    /** Boolean deduction flags, kept separate because they cast differently. */
    public const PAYROLL_FLAGS = ['pf_deduction', 'tds_deduction', 'pt_deduction'];

    /**
     * GET /api/onboarding/journeys/{journeyId}/workstream-data
     *
     * Everything the five panels need, in one call.
     */
    public function show(Request $request, int $journeyId)
    {
        $context = $this->onboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $journey = DB::table('talent_onboarding_journeys')
            ->where('id', $journeyId)->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->first(['id', 'employee_id', 'candidate_name', 'joining_date']);

        if (!$journey) {
            return $this->onboardingError('Onboarding journey not found', 404);
        }

        $employeeId = $journey->employee_id ? (int) $journey->employee_id : null;

        return $this->onboardingResponse([
            'journey_id'  => (int) $journey->id,
            'employee_id' => $employeeId,
            'assets'      => $this->assetsFor($tenant, $journeyId, $employeeId),
            'benefits'    => $this->benefitsFor($tenant, $journeyId, $employeeId),
            'policies'    => $this->policiesFor($tenant, $journeyId, $employeeId),
            'payroll'     => $this->payrollFor($tenant, $employeeId),
            'learning'    => $this->learningFor($tenant, $employeeId),
            'options'     => [
                'asset_types'      => self::ASSET_TYPES,
                'asset_statuses'   => self::ASSET_STATUSES,
                'benefit_types'    => self::BENEFIT_TYPES,
                'benefit_statuses' => self::BENEFIT_STATUSES,
            ],
        ]);
    }

    /** POST /api/onboarding/journeys/{journeyId}/assets */
    public function storeAsset(Request $request, int $journeyId)
    {
        $context = $this->onboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = $this->journeyOr404($tenant, $journeyId);
        if (!is_object($journey)) {
            return $journey;
        }

        $validator = Validator::make($request->all(), [
            'asset_type'     => 'required|string|in:' . implode(',', array_keys(self::ASSET_TYPES)),
            'make_model'     => 'nullable|string|max:191',
            'serial_no'      => 'nullable|string|max:100',
            'issued_on'      => 'nullable|date',
            'condition_note' => 'nullable|string|max:2000',
            'status'         => 'nullable|string|in:' . implode(',', self::ASSET_STATUSES),
        ]);

        if ($validator->fails()) {
            return $this->onboardingError($validator->errors()->first(), 422);
        }

        /*
         * A serial number is unique to a device, so the same one appearing twice
         * in one organisation means either a typo or a device issued to two
         * people. Refused with the holder named, rather than silently creating
         * the second row that makes an audit impossible to resolve.
         */
        $serial = trim((string) $request->input('serial_no'));

        if ($serial !== '') {
            $held = DB::table('talent_onboarding_assets as a')
                ->leftJoin('tbluser as u', 'u.id', '=', 'a.employee_id')
                ->where('a.sub_institute_id', $tenant)
                ->where('a.serial_no', $serial)
                ->whereNull('a.deleted_at')
                ->whereNull('a.returned_on')
                ->first(['a.id', 'u.first_name', 'u.last_name']);

            if ($held) {
                $holder = trim(($held->first_name ?? '') . ' ' . ($held->last_name ?? '')) ?: 'another employee';

                return $this->onboardingError(
                    'Serial ' . $serial . ' is already issued to ' . $holder
                    . ' and has not been returned.',
                    422
                );
            }
        }

        $id = DB::table('talent_onboarding_assets')->insertGetId([
            'sub_institute_id' => $tenant,
            'journey_id'       => $journeyId,
            'employee_id'      => $journey->employee_id,
            'asset_type'       => $request->input('asset_type'),
            'make_model'       => $request->input('make_model'),
            'serial_no'        => $serial !== '' ? $serial : null,
            'issued_on'        => $request->input('issued_on') ?: now()->toDateString(),
            'condition_note'   => $request->input('condition_note'),
            'status'           => $request->input('status', 'issued'),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->logOnboardingActivity(
            $tenant, $context['user_id'], 'issued_asset',
            'issued ' . (self::ASSET_TYPES[$request->input('asset_type')] ?? 'an asset')
            . ($serial !== '' ? ' (' . $serial . ')' : ''),
            'asset', $id, $journey->candidate_name, null, $journeyId
        );

        return $this->onboardingResponse(['id' => $id], 'Asset recorded.');
    }

    /** POST /api/onboarding/assets/{id}/return */
    public function returnAsset(Request $request, int $assetId)
    {
        $context = $this->onboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $updated = DB::table('talent_onboarding_assets')
            ->where('id', $assetId)->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->update([
                'returned_on'    => $request->input('returned_on') ?: now()->toDateString(),
                'status'         => $request->input('status', 'returned'),
                'condition_note' => $request->input('condition_note'),
                'updated_by'     => $context['user_id'],
                'updated_at'     => now(),
            ]);

        return $updated
            ? $this->onboardingResponse(['id' => $assetId], 'Asset marked returned.')
            : $this->onboardingError('Asset not found', 404);
    }

    /** POST /api/onboarding/journeys/{journeyId}/benefits */
    public function storeBenefit(Request $request, int $journeyId)
    {
        $context = $this->onboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = $this->journeyOr404($tenant, $journeyId);
        if (!is_object($journey)) {
            return $journey;
        }

        /*
         * A benefit belongs to an EMPLOYEE, not a candidate. The column is NOT
         * NULL for that reason: enrolling somebody in group life cover before
         * they exist as an employee produces a policy nobody can claim on.
         */
        if (!$journey->employee_id) {
            return $this->onboardingError(
                'This journey has no employee yet, so benefits cannot be enrolled. '
                . 'The employee record is created when the offer is accepted.',
                422
            );
        }

        $validator = Validator::make($request->all(), [
            'benefit_type'     => 'required|string|in:' . implode(',', array_keys(self::BENEFIT_TYPES)),
            'provider'         => 'nullable|string|max:191',
            'policy_no'        => 'nullable|string|max:100',
            'coverage_amount'  => 'nullable|numeric|min:0',
            'effective_from'   => 'nullable|date',
            'nominee_name'     => 'nullable|string|max:191',
            'nominee_relation' => 'nullable|string|max:50',
            'status'           => 'nullable|string|in:' . implode(',', self::BENEFIT_STATUSES),
        ]);

        if ($validator->fails()) {
            return $this->onboardingError($validator->errors()->first(), 422);
        }

        $id = DB::table('talent_employee_benefits')->insertGetId([
            'sub_institute_id' => $tenant,
            'journey_id'       => $journeyId,
            'employee_id'      => (int) $journey->employee_id,
            'benefit_type'     => $request->input('benefit_type'),
            'provider'         => $request->input('provider'),
            'policy_no'        => $request->input('policy_no'),
            'coverage_amount'  => $request->input('coverage_amount'),
            'effective_from'   => $request->input('effective_from') ?: $journey->joining_date,
            'nominee_name'     => $request->input('nominee_name'),
            'nominee_relation' => $request->input('nominee_relation'),
            'status'           => $request->input('status', 'enrolled'),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->logOnboardingActivity(
            $tenant, $context['user_id'], 'enrolled_benefit',
            'enrolled in ' . (self::BENEFIT_TYPES[$request->input('benefit_type')] ?? 'a benefit'),
            'benefit', $id, $journey->candidate_name, null, $journeyId
        );

        return $this->onboardingResponse(['id' => $id], 'Benefit recorded.');
    }

    /** POST /api/onboarding/journeys/{journeyId}/acknowledge-policy */
    public function acknowledgePolicy(Request $request, int $journeyId)
    {
        $context = $this->onboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = $this->journeyOr404($tenant, $journeyId);
        if (!is_object($journey)) {
            return $journey;
        }

        if (!$journey->employee_id) {
            return $this->onboardingError(
                'This journey has no employee yet, so a policy cannot be acknowledged against them.',
                422
            );
        }

        $validator = Validator::make($request->all(), [
            'policy_key'     => 'required|string|max:100',
            'policy_title'   => 'nullable|string|max:191',
            'policy_version' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->onboardingError($validator->errors()->first(), 422);
        }

        $version = (string) ($request->input('policy_version') ?: '1.0');

        /*
         * updateOrInsert on (employee, policy, version), matching the unique key.
         *
         * Acknowledging the SAME version twice is not a second fact - it is the
         * same person confirming the same document, and a second row would
         * inflate any compliance count. Acknowledging a NEW version is a new
         * fact and gets its own row, which is the whole reason the version is in
         * the key.
         */
        DB::table('talent_policy_acknowledgements')->updateOrInsert(
            [
                'employee_id'    => (int) $journey->employee_id,
                'policy_key'     => $request->input('policy_key'),
                'policy_version' => $version,
            ],
            [
                'sub_institute_id' => $tenant,
                'journey_id'       => $journeyId,
                'policy_title'     => $request->input('policy_title'),
                'acknowledged_at'  => now(),
                // Recorded because "who signed it" is only half of an audit answer.
                'acknowledged_ip'  => mb_substr((string) $request->ip(), 0, 45),
                'updated_by'       => $context['user_id'],
                'updated_at'       => now(),
                'created_at'       => now(),
            ]
        );

        $this->logOnboardingActivity(
            $tenant, $context['user_id'], 'acknowledged_policy',
            'acknowledged ' . ($request->input('policy_title') ?: $request->input('policy_key'))
            . ' v' . $version,
            'policy', null, $journey->candidate_name, null, $journeyId
        );

        return $this->onboardingResponse(['acknowledged' => true], 'Policy acknowledged.');
    }

    /**
     * PUT /api/onboarding/journeys/{journeyId}/payroll
     *
     * Writes the tbluser columns that already exist. No new table: the Employee
     * Directory reads these same columns, and two homes for a UAN is how they
     * come to disagree.
     */
    public function savePayroll(Request $request, int $journeyId)
    {
        $context = $this->onboardingContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $journey = $this->journeyOr404($tenant, $journeyId);
        if (!is_object($journey)) {
            return $journey;
        }

        if (!$journey->employee_id) {
            return $this->onboardingError(
                'This journey has no employee yet, so payroll details cannot be saved. '
                . 'The employee record is created when the offer is accepted.',
                422
            );
        }

        $validator = Validator::make($request->all(), [
            'bank_name'   => 'nullable|string|max:191',
            'branch_name' => 'nullable|string|max:191',
            'account_no'  => 'nullable|string|max:50',
            'ifsc_code'   => 'nullable|string|max:20',
            // Format-checked, not just length: a PAN that is not a PAN fails at
            // the TDS return, months later, for somebody else to unpick.
            'pan_no'      => 'nullable|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/',
            'aadhar_no'   => 'nullable|string|regex:/^[0-9]{12}$/',
            'pf_no'       => 'nullable|string|max:50',
            'esic_no'     => 'nullable|string|max:50',
            'uan_no'      => 'nullable|string|regex:/^[0-9]{12}$/',
            'pf_deduction'  => 'nullable|boolean',
            'tds_deduction' => 'nullable|boolean',
            'pt_deduction'  => 'nullable|boolean',
        ], [
            'pan_no.regex'    => 'A PAN looks like ABCDE1234F.',
            'aadhar_no.regex' => 'An Aadhaar number is 12 digits.',
            'uan_no.regex'    => 'A UAN is 12 digits.',
        ]);

        if ($validator->fails()) {
            return $this->onboardingError($validator->errors()->first(), 422);
        }

        /*
         * ONLY FIELDS THAT WERE SENT. Absent means "leave it alone", so saving
         * the bank half of this form cannot blank a UAN captured earlier - the
         * same rule that F-69b and the mobility remarks fix exist to enforce.
         */
        $changes = [];

        foreach (self::PAYROLL_FIELDS as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->input($field) ?: null;
            }
        }

        foreach (self::PAYROLL_FLAGS as $flag) {
            if ($request->has($flag)) {
                $changes[$flag] = $request->boolean($flag) ? 1 : 0;
            }
        }

        if ($changes === []) {
            return $this->onboardingError('Nothing to save.', 422);
        }

        $changes['updated_at'] = now();

        DB::table('tbluser')
            ->where('id', $journey->employee_id)
            ->where('sub_institute_id', $tenant)
            ->update($changes);

        $this->logOnboardingActivity(
            $tenant, $context['user_id'], 'saved_payroll',
            'saved payroll details (' . implode(', ', array_keys(array_diff_key($changes, ['updated_at' => 1]))) . ')',
            'payroll', (int) $journey->employee_id, $journey->candidate_name, null, $journeyId
        );

        return $this->onboardingResponse(
            $this->payrollFor($tenant, (int) $journey->employee_id),
            'Payroll details saved.'
        );
    }

    // ── readers ─────────────────────────────────────────────────────────────

    private function assetsFor(int $tenant, int $journeyId, ?int $employeeId): array
    {
        return DB::table('talent_onboarding_assets')
            ->where('sub_institute_id', $tenant)
            ->where(function ($q) use ($journeyId, $employeeId) {
                $q->where('journey_id', $journeyId);
                if ($employeeId) {
                    $q->orWhere('employee_id', $employeeId);
                }
            })
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id', 'asset_type', 'make_model', 'serial_no', 'issued_on', 'returned_on', 'status', 'condition_note'])
            ->map(fn ($a) => (array) $a + ['type_label' => self::ASSET_TYPES[$a->asset_type] ?? $a->asset_type])
            ->all();
    }

    private function benefitsFor(int $tenant, int $journeyId, ?int $employeeId): array
    {
        if (!$employeeId) {
            return [];
        }

        return DB::table('talent_employee_benefits')
            ->where('sub_institute_id', $tenant)
            ->where('employee_id', $employeeId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id', 'benefit_type', 'provider', 'policy_no', 'coverage_amount', 'effective_from',
                   'nominee_name', 'nominee_relation', 'status'])
            ->map(fn ($b) => (array) $b + ['type_label' => self::BENEFIT_TYPES[$b->benefit_type] ?? $b->benefit_type])
            ->all();
    }

    private function policiesFor(int $tenant, int $journeyId, ?int $employeeId): array
    {
        if (!$employeeId) {
            return [];
        }

        return DB::table('talent_policy_acknowledgements')
            ->where('sub_institute_id', $tenant)
            ->where('employee_id', $employeeId)
            ->whereNull('deleted_at')
            ->orderByDesc('acknowledged_at')
            ->get(['id', 'policy_key', 'policy_title', 'policy_version', 'acknowledged_at'])
            ->all();
    }

    /** @return array<string, mixed> */
    private function payrollFor(int $tenant, ?int $employeeId): array
    {
        if (!$employeeId) {
            return ['employee_id' => null, 'complete' => false, 'fields' => []];
        }

        $row = DB::table('tbluser')
            ->where('id', $employeeId)->where('sub_institute_id', $tenant)
            ->first(array_merge(self::PAYROLL_FIELDS, self::PAYROLL_FLAGS));

        $fields = $row ? (array) $row : [];

        /*
         * "Complete" means payroll can actually run: an account with no IFSC
         * cannot be paid, and no PAN means TDS at 20%. The deduction flags and
         * the optional ESIC number are excluded from the test on purpose.
         */
        $required = ['bank_name', 'account_no', 'ifsc_code', 'pan_no'];
        $missing = array_values(array_filter($required, fn ($f) => empty($fields[$f])));

        return [
            'employee_id' => $employeeId,
            'fields'      => $fields,
            'complete'    => $missing === [],
            'missing'     => $missing,
        ];
    }

    /**
     * What LearningAssigner already assigned. This READS; it never writes.
     */
    private function learningFor(int $tenant, ?int $employeeId): array
    {
        if (!$employeeId) {
            return ['total' => 0, 'completed' => 0, 'courses' => []];
        }

        $rows = DB::table('lms_course_enroll')
            ->where('user_id', $employeeId)
            ->when(
                $this->columnExists('lms_course_enroll', 'sub_institute_id'),
                fn ($q) => $q->where('sub_institute_id', $tenant)
            )
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $courses = $rows->map(fn ($r) => [
            'id'     => (int) ($r->id ?? 0),
            'course' => $r->course_name ?? $r->title ?? ('Course ' . ($r->course_id ?? '?')),
            'status' => $r->status ?? 'assigned',
        ])->all();

        return [
            'total'     => count($courses),
            'completed' => count(array_filter($courses, fn ($c) => strtolower((string) $c['status']) === 'completed')),
            'courses'   => $courses,
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return object|\Illuminate\Http\JsonResponse */
    private function journeyOr404(int $tenant, int $journeyId)
    {
        $journey = DB::table('talent_onboarding_journeys')
            ->where('id', $journeyId)->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->first(['id', 'employee_id', 'candidate_name', 'joining_date']);

        // 404, never 403: a 403 confirms the row exists in someone else's tenant.
        return $journey ?: $this->onboardingError('Onboarding journey not found', 404);
    }

    private function columnExists(string $table, string $column): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ));
    }
}
