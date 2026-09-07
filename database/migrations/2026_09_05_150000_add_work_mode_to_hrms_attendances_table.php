<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the employee worked that day. F-112 (the dead "Mark WFH" button) and
 * F-115 (the Location column that always said "Office").
 *
 * The dashboard has always shown a Location column. It was rendered as
 *
 *     location: entry.ipaddress_in ? 'Office' : undefined      // use-attendance.ts
 *     {record.location || 'Office'}                            // page.tsx
 *
 * - a constant either way. Next to it sat a "Mark WFH" Quick Action whose
 * handler was `onClick: () => {}`, because there was nothing in the schema for
 * it to set. This column is the missing concept both were reaching for.
 *
 * varchar, not enum, and this is deliberate: hrms_emp_leaves.status was an enum
 * and needed a later migration (2026_07_25_100400) purely to widen it. A tenant
 * that later wants 'client_site' should not need a schema change.
 *
 * NOT NULL DEFAULT 'office' so the 994 existing rows get a truthful value
 * rather than a null the UI would have to guess at - every one of them predates
 * remote work being recordable here, and they were all captured against an
 * office IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hrms_attendances', 'work_mode')) {
            return;
        }

        Schema::table('hrms_attendances', function (Blueprint $table) {
            $table->string('work_mode', 20)->default('office')->after('punchout_time');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('hrms_attendances', 'work_mode')) {
            return;
        }

        Schema::table('hrms_attendances', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
    }
};
