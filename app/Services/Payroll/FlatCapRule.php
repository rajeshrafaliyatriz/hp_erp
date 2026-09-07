<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

/**
 * What a flat pay head's configured cap MEANS, per organisation. F-111.
 *
 * When a pay head is Flat (`amount_type = 1`), `payroll_types.payroll_percentage`
 * holds a non-zero cap, and the figure entered for an employee is higher than
 * it, two different things can be intended:
 *
 *     excess   amount = entered - cap     "pay the part above the cap"
 *     clamp    amount = cap               "never pay more than the cap"
 *
 * This was `$sub_institute_id == 47`, written inline in the middle of a salary
 * calculation. Sprint 1 moved the id to config/payroll.php with the arithmetic
 * byte-identical; this finishes the job by making it a per-organisation setting
 * that can be seen and changed, rather than a deploy-time list.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ARITHMETIC IS STILL UNCHANGED. This class decides WHICH of the two
 * existing branches runs; it does not compute anything. Q1 - which behaviour
 * *should* be the default - remains a domain question, and unifying the two
 * branches blind would change what somebody is paid.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * RESOLUTION ORDER, and the last line is the one that matters:
 *
 *   1. tenant_setting.payroll.flat_cap_behaviour  - the tenant's own choice
 *   2. config('payroll.excess_over_flat_amount_tenants') - the Sprint 1 list,
 *      kept so an environment that has not been migrated behaves as it did
 *   3. CLAMP
 *
 * Absence means CLAMP, deliberately. A tenant nobody has configured must get the
 * behaviour it has today, and an unconfigured setting must never be the reason
 * somebody's pay moves. The seeding migration writes an explicit `excess` row
 * for every tenant currently on the list, so no live institute changes.
 *
 * `tenant_setting` is reused rather than adding a table: it exists, it is
 * (sub_institute_id, setting_key, setting_value), and ReportingLineValidator
 * already reads it the same way.
 */
class FlatCapRule
{
    public const KEY = 'payroll.flat_cap_behaviour';

    public const EXCESS = 'excess';
    public const CLAMP  = 'clamp';

    /** Resolved settings per tenant, so a payroll run of 122 employees asks once. */
    private static array $cache = [];

    /**
     * Does this organisation pay the excess over a flat cap, or clamp to it?
     */
    public function behaviourFor(?int $subInstituteId): string
    {
        if (!$subInstituteId) {
            return self::CLAMP;
        }

        if (array_key_exists($subInstituteId, self::$cache)) {
            return self::$cache[$subInstituteId];
        }

        $stored = DB::table('tenant_setting')
            ->where('sub_institute_id', $subInstituteId)
            ->where('setting_key', self::KEY)
            ->value('setting_value');

        if ($stored === self::EXCESS || $stored === self::CLAMP) {
            return self::$cache[$subInstituteId] = $stored;
        }

        // Nothing stored. Fall back to the Sprint 1 list so an environment that
        // has not run the seeding migration keeps the behaviour it has now.
        $legacy = in_array(
            $subInstituteId,
            array_map('intval', (array) config('payroll.excess_over_flat_amount_tenants', [])),
            true
        );

        return self::$cache[$subInstituteId] = $legacy ? self::EXCESS : self::CLAMP;
    }

    /** The question the salary calculation actually asks. */
    public function paysExcessOverCap(?int $subInstituteId): bool
    {
        return $this->behaviourFor($subInstituteId) === self::EXCESS;
    }

    /**
     * Set it, or clear it back to the default.
     *
     * Not wired to a screen yet - HR has nowhere to change this today, and
     * pretending otherwise would be the "configuration that controls nothing"
     * defect this module has already been through twice (F-124, F-89). It is
     * here so the setting has one writer when a screen arrives.
     */
    public function setBehaviour(int $subInstituteId, ?string $behaviour): void
    {
        unset(self::$cache[$subInstituteId]);

        if ($behaviour === null) {
            DB::table('tenant_setting')
                ->where('sub_institute_id', $subInstituteId)
                ->where('setting_key', self::KEY)
                ->delete();

            return;
        }

        if (!in_array($behaviour, [self::EXCESS, self::CLAMP], true)) {
            throw new \InvalidArgumentException(
                'A flat-cap behaviour is "' . self::EXCESS . '" or "' . self::CLAMP . '", not '
                . var_export($behaviour, true) . '. Guessing here would change what somebody is paid.'
            );
        }

        DB::table('tenant_setting')->updateOrInsert(
            ['sub_institute_id' => $subInstituteId, 'setting_key' => self::KEY],
            ['setting_value' => $behaviour, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Tests and migrations reuse the process; the cache must not outlive them. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
