<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voluntary, per-person calendar sharing grants — "let a colleague peek at
 * my calendar", the one real CRM capability the existing tiered visibility
 * model (SubjectAuthority::TASK_PRIVILEGED sees everyone; a manager sees
 * subordinates via tbluser.employee_id) genuinely cannot express on its own,
 * because it is a personal choice, not a role fact.
 *
 * `viewer_user_id = 0` IS A SENTINEL FOR "EVERYONE", DELIBERATELY NOT NULL.
 * MySQL treats NULLs as distinct in a unique index, so a NULL "everyone"
 * row would not stop a second one from being inserted — the exact trap
 * `g2g_terminology.sub_institute_id` already hit and was fixed the same way
 * (see 2026_08_11_000100_create_g2g_notification_tables.php). 0 is a real
 * value and the unique index holds.
 *
 * Additive nullable `visibility` on `task`: NULL/default = visible per the
 * existing tiered rules; 'PRIVATE' = hidden from everyone except the
 * owner/assignee/TASK_PRIVILEGED — tasks are exempt from any "Busy" masking
 * because that layer was deliberately not built (see CalendarVisibilityService's
 * docblock); a private task is simply absent from someone else's feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_management_calendar_shares')) {
            Schema::create('task_management_calendar_shares', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('owner_user_id');
                // 0 = shared with everyone in the tenant. See docblock above.
                $table->unsignedBigInteger('viewer_user_id');
                $table->boolean('can_edit')->default(false);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->unique(['owner_user_id', 'viewer_user_id'], 'tm_cal_share_unique');
                $table->index(['sub_institute_id', 'viewer_user_id'], 'tm_cal_share_viewer_idx');
            });
        }

        if (!Schema::hasColumn('task', 'visibility')) {
            Schema::table('task', function (Blueprint $table) {
                $table->string('visibility', 10)->nullable()->after('recurrence_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task', 'visibility')) {
            Schema::table('task', function (Blueprint $table) {
                $table->dropColumn('visibility');
            });
        }
        Schema::dropIfExists('task_management_calendar_shares');
    }
};
