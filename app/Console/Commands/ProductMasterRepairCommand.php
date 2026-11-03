<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductMasterRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-syncs the Product Master (g2g_product_master) from the CRM catalogue, the Product Portfolio and the organisation's own products.
 * A dry run unless --apply is given; repeating it changes nothing. See ProductMasterRepair for the two steps and the rules.
 */
class ProductMasterRepairCommand extends Command
{
    protected $signature = 'product-master:repair
        {--tenant= : One organisation (sub_institute_id)}
        {--all : Every organisation that has offers, own products or catalogue items}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the changes (default: show what would change)}';

    protected $description = 'Re-sync the Product Master from the CRM catalogue, the Product Portfolio and own products. Dry run unless --apply.';

    public function handle(): int
    {
        $connection = $this->option('database') ?: null;
        $apply = (bool) $this->option('apply');

        $tenants = $this->tenants($connection);
        if ($tenants === []) {
            $this->error('Give --tenant=<id> or --all (and make sure that organisation has offers, own products or catalogue items).');

            return self::FAILURE;
        }

        $service = new ProductMasterRepair($connection);
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . ($connection ?? '(default)') . ', organisations: ' . implode(', ', $tenants));

        $changes = 0;
        foreach ($tenants as $tenant) {
            try {
                $r = $service->run($tenant, $apply);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('');
            $this->line("Organisation {$tenant}");
            foreach (['created' => $apply ? 'created' : 'would create', 'synced' => $apply ? 'changed' : 'would change', 'existing' => 'already there'] as $k => $label) {
                foreach ($r[$k] as $bucket => $n) {
                    $this->line(sprintf('  %-14s %-18s %d', $label, $bucket, $n));
                    if ($k !== 'existing') {
                        $changes += $n;
                    }
                }
            }
            if ($r['created'] === [] && $r['synced'] === [] && $r['existing'] === []) {
                $this->line('  nothing found for this organisation');
            }
            foreach ($r['issues'] as $issue) {
                $this->warn('  ! ' . $issue);
            }
        }

        $this->line('');
        if ($changes === 0) {
            $this->line('Nothing to change: the Product Master already agrees with its sources.');
        } else {
            $this->line($apply ? "Done: {$changes} change(s). Running it again changes nothing." : "Dry run: nothing was written. {$changes} change(s) would be made. Run again with --apply to write.");
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function tenants(?string $connection): array
    {
        if ($this->option('tenant') !== null && $this->option('tenant') !== '') {
            return [max(0, (int) $this->option('tenant'))];
        }
        if (! $this->option('all')) {
            return [];
        }

        $db = DB::connection($connection);
        $ids = [];
        // Also the organisations that already have master rows: a source may be gone, which is exactly what a repair has to notice.
        foreach ([['g2g_product_offers', null], ['g2g_signal_products', null], ['crm_products', 'deleted_at'], ['g2g_product_master', null]] as [$table, $softDelete]) {
            if (! Schema::connection($db->getName())->hasTable($table)) {
                continue;
            }
            $q = $db->table($table)->whereNotNull('sub_institute_id')->where('sub_institute_id', '>', 0);
            if ($softDelete !== null) {
                $q->whereNull($softDelete);
            }
            foreach ($q->distinct()->pluck('sub_institute_id') as $id) {
                $ids[(int) $id] = true;
            }
        }
        ksort($ids);

        return array_keys($ids);
    }
}
