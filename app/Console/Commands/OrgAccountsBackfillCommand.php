<?php

namespace App\Console\Commands;

use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgBackfill;
use App\Domain\OrgAccounts\OrgBackfillBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every live CRM organisation, GTM account and Signals company an identity in g2g_org_accounts, linking the ones that are provably the same
 * organisation (Organisation 360, slice 3). A dry run unless --apply is given. Repeating it changes nothing. See OrgResolver for the linking rules
 * and OrgBackfill for what is written. It never writes to the three source tables.
 *
 *   php artisan org-accounts:backfill                          (dry run, every organisation, per-organisation counts)
 *   php artisan org-accounts:backfill --tenant=6               (dry run for one organisation)
 *   php artisan org-accounts:backfill --tenant=6 --apply --summary=Docs/agentic-crm/_evidence/s3-backfill.txt
 *   php artisan org-accounts:backfill --tenant=6 --apply --database=live
 */
class OrgAccountsBackfillCommand extends Command
{
    /** @var list<string> */
    private array $summaryLines = [];

    protected $signature = 'org-accounts:backfill
        {--tenant= : One organisation (sub_institute_id). Required with --apply; a dry run without it covers every organisation}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the identities (default: show what would be written)}
        {--summary= : Also save the printed summary to this file}';

    protected $description = 'Give every CRM organisation, GTM account and Signals company an Organisation 360 identity. Dry run unless --apply.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $backfill = new OrgBackfill;

        $database = $this->option('database');
        if ($database !== null && $database !== '') {
            if (config('database.connections.' . $database) === null) {
                $this->error("There is no database connection called '{$database}'.");

                return self::INVALID;
            }
            DB::setDefaultConnection($database);
        }

        $tenant = $this->option('tenant');
        if ($tenant === null || $tenant === '') {
            if ($apply) {
                $this->error('--apply needs --tenant=<organisation id>: one organisation at a time.');

                return self::INVALID;
            }
            $tenants = $backfill->tenants();
        } elseif (! ctype_digit((string) $tenant) || (int) $tenant <= 0) {
            $this->error('--tenant must be an organisation id (a whole number above 0).');

            return self::INVALID;
        } else {
            $tenants = [(int) $tenant];
        }

        $this->say(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection() . ', organisations: ' . ($tenants === [] ? '(none)' : implode(', ', $tenants)));

        foreach ($tenants as $id) {
            try {
                $r = $backfill->run($id, $apply);
            } catch (OrgBackfillBlocked $e) {
                $this->say($e->getMessage(), 'error');

                return $this->finish(self::FAILURE);
            }

            $this->say();
            $this->say("Organisation {$id}");
            $this->count('live CRM organisations', $r['rows'][OrgAccount::SOURCE_CRM]);
            $this->count('live GTM accounts', $r['rows'][OrgAccount::SOURCE_GTM]);
            $this->count('live Signals companies', $r['rows'][OrgAccount::SOURCE_COMPANY]);
            $this->count($apply ? 'identities created' : 'identities to create', $r['identities']);
            $this->count($apply ? 'identities given a missing record' : 'identities that would get a missing record', $r['extended']);
            $this->say('  rows linked, by what joined them:');
            foreach ($r['linked'] as $basis => $n) {
                $this->count("  {$basis}", $n);
            }
            $this->count('ambiguous matches left as suggestions (pairs)', $r['ambiguous']);
            $this->count('same name or domain, different place (pairs)', $r['place_conflict']);
            $this->count('same_source_conflict (groups left apart)', $r['same_source_conflict']);
            $this->count('identity_conflict (groups left apart)', $r['identity_conflict']);
            $this->count('rows left alone (already in an identity)', $r['left_alone']);
            $this->count('suggestions pending', $r['suggestions']);
            foreach ($r['warnings'] as $warning) {
                $this->say('  ! ' . $warning, 'warn');
            }
        }

        $this->say();
        $this->say($apply ? 'Done. Running it again changes nothing.' : 'Dry run: nothing was written. Run again with --tenant=<id> --apply to write.');

        return $this->finish(self::SUCCESS);
    }

    /** One aligned "label  number" line. */
    private function count(string $label, int $n): void
    {
        $this->say(sprintf('  %-52s %d', $label, $n));
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
