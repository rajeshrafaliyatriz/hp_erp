<?php

namespace App\Console\Commands;

use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Demo / starter data for Shift Master and Expense Management, for ONE
 * organisation, on whichever database you point it at.
 *
 *   php artisan hrit:seed-demo 6                       this database, tenant 6
 *   php artisan hrit:seed-demo 6 --database=live       the live database
 *   php artisan hrit:seed-demo 6 --only=shifts,categories
 *   php artisan hrit:seed-demo 6 --remove              take the demo claims/advances back out
 *
 * ── WHAT IT ADDS (each step can be chosen with --only) ──────────────────────
 *
 *   shifts         eight common shifts (General, Morning, Afternoon, Night, ...)
 *   categories     twelve expense categories with limits (Travel, Fuel, Meals, ...)
 *   expenses       advances and claims in every status, spread over the last
 *                  months, built from THIS organisation's real employees and
 *                  their real reporting managers
 *   assign-shifts  puts every ACTIVE employee who has no shift on "General Shift"
 *
 * ── SAFE TO RUN TWICE ───────────────────────────────────────────────────────
 *
 * Shifts and categories are matched by name and skipped if they exist. Demo
 * expenses are skipped if demo expenses are already there. assign-shifts only
 * touches employees with NO shift, and never changes anybody's existing one.
 *
 * ── DEMO EXPENSES CAN BE REMOVED ────────────────────────────────────────────
 *
 * Demo claims carry the description "[Demo data]" and demo advances the note
 * "[Demo data]". `--remove` deletes exactly those (and the lines on those
 * claims) for the organisation and nothing else. Shifts, categories and shift
 * assignments are starter data and are NOT removed by it - they are edited or
 * deleted on their own screens.
 *
 * ── WHAT IT NEEDS ───────────────────────────────────────────────────────────
 *
 * The migrations for these features must already have run on that database. The
 * demo expenses need at least one active, non-HR employee and at least one
 * administrator/HR user in the organisation (they decide and pay the claims);
 * without both it stops and says so, having written nothing. It asks before it
 * writes unless you pass --force.
 */
class SeedHritDemoData extends Command
{
    protected $signature = 'hrit:seed-demo
        {tenant : sub_institute_id of the organisation}
        {--database= : connection to use (e.g. live). Defaults to the app\'s own}
        {--only= : comma list of: shifts, categories, expenses, assign-shifts (default: all)}
        {--remove : remove the demo claims and advances instead of adding}
        {--force : do not ask for confirmation}';

    protected $description = 'Add (or remove) demo data for Shift Master and Expense Management for one organisation';

    private const MARK = '[Demo data]';

    private const STEPS = ['shifts', 'categories', 'expenses', 'assign-shifts'];

    /** name, code, start, end, break, grace, colour, description */
    private const SHIFTS = [
        ['General Shift', 'GS', '09:00', '18:00', 60, 10, '#2563eb', 'Normal office hours'],
        ['Morning Shift', 'MS', '06:00', '14:00', 30, 10, '#d97706', 'Morning shift'],
        ['Afternoon Shift', 'AS', '14:00', '22:00', 30, 10, '#16a34a', 'Afternoon shift'],
        ['Night Shift', 'NS', '22:00', '06:00', 45, 15, '#7c3aed', 'Overnight shift'],
        ['Early Shift', 'ES', '08:00', '16:30', 30, 5, '#0891b2', 'Early office shift'],
        ['Late Shift', 'LS', '11:00', '20:00', 60, 10, '#db2777', 'Late office shift'],
        ['Half Day (Morning)', 'HD', '09:00', '13:00', 0, 10, '#475569', 'Half day'],
        ['Flexible Shift', 'FX', '10:00', '19:00', 60, 60, '#dc2626', 'Flexible arrival window'],
    ];

    /** name, code, description, max per expense, bill required */
    private const CATEGORIES = [
        ['Travel', 'TRV', 'Local and outstation travel: bus, train, flight, taxi, auto', 25000, 1],
        ['Fuel', 'FUL', 'Fuel for personal vehicle used on official work', 5000, 1],
        ['Accommodation', 'ACC', 'Hotel and stay during official tours', 10000, 1],
        ['Meals & Food', 'MLS', 'Meals during official travel or client meetings', 1500, 0],
        ['Client Entertainment', 'CLE', 'Client meetings, lunches and hospitality', 10000, 1],
        ['Office Supplies', 'OFS', 'Stationery and small office items', 5000, 1],
        ['Internet & Mobile', 'INT', 'Mobile recharge and internet bills for work', 2000, 1],
        ['Training & Courses', 'TRN', 'Course fees, certifications, workshops', 50000, 1],
        ['Software & Subscriptions', 'SWS', 'Tools and subscriptions needed for work', 20000, 1],
        ['Parking & Toll', 'PKT', 'Parking and toll charges', 1000, 0],
        ['Medical (Work-related)', 'MED', 'Work-related medical and safety expenses', 10000, 1],
        ['Miscellaneous', 'MSC', 'Other work expenses that do not fit above', 3000, 1],
    ];

    public function handle(): int
    {
        if ($db = $this->option('database')) {
            if (!is_array(config("database.connections.$db"))) {
                $this->error("There is no database connection called '$db'.");

                return self::FAILURE;
            }
            Config::set('database.default', $db);
            DB::purge($db);
        }

        $tenant = (int) $this->argument('tenant');
        $connection = DB::connection()->getDatabaseName() . ' @ ' . DB::connection()->getConfig('host');

        if ($tenant < 1 || !DB::table('tbluser')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->exists()) {
            $this->error("No employees found for organisation (sub_institute_id) $tenant on $connection.");

            return self::FAILURE;
        }

        $this->line("Database: <info>$connection</info>   Organisation: <info>$tenant</info>");

        if ($this->option('remove')) {
            return $this->remove($tenant);
        }

        $steps = $this->option('only')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))))
            : self::STEPS;

        if ($bad = array_diff($steps, self::STEPS)) {
            $this->error('Unknown step(s): ' . implode(', ', $bad) . '. Choose from: ' . implode(', ', self::STEPS));

            return self::FAILURE;
        }

        $this->line('Will do: <info>' . implode(', ', $steps) . '</info>');

        if (!$this->option('force') && !$this->confirm('Write this to the database above?', false)) {
            $this->warn('Nothing written.');

            return self::SUCCESS;
        }

        // Expenses are the only step that can refuse, so it is checked first and
        // nothing at all is written if it would.
        $expenses = null;
        if (in_array('expenses', $steps, true)) {
            $expenses = $this->planExpenses($tenant, in_array('categories', $steps, true));
            if ($expenses === false) {
                return self::FAILURE;
            }
        }

        if (in_array('shifts', $steps, true)) {
            $this->seedShifts($tenant);
        }
        if (in_array('categories', $steps, true)) {
            $this->seedCategories($tenant);
        }
        if ($expenses) {
            $this->seedExpenses($tenant, $expenses);
        }
        if (in_array('assign-shifts', $steps, true)) {
            $this->assignShifts($tenant);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    // ── shifts / categories ─────────────────────────────────────────────────

    private function seedShifts(int $tenant): void
    {
        $added = 0;

        foreach (self::SHIFTS as [$name, $code, $start, $end, $break, $grace, $colour, $desc]) {
            if (DB::table('hrms_shifts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
                continue;
            }

            DB::table('hrms_shifts')->insert([
                'sub_institute_id' => $tenant, 'name' => $name, 'code' => $code,
                'start_time' => $start, 'end_time' => $end, 'is_overnight' => $end <= $start ? 1 : 0,
                'break_minutes' => $break, 'grace_minutes' => $grace, 'color' => $colour, 'description' => $desc,
                'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $added++;
        }

        $this->line("  shifts: added $added, already there " . (count(self::SHIFTS) - $added));
    }

    private function seedCategories(int $tenant): void
    {
        $added = 0;

        foreach (self::CATEGORIES as [$name, $code, $desc, $max, $receipt]) {
            if (DB::table('hrms_expense_categories')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
                continue;
            }

            DB::table('hrms_expense_categories')->insert([
                'sub_institute_id' => $tenant, 'name' => $name, 'code' => $code, 'description' => $desc,
                'max_amount' => $max, 'requires_receipt' => $receipt, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $added++;
        }

        $this->line("  categories: added $added, already there " . (count(self::CATEGORIES) - $added));
    }

    private function assignShifts(int $tenant): void
    {
        $shift = DB::table('hrms_shifts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('status', 1)
            ->whereRaw('LOWER(name) = ?', ['general shift'])->first();

        if (!$shift) {
            $this->warn('  assign-shifts: there is no active "General Shift" - run the shifts step first. Nothing assigned.');

            return;
        }

        $n = DB::table('tbluser')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('status', 1)
            ->whereNull('shift_id')->update(['shift_id' => $shift->id]);

        $this->line("  assign-shifts: put $n active employee(s) with no shift on General Shift");
    }

    // ── expenses ────────────────────────────────────────────────────────────

    /**
     * Who the demo is built from, or false (with the reason printed) if it cannot be.
     *
     * @return array|false|null
     */
    private function planExpenses(int $tenant, bool $categoriesComingFirst)
    {
        if (DB::table('hrms_expense_claims')->where('sub_institute_id', $tenant)->where('description', self::MARK)->exists()
            || DB::table('hrms_expense_advances')->where('sub_institute_id', $tenant)->where('note', self::MARK)->exists()) {
            $this->warn('  expenses: demo expenses are already here (use --remove first to start again). Skipped.');

            return null;
        }

        if (!$categoriesComingFirst && DB::table('hrms_expense_categories')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->count() < count(self::CATEGORIES)) {
            $this->error('  expenses: the demo uses the standard categories, which are not all there. Add --only=categories,expenses (or run both steps).');

            return false;
        }

        $people = DB::table('tbluser as u')
            ->leftJoin('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.sub_institute_id', $tenant)->whereNull('u.deleted_at')->where('u.status', 1)
            ->orderBy('u.id')
            ->get(['u.id', 'u.reporting_manager_id', 'p.role_key', 'p.name as profile_name']);

        $hr = [];
        $staff = [];
        foreach ($people as $p) {
            $role = RoleKey::fromProfile((object) ['role_key' => $p->role_key, 'name' => $p->profile_name]);
            if (in_array($role, ['administrator', 'hr_manager', 'hr_executive'], true)) {
                $hr[] = (int) $p->id;
            } else {
                $staff[] = $p;
            }
        }

        if ($hr === []) {
            $this->error('  expenses: this organisation has no active administrator or HR user to decide and pay the claims. Nothing written.');

            return false;
        }
        if ($staff === []) {
            $this->error('  expenses: this organisation has no active non-HR employee to make the claims. Nothing written.');

            return false;
        }

        return ['hr' => $hr, 'staff' => $staff, 'active' => $people->pluck('id')->map(fn ($v) => (int) $v)->all()];
    }

    private function seedExpenses(int $tenant, array $plan): void
    {
        $cat = DB::table('hrms_expense_categories')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->pluck('id', 'name')->all();
        $staff = $plan['staff'];
        $hr = $plan['hr'];

        // Someone to be the employee in scenario $i, who decides, and who pays.
        $employee = fn (int $i) => $staff[$i % count($staff)];
        $manager = function ($e) use ($plan) {
            $m = (int) $e->reporting_manager_id;

            return ($m > 0 && $m !== (int) $e->id && in_array($m, $plan['active'], true)) ? $m : null;
        };
        $decider = fn ($e) => $manager($e) ?? $this->pick($hr, (int) $e->id);
        $payer = fn ($e) => $this->pick($hr, (int) $e->id);

        $at = fn (int $daysAgo, string $time = '10:30:00') => Carbon::now()->subDays($daysAgo)->format('Y-m-d') . ' ' . $time;
        $on = fn (int $daysAgo) => Carbon::now()->subDays($daysAgo)->format('Y-m-d');

        // ── advances: [emp, purpose, amount, claimed, status, requestedDaysAgo, decided, paid, rejection note]
        $advances = [
            'A' => [0, 'Client visit advance', 20000, 12000, 'paid', 48, 47, 45, null],
            'B' => [1, 'Training trip advance', 15000, 15000, 'claimed', 70, 69, 68, null],
            'C' => [2, 'Vendor meeting advance', 10000, 0, 'approved', 5, 4, null, null],
            'D' => [3, 'Conference travel advance', 25000, 0, 'requested', 2, null, null, null],
            'E' => [4, 'Equipment purchase advance', 8000, 0, 'rejected', 12, 11, null, 'Please raise this as a claim after the purchase, with the bill.'],
            'F' => [5, 'Site visit advance', 12000, 0, 'paid', 16, 15, 13, null],
        ];

        $advId = [];
        $advNo = $this->nextNumber('hrms_expense_advances', 'advance_no', 'ADV-', $tenant);
        foreach ($advances as $key => [$i, $purpose, $amount, $claimed, $status, $req, $dec, $paid, $note]) {
            $e = $employee($i);
            $advId[$key] = DB::table('hrms_expense_advances')->insertGetId([
                'sub_institute_id' => $tenant, 'advance_no' => $this->format('ADV-', $advNo++), 'employee_id' => $e->id,
                'purpose' => $purpose, 'needed_by' => $on(max($req - 3, 0)), 'note' => self::MARK,
                'advance_amount' => $amount, 'claimed_amount' => $claimed, 'currency' => 'INR', 'status' => $status,
                'requested_at' => $at($req), 'approver_id' => $manager($e),
                'decided_by' => $dec !== null ? $decider($e) : null, 'decided_at' => $dec !== null ? $at($dec, '15:00:00') : null,
                // The rejection reason is the decision note; the demo marker lives on `note`.
                'decision_note' => $note,
                'paid_by' => $paid !== null ? $payer($e) : null, 'paid_at' => $paid !== null ? $at($paid, '12:00:00') : null,
                'payment_reference' => $paid !== null ? 'DEMO-' . random_int(100000, 999999) : null,
                'created_at' => $at($req), 'updated_at' => $at($paid ?? $dec ?? $req),
            ]);
        }

        // ── claims: [emp, title, status, submittedDaysAgo, decidedDaysAgo, paidDaysAgo, rejection note, advance key, adjusted, items]
        // items: [category, daysAgo, description, amount, receipt]
        $claims = [
            [0, 'Client visit, Mumbai', 'paid', 33, 32, 28, null, 'A', 12000, [
                ['Travel', 36, 'Return flight', 6500, 'INV-88231'], ['Accommodation', 35, 'Hotel, 2 nights', 4500, 'HTL-5521'], ['Meals & Food', 35, 'Dinner with client team', 1000, null]]],
            [1, 'Training trip', 'paid', 61, 59, 55, null, 'B', 15000, [
                ['Training & Courses', 64, 'Workshop fee', 14000, 'RCPT-90412'], ['Travel', 65, 'Train tickets', 2500, 'PNR-4418820']]],
            [2, 'Client lunch and taxi', 'submitted', 7, null, null, null, null, 0, [
                ['Client Entertainment', 8, 'Lunch with client, 6 people', 3200, 'RST-20931'], ['Travel', 8, 'Taxi to and from client office', 800, 'OLA-77120']]],
            [3, 'Fuel, this month', 'approved', 15, 14, null, null, null, 0, [
                ['Fuel', 17, 'Fuel for site visits', 3500, 'FUEL-1180'], ['Parking & Toll', 17, 'Toll charges', 400, null]]],
            [4, 'Office supplies and mobile recharge', 'approved', 40, 39, null, null, null, 0, [
                ['Office Supplies', 42, 'Notebooks, markers, whiteboard', 2100, 'STN-3320'], ['Internet & Mobile', 41, 'Mobile data recharge', 999, 'RCH-7741']]],
            [5, 'Software subscription', 'rejected', 11, 10, null, 'The invoice is not in the company name. Please get it re-issued and resubmit.', null, 0, [
                ['Software & Subscriptions', 14, 'Annual design tool licence', 8900, 'SUB-INV-5523']]],
            [6, 'Safety kit for site visits', 'submitted', 3, null, null, null, null, 0, [
                ['Medical (Work-related)', 5, 'First-aid and safety kit', 4200, 'MED-90021']]],
            [7, 'Travel, local visit', 'draft', null, null, null, null, null, 0, [
                ['Travel', 2, 'Taxi, return', 2800, 'TXI-1172'], ['Meals & Food', 2, 'Lunch', 650, null]]],
            [8, 'Hotel, conference', 'submitted', 1, null, null, null, null, 0, [
                ['Accommodation', 7, 'Hotel, 2 nights', 7600, 'HTL-3391']]],
            [9, 'Training course fee', 'approved', 90, 89, null, null, null, 0, [
                ['Training & Courses', 93, 'Certification course', 12000, 'RCPT-7712']]],
            [10, 'Client entertainment', 'paid', 98, 96, 92, null, null, 0, [
                ['Client Entertainment', 101, 'Dinner with prospective client', 5400, 'RST-88120']]],
            [11, 'Parking and tolls', 'cancelled', 9, null, null, null, null, 0, [
                ['Parking & Toll', 10, 'Parking', 650, null], ['Miscellaneous', 10, 'Courier charges', 300, 'CRR-5521']]],
            [12, 'Stationery', 'paid', 160, 159, 154, null, null, 0, [
                ['Office Supplies', 162, 'Stationery for the team', 2400, 'STN-2201']]],
            [13, 'Internet bill', 'paid', 130, 129, 124, null, null, 0, [
                ['Internet & Mobile', 134, 'Broadband for work from home', 1800, 'ISP-6612']]],
            [14, 'Client visit travel', 'paid', 80, 79, 74, null, null, 0, [
                ['Travel', 83, 'Bus and cab to client site', 5200, 'TRV-3380']]],
            [15, 'Team lunch', 'paid', 17, 16, 13, null, null, 0, [
                ['Meals & Food', 18, 'Team lunch', 1200, null]]],
            [16, 'Training materials', 'paid', 28, 27, 23, null, null, 0, [
                ['Training & Courses', 30, 'Online course subscription', 6400, 'UDM-5512']]],
        ];

        $claimNo = $this->nextNumber('hrms_expense_claims', 'claim_no', 'EXP-', $tenant);
        $made = 0;
        foreach ($claims as [$i, $title, $status, $sub, $dec, $paid, $note, $advKey, $adjusted, $items]) {
            $e = $employee($i);
            $base = $sub ?? 1;
            $total = round(array_sum(array_column($items, 3)), 2);

            $claimId = DB::table('hrms_expense_claims')->insertGetId([
                'sub_institute_id' => $tenant, 'claim_no' => $this->format('EXP-', $claimNo++), 'employee_id' => $e->id,
                'title' => $title, 'description' => self::MARK, 'total_amount' => $total, 'currency' => 'INR', 'status' => $status,
                'submitted_at' => $sub !== null ? $at($sub) : null, 'approver_id' => $sub !== null ? $manager($e) : null,
                'decided_by' => $dec !== null ? $decider($e) : null, 'decided_at' => $dec !== null ? $at($dec, '15:00:00') : null,
                'decision_note' => $note,
                'paid_by' => $paid !== null ? $payer($e) : null, 'paid_at' => $paid !== null ? $at($paid, '12:00:00') : null,
                'payment_reference' => $paid !== null ? 'DEMO-' . random_int(100000, 999999) : null,
                'advance_id' => $advKey ? $advId[$advKey] : null, 'advance_adjusted' => $adjusted,
                'created_at' => $at($base + 1, '09:00:00'), 'updated_at' => $at($paid ?? $dec ?? $base),
            ]);

            foreach ($items as [$c, $d, $desc, $amount, $receipt]) {
                DB::table('hrms_expense_items')->insert([
                    'sub_institute_id' => $tenant, 'claim_id' => $claimId, 'category_id' => $cat[$c],
                    'expense_date' => $on($d), 'description' => $desc, 'amount' => $amount, 'receipt_ref' => $receipt,
                    'created_at' => $at($base + 1, '09:00:00'), 'updated_at' => $at($base + 1, '09:00:00'),
                ]);
            }
            $made++;
        }

        $this->line('  expenses: added ' . count($advances) . " advances and $made claims (marked \"" . self::MARK . '")');
    }

    private function remove(int $tenant): int
    {
        if (!$this->option('force') && !$this->confirm('Delete the demo claims and advances for this organisation?', false)) {
            $this->warn('Nothing removed.');

            return self::SUCCESS;
        }

        $claims = DB::table('hrms_expense_claims')->where('sub_institute_id', $tenant)->where('description', self::MARK)->pluck('id');

        DB::transaction(function () use ($tenant, $claims, &$items, &$advances) {
            $items = DB::table('hrms_expense_items')->whereIn('claim_id', $claims)->delete();
            DB::table('hrms_expense_claims')->whereIn('id', $claims)->delete();
            $advances = DB::table('hrms_expense_advances')->where('sub_institute_id', $tenant)->where('note', self::MARK)->delete();
        });

        $this->info("Removed {$claims->count()} claim(s), $items line(s) and $advances advance(s). Shifts and categories were left as they are.");

        return self::SUCCESS;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** An HR user other than $not (nobody decides or pays their own claim). */
    private function pick(array $hr, int $not): int
    {
        foreach ($hr as $id) {
            if ($id !== $not) {
                return $id;
            }
        }

        return $hr[0];
    }

    /** The next free sequence number for "PREFIX-YEAR-NNNN" in this organisation. */
    private function nextNumber(string $table, string $column, string $prefix, int $tenant): int
    {
        $full = $prefix . date('Y') . '-';
        $last = DB::table($table)->where('sub_institute_id', $tenant)->where($column, 'like', $full . '%')->max($column);

        return $last ? ((int) substr($last, strlen($full))) + 1 : 1;
    }

    private function format(string $prefix, int $n): string
    {
        return $prefix . date('Y') . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
