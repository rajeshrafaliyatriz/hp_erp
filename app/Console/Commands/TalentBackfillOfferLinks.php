<?php

namespace App\Console\Commands;

use App\Services\Talent\OfferLinkService;
use App\Support\CandidateLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mint the missing accept/decline link for every offer that was marked
 * 'sent' before TalentOfferController::store() stopped that from happening
 * silently (CRA-007).
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Before the fix, a failed OfferLinkService::mint() call was logged and
 * ignored - the offer still got emailed and marked 'sent' with no accept
 * link. Measured on DB3/live 2026-09-28: 71 offers, 68 marked 'sent', 0 rows
 * in talent_offer_acceptances. This does not resend any email - it only
 * creates the missing token, the same row a recruiter's "copy link" button
 * already creates one at a time, so HR can decide how to get it to each
 * candidate.
 *
 * ── WHY A COMMAND AND NOT A MIGRATION ───────────────────────────────────────
 *
 * It writes live customer rows and calls out to OfferLinkService per offer.
 * Dry-run BY DEFAULT; safe to run repeatedly (mint() upserts on offer_id, so
 * a second run just re-mints/replaces rather than duplicating).
 *
 *   php artisan talent:backfill-offer-links                  # dry run, dev
 *   php artisan talent:backfill-offer-links --database=live  # dry run, live
 *   php artisan talent:backfill-offer-links --database=live --execute
 */
class TalentBackfillOfferLinks extends Command
{
    protected $signature = 'talent:backfill-offer-links
        {--execute : Actually write. Without this nothing changes.}
        {--database= : Connection to work on (default: the app default).}
        {--tenant= : Restrict to one sub_institute_id.}';

    protected $description = "Mint the missing accept/decline link for offers marked 'sent' with no link.";

    public function handle(OfferLinkService $links): int
    {
        $connection = $this->option('database') ?: config('database.default');
        $execute = (bool) $this->option('execute');
        $tenant = $this->option('tenant');

        $this->info(sprintf(
            'Connection: %s   Mode: %s%s',
            $connection,
            $execute ? 'EXECUTE (will write)' : 'DRY RUN (nothing will change)',
            $tenant ? "   Tenant: {$tenant}" : '',
        ));

        $stuck = DB::connection($connection)
            ->table('talent_offers as o')
            ->leftJoin('talent_offer_acceptances as a', function ($join) {
                $join->on('a.offer_id', '=', 'o.id')->whereNull('a.deleted_at');
            })
            ->where('o.status', 'sent')
            ->whereNull('a.id')
            ->when($tenant, fn ($q) => $q->where('o.sub_institute_id', $tenant))
            ->select('o.id', 'o.application_id', 'o.sub_institute_id', 'o.updated_by', 'o.created_at')
            ->orderBy('o.id')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info("Nothing to do - every 'sent' offer already has an accept link.");
            return self::SUCCESS;
        }

        $minted = 0;
        $skippedNoEmail = 0;
        $rows = [];

        foreach ($stuck as $row) {
            $application = DB::connection($connection)
                ->table('talent_job_applications')
                ->where('id', $row->application_id)
                ->first(['email']);

            if (! $application || ! $application->email) {
                $skippedNoEmail++;
                $rows[] = [$row->id, $row->sub_institute_id, '-', 'skip-no-candidate-email'];
                continue;
            }

            $syear = $row->created_at
                ? \Carbon\Carbon::parse($row->created_at)->format('Y')
                : date('Y');

            $preview = 'would-mint';

            if ($execute) {
                // ->on() is load-bearing for a live-tenant run - see OfferLinkService's
                // own DB:: calls, which use the facade's currently-configured default
                // connection unless this app also switches it here.
                DB::setDefaultConnection($connection);

                $offerObj = (object) ['id' => $row->id, 'application_id' => $row->application_id];
                $result = $links->mint(
                    $offerObj,
                    (int) $row->sub_institute_id,
                    $syear,
                    $row->updated_by ? (int) $row->updated_by : null,
                    $application->email
                );

                $preview = CandidateLink::pointsAtApi()
                    ? '(FRONTEND_URL not set - link would point at the API)'
                    : CandidateLink::to('offer', $result['token']);
            }

            $rows[] = [$row->id, $row->sub_institute_id, $application->email, $preview];
            $minted++;
        }

        $this->table(['offer', 'tenant', 'candidate_email', $execute ? 'link' : 'action'], $rows);

        $this->newLine();
        $this->info(sprintf(
            '%s: %d   skip-no-candidate-email: %d   examined: %d',
            $execute ? 'minted' : 'would mint',
            $minted,
            $skippedNoEmail,
            $stuck->count(),
        ));

        if (! $execute) {
            $this->comment('Dry run. Re-run with --execute to write. This does NOT send any email - '
                . 'share the printed link with each candidate however your team already does.');
        }

        return self::SUCCESS;
    }
}
