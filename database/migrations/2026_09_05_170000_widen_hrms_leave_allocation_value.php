<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leave entitlement in halves, not just whole days.
 *
 * hrms_leave_allocation.value was `int(11)`. Half-day entitlements are ordinary
 * in HR - "12.5 days" - and Sprint 4's day counter already produces halves,
 * because a tenant can mark Saturday as a half working day.
 *
 * Caught while writing up Sprint 4: the new Entitlements screen offers a 0.5
 * step, so a user typing 12.5 would have been silently stored as 12 with no
 * message. A control that quietly changes what you typed is the same class of
 * defect this remediation exists to remove, so it is fixed here rather than
 * deferred.
 *
 * decimal(6,2) matches hrms_emp_leaves.chargeable_days, added in the same
 * sprint, so a grant and a deduction are the same kind of number.
 *
 * Widening an integer column to decimal is lossless: every existing value
 * survives as itself. Raw SQL because Doctrine DBAL is not installed, which is
 * what Schema::table()->change() would need.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hrms_leave_allocation MODIFY value DECIMAL(6,2) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        // Narrowing back to int TRUNCATES any half day that has been set since.
        DB::statement('ALTER TABLE hrms_leave_allocation MODIFY value INT(11) NOT NULL DEFAULT 0');
    }
};
