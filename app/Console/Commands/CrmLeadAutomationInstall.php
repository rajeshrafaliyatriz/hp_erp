<?php

namespace App\Console\Commands;

use App\Domain\Crm\Automation\LeadAutomationInstaller;
use Illuminate\Console\Command;

/**
 * Creates the recommended Signal -> Lead -> Support workflows for one organisation (as DRAFTS unless --activate).
 *
 *   php artisan crm:lead-automation-install 6 --admin=28 --support-user=28
 *
 * Review them under Sales & Marketing -> GTM Agents -> Workflows, edit what you want while they are drafts, then activate.
 * Uses the default database connection; for the live database say so explicitly (--database is not offered here on purpose).
 */
class CrmLeadAutomationInstall extends Command
{
    protected $signature = 'crm:lead-automation-install {tenant : sub_institute_id} {--admin= : id of an active administrator who will own the workflows} {--support-user= : id of the user onboarding tickets are assigned to} {--prefix=Lead Automation} {--activate : activate them instead of leaving drafts}';

    protected $description = 'Create the recommended lead-to-support automation workflows for one organisation (drafts by default).';

    public function handle(): int
    {
        try {
            $r = LeadAutomationInstaller::install((int) $this->argument('tenant'), (int) $this->option('admin'), ['support_user_id' => (int) $this->option('support-user'), 'prefix' => (string) $this->option('prefix')], (bool) $this->option('activate'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($r['created'] as $n) {
            $this->line("created   {$n}");
        }
        foreach ($r['existing'] as $n) {
            $this->line("exists    {$n}");
        }
        foreach ($r['invalid'] as $n => $errors) {
            $this->error("invalid   {$n}: ".$errors[0]);
        }
        $this->info(count($r['created']).' created, '.count($r['existing']).' already there, '.count($r['invalid']).' invalid.');

        return $r['invalid'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
