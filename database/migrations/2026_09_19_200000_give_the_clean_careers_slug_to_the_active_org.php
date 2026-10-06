<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The organisation people actually apply to should own the clean careers URL.
 *
 * ── THE PROBLEM ─────────────────────────────────────────────────────────────
 *
 * careers_slug is derived from the organisation name and suffixed with the
 * tenant id when that name is already taken (see 2026_09_03_120000). The
 * suffix is assigned by whichever row is processed first - lowest id - not by
 * which organisation is actually in use.
 *
 * On live that produced:
 *
 *   tenant 1   scholar-clone-pvt-ltd      0 postings,  0 applications
 *   tenant 6   scholar-clone-pvt-ltd-6   19 postings, 23 applications
 *
 * Both rows are the SAME company - identical name, organization_code TRIZ,
 * website and created_at - so the collision is between an organisation and its
 * own duplicate record. The live one was pushed onto the suffixed URL and the
 * dormant one kept the clean one.
 *
 * That is not merely untidy. Both pages render the same company name and
 * branding, so /careers/scholar-clone-pvt-ltd looks like a working careers
 * page that simply has no openings, rather than like the wrong page. A
 * candidate given that link sees an empty list and leaves.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────
 *
 * When several organisations share a base slug, the clean one goes to the one
 * with careers ACTIVITY - postings first, then applications, then user count.
 * Everyone else keeps a suffixed slug. Activity is the only signal here that
 * reflects which URL a real person might have been given.
 *
 * Ties, and groups where nobody has any activity, are left exactly as they
 * are: there is no reason to prefer either, and churning a public URL for no
 * benefit is worse than an unhelpful suffix.
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────────
 *
 * careers_slug carries a UNIQUE index, so the clean slug is freed before it is
 * claimed - never two rows holding it at once, even momentarily. Idempotent:
 * once the active organisation holds the clean slug there is nothing to do.
 */
return new class extends Migration
{
    private const TABLE = 'institute_detail';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        // SHOW COLUMNS, not Schema::hasColumn(): the latter selects
        // generation_expression, which MariaDB 10.1 (live) does not have.
        $hasSlug = collect(DB::select('SHOW COLUMNS FROM `' . self::TABLE . '`'))
            ->contains(fn ($column) => $column->Field === 'careers_slug');

        if (!$hasSlug) {
            return;
        }

        $orgs = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->whereNotNull('careers_slug')
            ->get(['id', 'sub_institute_id', 'organization_name', 'careers_slug']);

        // Group by the slug their NAME would produce, which is what collides.
        $groups = [];
        foreach ($orgs as $org) {
            $base = Str::slug((string) $org->organization_name);
            if ($base === '') {
                continue;
            }
            $groups[$base][] = $org;
        }

        $moved = 0;

        foreach ($groups as $base => $members) {
            if (count($members) < 2) {
                continue;
            }

            // Who is actually in use?
            $scored = [];
            foreach ($members as $org) {
                $sid = (int) $org->sub_institute_id;
                $scored[] = [
                    'org'          => $org,
                    'postings'     => (int) DB::table('talent_job_postings')->where('sub_institute_id', $sid)->count(),
                    'applications' => (int) DB::table('talent_job_applications')->where('sub_institute_id', $sid)->count(),
                    'users'        => (int) DB::table('tbluser')->where('sub_institute_id', $sid)->whereNull('deleted_at')->count(),
                ];
            }

            usort($scored, function ($a, $b) {
                return [$b['postings'], $b['applications'], $b['users']]
                   <=> [$a['postings'], $a['applications'], $a['users']];
            });

            $winner = $scored[0];
            $runnerUp = $scored[1];

            // Nobody is using theirs, or it is a genuine tie - leave it alone.
            $decisive = [$winner['postings'], $winner['applications'], $winner['users']]
                    !== [$runnerUp['postings'], $runnerUp['applications'], $runnerUp['users']];

            if (!$decisive || $winner['postings'] + $winner['applications'] === 0) {
                continue;
            }

            if ($winner['org']->careers_slug === $base) {
                continue;   // already correct
            }

            // Free the clean slug first: the column is UNIQUE.
            foreach ($scored as $entry) {
                if ($entry['org']->careers_slug !== $base) {
                    continue;
                }
                DB::table(self::TABLE)->where('id', $entry['org']->id)->update([
                    'careers_slug' => Str::limit($base, 50, '') . '-' . $entry['org']->sub_institute_id,
                ]);
                echo "  freed  $base  from tenant {$entry['org']->sub_institute_id}"
                    . " ({$entry['postings']} postings, {$entry['applications']} applications)\n";
            }

            DB::table(self::TABLE)->where('id', $winner['org']->id)->update(['careers_slug' => $base]);
            echo "  gave   $base  to tenant {$winner['org']->sub_institute_id}"
                . " ({$winner['postings']} postings, {$winner['applications']} applications)\n";

            $moved++;
        }

        echo "  slugs reassigned: $moved\n";
    }

    /**
     * No down(). Reversing it would hand the clean URL back to the dormant
     * organisation and push the live careers page onto a suffixed one, which
     * is the state this exists to correct.
     */
    public function down(): void
    {
    }
};
