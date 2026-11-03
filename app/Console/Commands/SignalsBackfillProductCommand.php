<?php

namespace App\Console\Commands;

use App\Domain\Signals\Backfill\BackfillBlocked;
use App\Domain\Signals\Backfill\ProductBackfill;
use App\Domain\Signals\Backfill\RunsBackfill;
use Illuminate\Console\Command;

/**
 * Fills the empty `product_master_id` of each signal through the Product Master resolver (offer id, then product key, then business fit; never by
 * name). A dry run unless --apply is given. Repeating it changes nothing. See ProductBackfill for the rules.
 *
 *   php artisan signals:backfill-product-master                           (dry run, every organisation, counts per resolution path)
 *   php artisan signals:backfill-product-master --tenant=6 --apply --summary=Docs/agentic-crm/_evidence/s2-product.txt
 */
class SignalsBackfillProductCommand extends Command
{
    use RunsBackfill;

    protected $signature = 'signals:backfill-product-master
        {--tenant= : One organisation (sub_institute_id). Required with --apply; a dry run without it covers every organisation}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the values (default: show what would be written)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Fill the empty product_master_id of Signals through the Product Master resolver. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backfill = new ProductBackfill;

        $tenants = $this->organisations($backfill, $apply);
        if ($tenants === null) {
            return self::INVALID;
        }
        $this->header($apply, $tenants);

        foreach ($tenants as $tenant) {
            try {
                $r = $backfill->run($tenant, $apply);
            } catch (BackfillBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$tenant}");
            $this->count('signals with a product already (left alone)', $r['already_set']);
            $this->count('signals without a product', $r['without_product']);
            $this->say('  ' . ($apply ? 'product set, by the value that decided it:' : 'product would be set, by the value that decides it:'));
            foreach ($r['resolved'] as $path => $n) {
                $this->count("  {$path}", $n);
            }
            $this->say('  left empty:');
            $this->count('  no offer id, product key or business fit', $r['unresolved']['no_values']);
            $this->count('  values given, but no Product Master row matches', $r['unresolved']['no_match']);
            if ($r['offer_unused'] > 0) {
                $this->say("  ! {$r['offer_unused']} signal(s) carry an offer id that was not used (unknown, ambiguous, or it belongs to a different product than the signal's product value).", 'warn');
            }
            foreach ($r['issues'] as $issue) {
                $this->say('  ! ' . $issue, 'warn');
            }
        }

        $this->say();
        $this->say($apply ? 'Done. Running it again changes nothing.' : 'Dry run: nothing was written. Run again with --tenant=<id> --apply to write.');

        return $this->finish(self::SUCCESS);
    }
}
