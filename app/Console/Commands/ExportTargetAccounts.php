<?php

namespace App\Console\Commands;

use App\Domain\Signals\Icp\TargetAccount;
use Illuminate\Console\Command;

/**
 * Writes one product's target organisations (the list research reads the websites of) to a JSON file, so another environment can load the
 * same list with `signals:load-accounts`. Read-only. Contact phone numbers and emails are never stored in this table, so none are exported;
 * `contact_name` and `crm_ref` are.
 *
 *   php artisan signals:export-accounts --tenant=6 --pack=scholar_k12 --file=storage/app/scholar_accounts.json
 */
class ExportTargetAccounts extends Command
{
    protected $signature = 'signals:export-accounts {--tenant= : sub_institute_id to export from} {--pack=scholar_k12 : the ICP pack key (ScholarClone: scholar_k12)} {--file= : where to write the JSON}';

    protected $description = 'Read-only: export a product\'s target accounts (with their verified websites) to a JSON file for signals:load-accounts.';

    /** The columns that describe the organisation. Ids, company links and run timestamps stay behind. */
    public const COLUMNS = [
        'account_key', 'name', 'city', 'state', 'board', 'level', 'website', 'crm_ref', 'crm_owner', 'contact_name', 'attributes',
        'icp_status', 'icp_tier', 'icp_fit_pct', 'icp_known_share', 'icp_notes', 'watch_priority',
    ];

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $file = (string) $this->option('file');
        if ($tenant <= 0 || $file === '') {
            $this->error('--tenant and --file are required.');

            return self::FAILURE;
        }
        $pack = (string) $this->option('pack');

        $rows = TargetAccount::where('sub_institute_id', $tenant)->where('pack', $pack)->orderBy('id')->get()
            ->map(fn ($a) => array_intersect_key($a->getAttributes(), array_flip(self::COLUMNS)))
            ->map(function ($r) {
                foreach (['attributes', 'icp_notes'] as $json) {
                    $r[$json] = is_string($r[$json] ?? null) ? json_decode($r[$json], true) : ($r[$json] ?? null);
                }

                return $r;
            })->values()->all();

        if ($rows === []) {
            $this->error("No target accounts for tenant {$tenant}, pack {$pack}.");

            return self::FAILURE;
        }
        file_put_contents($file, json_encode(['pack' => $pack, 'exported_at' => now()->toIso8601String(), 'accounts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info(count($rows) . " accounts written to {$file}.");

        return self::SUCCESS;
    }
}
