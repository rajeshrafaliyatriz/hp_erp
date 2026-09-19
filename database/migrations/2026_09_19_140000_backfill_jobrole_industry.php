<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every job role the industry its own organisation is filed under.
 *
 * ── WHY A ROLE WITH NO INDUSTRY IS INVISIBLE ────────────────────────────────
 *
 * The Job Opening form filters its job-title dropdown on
 * `s_user_jobrole.industries`, sending the tenant's own institute_type (which
 * reaches the browser as session.org_type). The Capability Library's job-role
 * form has no industry field, so every role added there was saved with
 * industries = NULL and matched nothing - invisible on the one screen it exists
 * to be used on.
 *
 * Reported from tenant 6: "Pre Sale Education AI Solution Consultant" was added
 * under Sales and Marketing and never appeared in the dropdown, while its 149
 * siblings carrying 'Information Technology' did.
 *
 * LibraryController now stamps the industry at creation. This repairs the rows
 * saved before that.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────────
 *
 * Only rows whose industry is missing, and only where the ORGANISATION has an
 * institute_type to inherit. A role belonging to a tenant with no industry set
 * is left alone: inventing one would hide it just as effectively, in a way
 * nobody could trace back to here.
 *
 * Measured before running - app host 129 rows (127 repairable), live host 3 (3).
 */
return new class extends Migration
{
    public function up(): void
    {
        $repaired = 0;

        DB::table('s_user_jobrole as r')
            ->join('school_setup as s', 's.id', '=', 'r.sub_institute_id')
            ->whereNull('r.deleted_at')
            ->where(fn ($w) => $w->whereNull('r.industries')->orWhere('r.industries', ''))
            ->whereNotNull('s.institute_type')
            ->where('s.institute_type', '!=', '')
            ->select('r.id', 's.institute_type')
            // Chunked because this is 129 rows on one host and could be more on
            // another; the update is per-row because each tenant has its own value.
            ->orderBy('r.id')
            ->chunk(200, function ($rows) use (&$repaired) {
                foreach ($rows as $row) {
                    DB::table('s_user_jobrole')
                        ->where('id', $row->id)
                        ->update(['industries' => trim($row->institute_type)]);
                    $repaired++;
                }
            });

        // Visible in the migration output, so the count is recorded rather than
        // assumed - the two hosts hold very different numbers of these.
        echo "  backfilled industry on {$repaired} job role(s)\n";
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling back would mean blanking `industries` on rows this migration
     * cannot distinguish from ones that always carried a correct value - the
     * column has no "set by the backfill" marker. Clearing all of them would
     * make every job role invisible in Recruitment, which is the exact fault
     * this repairs.
     */
    public function down(): void
    {
        // no-op, on purpose
    }
};
