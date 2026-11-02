<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductMasterBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills the Product Master (g2g_product_master) from the Product Portfolio, the organisation's own products and the CRM catalogue.
 * A dry run unless --apply is given. Repeating it changes nothing. See ProductMasterBackfill for the order and the rules.
 */
class ProductMasterBackfillCommand extends Command
{
    protected $signature = 'product-master:backfill
        {--tenant= : One organisation (sub_institute_id)}
        {--all : Every organisation that has offers, own products or catalogue items}
        {--database= : Connection name; the default connection when omitted (for example: live)}
        {--apply : Write the rows (default: show what would be created)}';

    protected $description = 'Fill the Product Master from the Product Portfolio, own products and the CRM catalogue. Dry run unless --apply.';

    public function handle(): int
    {
        $connection = $this->option('database') ?: null;
        $apply = (bool) $this->option('apply');

        $tenants = $this->tenants($connection);
        if ($tenants === []) {
            $this->error('Give --tenant=<id> or --all (and make sure that organisation has offers, own products or catalogue items).');

            return self::FAILURE;
        }

        $service = new ProductMasterBackfill($connection);
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ' on connection ' . ($connection ?? '(default)') . ', organisations: ' . implode(', ', $tenants));

        foreach ($tenants as $tenant) {
            $r = $service->run($tenant, $apply);
            $this->line('');
            $this->line("Organisation {$tenant}");
            foreach (['created' => $apply ? 'created' : 'would create', 'existing' => 'already there'] as $k => $label) {
                foreach ($r[$k] as $bucket => $n) {
                    $this->line(sprintf('  %-14s %-18s %d', $label, $bucket, $n));
                }
            }
            if ($r['created'] === [] && $r['existing'] === []) {
                $this->line('  nothing found for this organisation');
            }
            foreach ($r['issues'] as $issue) {
                $this->warn('  ! ' . $issue);
            }
        }

        $this->line('');
        $this->line($apply ? 'Done. Running it again changes nothing.' : 'Dry run: nothing was written. Run again with --apply to write.');

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
        foreach ([['g2g_product_offers', null], ['g2g_signal_products', null], ['crm_products', 'deleted_at']] as [$table, $softDelete]) {
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
