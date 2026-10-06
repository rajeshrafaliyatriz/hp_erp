<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONE IDENTITY PER ORGANISATION.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THREE TABLES DESCRIBED AN ORGANISATION AND THEY DISAGREED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Measured on live before this ran:
 *
 *   school_setup       12 rows   name, short code, Logo, contact - IS the tenant
 *   org_details         4 rows   legal name, CIN, GSTIN, PAN, logo
 *   institute_detail    5 rows   organization_name, for certificates and offers
 *
 * So `org_details` was missing for EIGHT of twelve organisations, and the
 * organisation screen read the logo from it - which is why a logo could be
 * uploaded and then never appear.
 *
 * `school_setup` is now the source of truth for IDENTITY, because it is the only
 * table with a row for every tenant and because `school_setup.id` IS
 * `sub_institute_id`. `org_details` keeps what it is actually for: the statutory
 * record.
 *
 * ── AND THREE ORGANISATIONS WERE WEARING ONE ANOTHER'S LOGO ─────────────────
 *
 * `scholar_clone.png` was the stored logo of organisations 1 ("Triz High School"),
 * 5 ("IT") AND 6 ("Scholar Clone") on both databases. Two customers were branded
 * with a third customer's mark.
 *
 * The rule below is derived, not hardcoded: when several organisations share a
 * logo file, the one whose NAME matches the filename keeps it and the others are
 * cleared. If no name matches, every sharer is cleared - showing a logo that might
 * belong to another company is worse than showing a monogram of your own initials,
 * which is what the screen falls back to.
 *
 * ── REVERSIBLE ──────────────────────────────────────────────────────────────
 *
 * `down()` restores nothing by itself, because the previous values cannot be
 * recomputed. The reversal SQL in
 * `docs/hrit-audit/_reversals/REVERSAL-2026-09-22-organisation-identity.sql`
 * carries the exact prior value of every row this touches, per database.
 *
 * ── MariaDB 10.1 ON LIVE ────────────────────────────────────────────────────
 *
 * `Schema::hasTable()` asks for `information_schema.columns.generation_expression`,
 * which 10.1 does not have, and THROWS. Every existence check here is raw SQL, as
 * everywhere else in this repository.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tablePresent('school_setup') || !$this->tablePresent('org_details')) {
            return;
        }

        $this->backfillStatutoryRecords();
        $this->reconcileLogoColumns();
        $this->releaseSharedLogos();
    }

    /**
     * Give every organisation a statutory record, even an empty one.
     *
     * Not cosmetic: `OrganizationProfileController::save()` UPDATEs when a row
     * exists and INSERTs when it does not, and several readers treat a missing row
     * as "this organisation has not been set up". Eight of twelve live
     * organisations were in that state while being fully operational.
     *
     * Seeded from `school_setup` where there is something to seed from, so the
     * statutory record starts out agreeing with the identity rather than blank.
     */
    private function backfillStatutoryRecords(): void
    {
        $missing = DB::table('school_setup')
            ->whereNotIn('id', function ($query) {
                $query->select('sub_institute_id')->from('org_details')->whereNotNull('sub_institute_id');
            })
            ->get(['id', 'SchoolName', 'Logo', 'Email', 'Mobile']);

        foreach ($missing as $org) {
            DB::table('org_details')->insert([
                'sub_institute_id' => $org->id,
                // The trading name is the best available starting point for the
                // legal name. An administrator corrects it; nobody has to invent it
                // from nothing.
                'legal_name' => $org->SchoolName ?: null,
                'logo' => $org->Logo ?: null,
                'email' => $org->Email ?: null,
                'mobile_no' => $org->Mobile ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Make the two logo columns agree, with `school_setup` winning.
     *
     * `save()` writes both, but the older web controller wrote only
     * `school_setup.Logo`, so they drifted: on live, organisation 1 held
     * `scholar_clone.png` in one column and a completely different filename in the
     * other. A screen reading either one was showing something true about a
     * different moment in history.
     *
     * `school_setup` wins because it is the identity table and because it is the
     * one with a value for every organisation. Where `school_setup` has NO logo and
     * `org_details` does, the value is promoted upward instead - that is a real
     * logo somebody uploaded, and discarding it would lose a customer's brand.
     */
    private function reconcileLogoColumns(): void
    {
        $rows = DB::table('school_setup as s')
            ->leftJoin('org_details as o', 'o.sub_institute_id', '=', 's.id')
            ->get(['s.id', 's.Logo as setup_logo', 'o.logo as details_logo']);

        foreach ($rows as $row) {
            $setup = trim((string) $row->setup_logo);
            $details = trim((string) $row->details_logo);

            if ($setup === $details) {
                continue;
            }

            if ($setup !== '') {
                // Identity wins.
                DB::table('org_details')->where('sub_institute_id', $row->id)->update([
                    'logo' => $setup,
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ($details !== '') {
                // Promote upward: a logo that exists only on the statutory record
                // is still this organisation's logo, and `school_setup` is what the
                // screen now reads.
                DB::table('school_setup')->where('id', $row->id)->update(['Logo' => $details]);
            }
        }
    }

    /**
     * Stop one organisation displaying another's logo.
     *
     * ── THE RULE IS DERIVED, NOT A LIST OF IDS ──────────────────────────────
     *
     * Hardcoding "clear organisations 1 and 5" would fix today's three rows and
     * nothing else; the same collision happens again the moment two tenants are
     * seeded from one template. So: group by filename, and for each file used by
     * more than one organisation, the one whose NAME matches the filename keeps it.
     *
     * `scholar_clone.png` vs "Scholar Clone" matches on a slug comparison, so
     * organisation 6 keeps it and 1 and 5 are cleared - which is the intended
     * outcome, reached by a rule rather than by naming the rows.
     *
     * ── AND IF NOTHING MATCHES, NOBODY KEEPS IT ─────────────────────────────
     *
     * Choosing arbitrarily - lowest id, say - would leave one organisation wearing a
     * mark that is probably not theirs, and it would look deliberate. A monogram of
     * your own initials is honest; another company's logo is not. So an ambiguous
     * collision clears every sharer and they each re-upload.
     */
    private function releaseSharedLogos(): void
    {
        $shared = DB::table('school_setup')
            ->selectRaw('Logo, COUNT(*) as n')
            ->whereNotNull('Logo')
            ->where('Logo', '<>', '')
            ->groupBy('Logo')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('Logo');

        foreach ($shared as $logo) {
            $owners = DB::table('school_setup')->where('Logo', $logo)->get(['id', 'SchoolName', 'ShortCode']);
            $slug = $this->slug(pathinfo((string) $logo, PATHINFO_FILENAME));

            $keep = null;

            foreach ($owners as $owner) {
                if ($slug !== '' && $this->slug((string) $owner->SchoolName) === $slug) {
                    $keep = (int) $owner->id;
                    break;
                }
            }

            foreach ($owners as $owner) {
                if ((int) $owner->id === $keep) {
                    continue;
                }

                // Emptied rather than set to NULL, matching what the upload path
                // writes for "no logo" and what the screen's fallback tests for.
                DB::table('school_setup')->where('id', $owner->id)->update(['Logo' => '']);
                DB::table('org_details')->where('sub_institute_id', $owner->id)->update([
                    'logo' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Lowercased, non-alphanumerics collapsed to one underscore. */
    private function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)) ?? '', '_');
    }

    /**
     * Nothing to undo automatically.
     *
     * The prior values are not recomputable - a cleared logo cannot be guessed back
     * and a backfilled statutory record cannot be told apart from one somebody
     * filled in afterwards. Deleting the backfilled rows on rollback would destroy
     * real edits made since. The reversal SQL carries the exact prior state instead.
     */
    public function down(): void
    {
        // Deliberately empty. See the reversal file named in the class comment.
    }

    private function tablePresent(string $table): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($found->n ?? 0) > 0;
    }
};
