<?php

namespace App\Console\Commands;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\ProspectAccounts;
use App\Domain\Gtm\ProspectScope;
use App\Domain\Signals\Opportunities\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puts the companies of signals that ALREADY exist into Prospecting (S20). New signals go in by themselves when they are saved (ProspectAccounts::autoAdd); this catches
 * up the ones saved before that. A dry run unless --apply is given. INSERT ONLY: it makes a target-stage account where a company has none for the signal's product, customer
 * type and place, and never edits an account that exists. An account that was REMOVED on purpose is not brought back. Eligibility is the Research tab's: a company, not
 * dismissed, not a sample, not expired. Repeating it changes nothing.
 *
 *   php artisan signals:send-to-prospecting                         (dry run, every organisation)
 *   php artisan signals:send-to-prospecting --tenant=6              (dry run for one organisation)
 *   php artisan signals:send-to-prospecting --tenant=6 --apply      (write)
 *   php artisan signals:send-to-prospecting --connection=db3        (a named connection; the live one needs the owner's go)
 */
class SignalsSendToProspectingCommand extends Command
{
    protected $signature = 'signals:send-to-prospecting
        {--apply : Make the accounts (default: only show what would be made)}
        {--connection= : Connection name; the default connection when omitted}
        {--tenant= : Only this organisation (sub_institute_id)}';

    protected $description = 'Add the companies of existing, eligible signals to Prospecting as target accounts. Insert only; dry run unless --apply.';

    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        if ($connection !== '') {
            if (config('database.connections.' . $connection) === null) {
                $this->error("There is no database connection called '{$connection}'.");

                return self::INVALID;
            }
            DB::setDefaultConnection($connection);
        }
        $tenantOption = (string) $this->option('tenant');
        if ($tenantOption !== '' && (! ctype_digit($tenantOption) || (int) $tenantOption < 1)) {
            $this->error('--tenant must be a whole number above 0.');

            return self::INVALID;
        }
        if (! Schema::hasTable('gtm_accounts') || ! Schema::hasTable('g2g_company_opportunities')) {
            $this->error('This database has no Prospecting or Signals tables.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . DB::getDefaultConnection());

        $signals = DB::table('g2g_company_opportunities')->whereNotNull('company_id')->where('is_sample', false)
            ->where(fn ($w) => $w->whereNull('review_status')->orWhere('review_status', '!=', 'Dismissed'))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()))
            ->when($tenantOption !== '', fn ($q) => $q->where('sub_institute_id', (int) $tenantOption))
            ->orderBy('sub_institute_id')->orderBy('id');

        $seen = [];
        $totals = [];
        foreach ($signals->cursor() as $signal) {
            $tenant = (int) $signal->sub_institute_id;
            $totals[$tenant] ??= ['signals' => 0, 'would_add' => 0, 'added' => 0, 'already' => 0, 'removed' => 0, 'no_company' => 0, 'failed' => 0];
            $totals[$tenant]['signals']++;

            $company = Company::where('sub_institute_id', $tenant)->find($signal->company_id);
            if (! $company) {
                $totals[$tenant]['no_company']++;

                continue;
            }
            $scope = ProspectAccounts::scopeOfSignal($tenant, $signal);
            $key = $tenant . '|' . $company->id . '|' . json_encode($scope);
            if (isset($seen[$key])) {
                continue;   // several signals of one company in one scope make one account
            }
            $seen[$key] = true;

            if (ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant)->where('company_id', $company->id), $scope)->exists()) {
                $totals[$tenant]['already']++;

                continue;
            }
            if (ProspectScope::apply(GtmAccount::onlyTrashed()->where('sub_institute_id', $tenant)->where('company_id', $company->id), $scope)->exists()) {
                $totals[$tenant]['removed']++;

                continue;
            }
            if (! $apply) {
                $totals[$tenant]['would_add']++;

                continue;
            }
            try {
                DB::transaction(fn () => ProspectAccounts::fromCompany($tenant, $company, $scope, null));
                $totals[$tenant]['added']++;
            } catch (\Throwable $e) {
                $totals[$tenant]['failed']++;
                $this->warn("Signal #{$signal->id} (organisation {$tenant}): " . $e->getMessage());
            }
        }

        if ($totals === []) {
            $this->line('No eligible signals.');
        }
        foreach ($totals as $tenant => $t) {
            $this->line(sprintf(
                'Organisation %d: %d eligible signal(s); %s %d account(s); %d already in Prospecting; %d removed on purpose (left alone); %d with no company row; %d failed.',
                $tenant, $t['signals'], $apply ? 'added' : 'would add', $apply ? $t['added'] : $t['would_add'], $t['already'], $t['removed'], $t['no_company'], $t['failed'],
            ));
        }
        if (! $apply) {
            $this->line('Nothing was written. Run again with --apply to make these accounts.');
        }

        return self::SUCCESS;
    }
}
