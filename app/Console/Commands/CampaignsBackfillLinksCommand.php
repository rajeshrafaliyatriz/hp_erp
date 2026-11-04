<?php

namespace App\Console\Commands;

use App\Domain\Campaigns\CampaignLinkBackfill;
use App\Domain\Campaigns\CampaignLinkBackfillBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the product of campaigns and the campaign of leads that were made before those columns existed (slice 9). A dry run unless --apply is given;
 * repeating it changes nothing; a value that is already there is never overwritten. It sends nothing and schedules nothing. See CampaignLinkBackfill for the rules.
 *
 *   php artisan campaigns:backfill-links --all                                (dry run, every organisation that has campaigns or leads with a campaign text)
 *   php artisan campaigns:backfill-links --tenant=6                           (dry run for one organisation)
 *   php artisan campaigns:backfill-links --tenant=6 --apply --summary=docs/agentic-crm/_evidence/s9/backfill-dev-apply.txt
 *   php artisan campaigns:backfill-links --all --apply                        (each organisation in its own transaction)
 */
class CampaignsBackfillLinksCommand extends Command
{
    /** @var list<string> */
    private array $summaryLines = [];

    protected $signature = 'campaigns:backfill-links
        {--tenant= : One organisation (sub_institute_id)}
        {--all : Every organisation that has campaigns or leads with a campaign text (instead of --tenant)}
        {--database= : Connection name; the default connection when omitted. The live connection needs the owner\'s go}
        {--apply : Write the links (default: show what would be written)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Set product_master_id on campaigns and campaign_id on leads from what they already say. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backfill = new CampaignLinkBackfill;

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
        $total = ['product' => 0, 'campaign' => 0, 'written' => 0, 'ambiguous' => 0];

        foreach ($tenants as $id) {
            try {
                $r = $backfill->run($id, $apply);
            } catch (CampaignLinkBackfillBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$id}");
            $this->count('campaigns', $r['campaigns']);
            $this->count('  with a product_id (the catalogue item)', $r['campaigns_with_product']);
            $this->count($apply ? 'campaign product set' : 'campaign product to set', $r['product_to_set']);
            $this->count('  product already there (left alone)', $r['product_filled']);
            $this->count('  catalogue item has no Product Master row (left empty)', $r['product_unresolved']);
            $this->count('leads', $r['leads']);
            $this->count('  with a campaign text', $r['leads_with_text']);
            $this->count($apply ? 'lead campaign set' : 'lead campaign to set', $r['campaign_to_set']);
            $this->count('  campaign already there (left alone)', $r['campaign_filled']);
            $this->count('  no campaign of this name (left empty)', $r['campaign_no_match']);
            $this->count('  two campaigns carry the name (NOT linked)', $r['campaign_ambiguous']);
            foreach ($r['ambiguous_names'] as $name) {
                $this->say('    ambiguous name: ' . $name);
            }
            if ($apply) {
                $this->count('columns written', $r['written']);
            }
            foreach ($r['warnings'] as $warning) {
                $this->say('  ! ' . $warning, 'warn');
            }
            $total['product'] += $r['product_to_set'];
            $total['campaign'] += $r['campaign_to_set'];
            $total['written'] += $r['written'];
            $total['ambiguous'] += $r['campaign_ambiguous'];
        }

        $this->say();
        $this->say(sprintf(
            'Total: %d campaign product link(s) and %d lead campaign link(s) %s; %d lead(s) left unlinked because the campaign name is ambiguous.',
            $total['product'], $total['campaign'], $apply ? "set ({$total['written']} columns written)" : 'to set', $total['ambiguous'],
        ));
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
