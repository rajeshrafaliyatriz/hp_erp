<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces `G2G_NOTIFY_EMAIL_TENANTS` (a comma-separated tenant-id list living
 * in a `.env` file on whichever server happens to be running) with a real,
 * per-tenant column — so approving one more organisation to send mail is an
 * admin-UI toggle, not an SSH session and a `php artisan config:clear`.
 *
 * `App\Support\MailGate::allowedForTenant()` is the only thing that reads
 * this; `G2G_NOTIFY_EMAIL` (the platform-wide master switch) is UNCHANGED —
 * this migration and the code it supports only replace the per-tenant list,
 * not the all-or-nothing flag above it.
 *
 * BACKFILL: whatever this *process's own* `.env` currently lists in
 * `G2G_NOTIFY_EMAIL_TENANTS` gets flipped on here, so running this against a
 * given database preserves whatever was already approved there — it does not
 * silently approve or revoke anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('school_setup', 'outbound_email_enabled')) {
            Schema::table('school_setup', function (Blueprint $table) {
                $table->boolean('outbound_email_enabled')->default(false)->after('expire_date');
            });
        }

        $alreadyApproved = array_values(array_filter(
            array_map(static fn ($v) => (int) trim($v), explode(',', (string) env('G2G_NOTIFY_EMAIL_TENANTS', ''))),
            static fn ($v) => $v > 0
        ));

        if ($alreadyApproved !== []) {
            DB::table('school_setup')->whereIn('id', $alreadyApproved)->update(['outbound_email_enabled' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('school_setup', 'outbound_email_enabled')) {
            Schema::table('school_setup', function (Blueprint $table) {
                $table->dropColumn('outbound_email_enabled');
            });
        }
    }
};
