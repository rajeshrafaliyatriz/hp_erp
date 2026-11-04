<?php

namespace App\Console\Commands;

use App\Domain\Crm\Sales\OpportunityLinkBackfill;
use App\Domain\Crm\Sales\OpportunityLinkBackfillBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the product and the coded loss reason of opportunities made before those columns existed (slice 11). A dry run unless --apply is given; repeating
 * it changes nothing; a value that is already there is never overwritten. See OpportunityLinkBackfill for the rules.
 *
 *   php artisan opportunities:backfill-links --all                                (dry run, every organisation that has opportunities)
 *   php artisan opportunities:backfill-links --tenant=6                           (dry run for one organisation)
 *   php artisan opportunities:backfill-links --tenant=6 --apply --summary=docs/agentic-crm/_evidence/s11/backfill-dev-apply.txt
 *   php artisan opportunities:backfill-links --all --apply                        (each organisation in its own transaction)
 */
class OpportunitiesBackfillLinksCommand extends Command
{
    /** @var list<string> */
    private array $summaryLines = [];

    protected $signature = 'opportunities:backfill-links
        {--tenant= : One organisation (sub_institute_id)}
        {--all : Every organisation that has opportunities (instead of --tenant)}
        {--database= : Connection name; the default connection when omitted. The live connection needs the owner\'s go}
        {--apply : Write the links (default: show what would be written)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Set product_master_id and loss_reason_code on opportunities made before those columns existed. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backfill = new OpportunityLinkBackfill;

        $database = $this->option('database');
        if ($database !== null && $database !== '') {
            if (config('database.connections.' . $database) === null) {
                $this->error("There is no database connection called '{$database}'.");

                return self::INVALID;
            }
            DB::setDefaultConnection($database);
        }

        $tenant = $this->option('tenant');
        if ($this->option('all') && ($tenant !== null && $tenant !== '')) {
            $this->error('Give --tenant or --all, not both.');

            return self::INVALID;
        }
        if ($this->option('all')) {
            $tenants = $backfill->tenants();
        } elseif ($tenant !== null && $tenant !== '' && ctype_digit((string) $tenant) && (int) $tenant > 0) {
            $tenants = [(int) $tenant];
        } else {
            $this->error('Give --tenant=<organisation id> (a whole number above 0) or --all.');

            return self::INVALID;
        }

        $this->say(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection() . ', organisations: ' . ($tenants === [] ? '(none)' : implode(', ', $tenants)));
        $total = ['product' => 0, 'reason' => 0, 'written' => 0];

        foreach ($tenants as $id) {
            try {
                $r = $backfill->run($id, $apply);
            } catch (OpportunityLinkBackfillBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$id}");
            $this->count('opportunities', $r['opportunities']);
            $this->count($apply ? 'product set' : 'product to set', $r['product_to_set']);
            $this->count('  from its own product tags', $r['product_by_tags']);
            $this->count('  from the lead it was converted from', $r['product_by_lead']);
            $this->count('  from that lead\'s signal (through the resolver)', $r['product_by_signal']);
            $this->count('  product already there (left alone)', $r['product_filled']);
            $this->count('  tags lead to different products (left empty for a person)', $r['product_ambiguous']);
            $this->count('  product does not resolve (left empty)', $r['product_unresolved']);
            $this->count('closed lost', $r['lost']);
            $this->count($apply ? 'coded loss reason set' : 'coded loss reason to set', $r['reason_to_set']);
            $this->count('  reason already coded (left alone)', $r['reason_filled']);
            $this->count('  free text matches no code exactly (left as free text)', $r['reason_unmatched']);
            $this->count('  no reason written at all (left)', $r['reason_none']);
            if ($apply) {
                $this->count('columns written', $r['written']);
            }
            foreach ($r['warnings'] as $warning) {
                $this->say('  ! ' . $warning, 'warn');
            }
            $total['product'] += $r['product_to_set'];
            $total['reason'] += $r['reason_to_set'];
            $total['written'] += $r['written'];
        }

        $this->say();
        $this->say(sprintf('Total: %d product link(s) and %d loss reason code(s) %s.', $total['product'], $total['reason'], $apply ? "set ({$total['written']} columns written)" : 'to set'));
        $this->say($apply ? 'Done. Running it again changes nothing.' : 'Dry run: nothing was written. Run again with --apply to write.');

        return $this->finish(self::SUCCESS);
    }

    /** One aligned "label  number" line. */
    private function count(string $label, int $n): void
    {
        $this->say(sprintf('  %-60s %d', $label, $n));
    }

    /** Prints a line and keeps it for --summary. $style is a Command output method: line, warn or error. */
    private function say(string $text = '', string $style = 'line'): void
    {
        $this->summaryLines[] = $text;
        $this->{$style}($text);
    }

    /** Saves the printed lines to --summary when given, and returns the exit code (FAILURE when the file could not be written). */
    private function finish(int $code): int
    {
        $path = $this->option('summary');
        if ($path === null || $path === '') {
            return $code;
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (@file_put_contents($path, implode(PHP_EOL, $this->summaryLines) . PHP_EOL) === false) {
            $this->error("The summary could not be written to {$path}.");

            return self::FAILURE;
        }
        $this->line("Summary saved to {$path}");

        return $code;
    }
}
