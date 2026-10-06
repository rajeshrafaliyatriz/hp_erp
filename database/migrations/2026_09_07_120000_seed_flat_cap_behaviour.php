<?php

use App\Services\Payroll\FlatCapRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the flat-cap rule a per-organisation setting. F-111.
 *
 * It began as `$sub_institute_id == 47` inline in a salary calculation. Sprint 1
 * moved the id to config/payroll.php with the arithmetic byte-identical; this
 * makes it a setting an organisation owns rather than a deploy-time list.
 *
 * NOBODY'S PAY CHANGES. That is the whole design constraint, and it is why the
 * seeding is explicit rather than implicit:
 *
 *   - every tenant currently on the config list gets an explicit `excess` row,
 *     so it keeps paying the excess over the cap exactly as it does today;
 *   - every other tenant gets NOTHING, and absence resolves to `clamp` - which
 *     is what they do today.
 *
 * An unconfigured setting must never be the reason a payslip moves.
 *
 * Q1 - which of the two behaviours *should* be the default - is still open, and
 * this migration does not answer it. It makes the question askable of a tenant
 * instead of of a constant, which is the part that was in the way.
 *
 * NOTE ON REACH: tenant 47 lives on lms.triz.co.in/triz_erp_21, not on the app's
 * own database, so this seeds nothing there. Whichever deployment holds a listed
 * tenant gets its row when this runs against that database. Until then the
 * config fallback in FlatCapRule keeps its behaviour unchanged - which is
 * precisely why that fallback exists rather than being cleaned away.
 */
return new class extends Migration
{
    public function up(): void
    {
        $listed = array_values(array_filter(array_map(
            'intval',
            (array) config('payroll.excess_over_flat_amount_tenants', [])
        )));

        if ($listed === []) {
            return;
        }

        // Only tenants that actually exist here. Writing a setting row for a
        // tenant this database has never heard of would be noise, and the
        // config fallback covers them anyway.
        $present = DB::table('school_setup')->whereIn('id', $listed)->pluck('id')->all();

        foreach ($present as $tenantId) {
            DB::table('tenant_setting')->updateOrInsert(
                ['sub_institute_id' => $tenantId, 'setting_key' => FlatCapRule::KEY],
                [
                    'setting_value' => FlatCapRule::EXCESS,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]
            );
        }

        FlatCapRule::forget();
    }

    public function down(): void
    {
        DB::table('tenant_setting')->where('setting_key', FlatCapRule::KEY)->delete();

        FlatCapRule::forget();
    }
};
