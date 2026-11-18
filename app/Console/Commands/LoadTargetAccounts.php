<?php

namespace App\Console\Commands;

use App\Console\Commands\ExportTargetAccounts as Export;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\TargetAccount;
use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Loads a file written by `signals:export-accounts` into this environment, for the organisation you name. A dry run unless --apply.
 * An account already present (same organisation, pack and account_key) is left exactly as it is, unless --update is given; nothing is
 * deleted. It reads and writes the database only: no website is fetched and no AI or search provider is called.
 *
 *   php artisan signals:load-accounts storage/app/scholar_accounts.json --tenant=<live id>            (shows what would be added)
 *   php artisan signals:load-accounts storage/app/scholar_accounts.json --tenant=<live id> --apply
 */
class LoadTargetAccounts extends Command
{
    protected $signature = 'signals:load-accounts {file? : JSON written by signals:export-accounts (default: database/seed-data/scholar_accounts.json)} {--tenant= : sub_institute_id to load into} {--apply : write (default: dry run)} {--update : also refresh accounts that already exist}';

    protected $description = 'Load target accounts exported from another environment (dry run unless --apply). Existing accounts are kept unless --update.';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        $rawPath = (string) $this->argument('file');
        $path = $rawPath !== '' ? $rawPath : (is_file(base_path('database/seed-data/scholar_accounts.json')) ? base_path('database/seed-data/scholar_accounts.json') : base_path('storage/app/scholar_accounts.json'));
        if ($tenant <= 0 || ! is_file($path)) {
            $this->error('A readable file and --tenant are required.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('g2g_target_accounts')) {
            $this->error('This environment has no g2g_target_accounts table: run the migrations first (2026_10_13_100000_create_g2g_target_accounts_table).');

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $pack = (string) ($data['pack'] ?? '');
        $accounts = (array) ($data['accounts'] ?? []);
        if ($pack === '' || $accounts === []) {
            $this->error('The file has no pack or no accounts.');

            return self::FAILURE;
        }

        // The ICP these accounts belong to in THIS environment (matched by key), so scoring and the editor stay linked. Absent: left empty.
        $icpId = Schema::hasTable('g2g_icp_profiles') && ColumnExists::has('g2g_target_accounts', 'icp_profile_id')
            ? IcpProfile::where('sub_institute_id', $tenant)->where('key', $pack)->value('id') : null;

        $add = $update = $keep = 0;
        foreach ($accounts as $a) {
            $key = (string) ($a['account_key'] ?? '');
            if ($key === '' || trim((string) ($a['name'] ?? '')) === '') {
                continue;
            }
            $values = array_intersect_key($a, array_flip(Export::COLUMNS));
            $existing = TargetAccount::where('sub_institute_id', $tenant)->where('pack', $pack)->where('account_key', $key)->first();
            if ($existing === null) {
                $add++;
                if ($this->option('apply')) {
                    TargetAccount::create($values + ['sub_institute_id' => $tenant, 'pack' => $pack] + ($icpId ? ['icp_profile_id' => $icpId] : []));
                }
            } elseif ($this->option('update')) {
                $update++;
                if ($this->option('apply')) {
                    $existing->forceFill($values)->save();
                }
            } else {
                $keep++;
            }
        }

        $this->line(sprintf('%s: %d to add, %d to update, %d already present and kept.', $this->option('apply') ? 'Applied' : 'Dry run', $add, $update, $keep));
        if (! $this->option('apply')) {
            $this->line('Nothing written. Add --apply to load them.');
        }

        return self::SUCCESS;
    }
}
