<?php

namespace App\Console\Commands;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\IcpProfileVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies an ICP template (config/signals_icp.php) into an organisation's own ICPs, where the organisation can edit it.
 *
 *   php artisan signals:icp-seed --tenant=6 --template=scholar_k12 --activate --default
 *
 * Safe to run twice: an ICP with the same key is left alone. The configuration is validated before it is saved.
 */
class SeedIcpProfile extends Command
{
    protected $signature = 'signals:icp-seed {--tenant= : sub_institute_id}
        {--template=scholar_k12 : template to copy}
        {--key= : key for the new ICP (default: the template name)}
        {--name= : display name (default: the template label)}
        {--activate : make it active so research uses it}
        {--default : make it the default ICP of the organisation (needs --activate)}
        {--enable-schedule : also switch on the daily research schedule (needs a complete product profile)}';

    protected $description = 'Copy an ICP template into an organisation as its own editable ICP.';

    public function handle(IcpProfileValidator $validator): int
    {
        $tenant = (int) $this->option('tenant');
        $template = (string) $this->option('template');
        $config = config("signals_icp.{$template}");
        if ($tenant <= 0 || ! is_array($config)) {
            $this->error('Give --tenant and a --template that exists in config/signals_icp.php.');

            return self::FAILURE;
        }
        if (($problems = $validator->validate($config)) !== []) {
            $this->error('The template is not valid: ' . implode('; ', $problems));

            return self::FAILURE;
        }
        $key = (string) ($this->option('key') ?: $template);
        if (IcpProfile::where('sub_institute_id', $tenant)->where('key', $key)->exists()) {
            $this->line("Organisation {$tenant} already has an ICP with key '{$key}'. Left unchanged.");
            $this->enableSchedule($tenant);

            return self::SUCCESS;
        }

        $p = DB::transaction(function () use ($tenant, $key, $config, $template) {
            $active = (bool) $this->option('activate');
            $default = $active && (bool) $this->option('default');
            if ($default) {
                IcpProfile::where('sub_institute_id', $tenant)->update(['is_default' => false]);
            }
            $p = IcpProfile::create([
                'sub_institute_id' => $tenant, 'key' => $key, 'name' => (string) ($this->option('name') ?: ($config['label'] ?? $key)),
                'product_line' => $config['product_line'] ?? null, 'status' => $active ? 'active' : 'draft', 'is_default' => $default,
                'version' => 1, 'config' => $config,
            ]);
            IcpProfileVersion::create(['sub_institute_id' => $tenant, 'icp_profile_id' => $p->id, 'version' => 1, 'config' => $config, 'note' => "copied from template {$template}"]);

            return $p;
        });

        $this->info("Created ICP '{$p->key}' (id {$p->id}, {$p->status}) for organisation {$tenant}.");
        if ($p->status !== 'active') {
            $this->warn('It is a draft: research will not use it until it is activated (add --activate, or activate it in the API).');
        }
        $this->enableSchedule($tenant);

        return self::SUCCESS;
    }

    /** The daily schedule is the product profile's research_enabled switch. It can only be on when that profile is complete. */
    private function enableSchedule(int $tenant): void
    {
        $profile = \App\Domain\Signals\Opportunities\ProductProfile::where('sub_institute_id', $tenant)->first();
        $on = (bool) ($profile?->research_enabled);

        if (! $this->option('enable-schedule')) {
            $this->line($on ? 'Daily schedule: already ON.' : 'Daily schedule: OFF. Add --enable-schedule to switch it on (research only runs on a schedule when it is on).');

            return;
        }
        if ($profile === null) {
            $this->warn('Schedule NOT enabled: this organisation has no product profile yet. Fill it in on the Signals screen first.');

            return;
        }
        $check = app(\App\Domain\Signals\Opportunities\ProductProfileService::class)->completeness($profile);
        if (! $check['complete']) {
            $this->warn('Schedule NOT enabled: the product profile is incomplete (missing: ' . implode(', ', (array) $check['missing']) . ').');

            return;
        }
        if (! $on) {
            $profile->forceFill(['research_enabled' => true])->save();
        }
        $this->info('Daily schedule: ON.');
    }
}
