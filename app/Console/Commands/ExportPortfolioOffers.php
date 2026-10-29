<?php

namespace App\Console\Commands;

use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes one product group's sub-products (offers), with each one's own ICP, to a JSON file that `portfolio:import-offers` can load in another
 * environment. Read-only: nothing is changed here. Run it on the environment that has the RIGHT data.
 *
 *   php artisan portfolio:export-offers                      (group "Scholar K-12", file storage/app/offers.json)
 *   php artisan portfolio:export-offers --tenant=6 --parent="Scholar K-12" --file=storage/app/offers.json
 */
class ExportPortfolioOffers extends Command
{
    protected $signature = 'portfolio:export-offers {--parent=Scholar K-12 : the product group (its parent_product)} {--tenant= : sub_institute_id (needed only when several organisations have this group)} {--file=storage/app/offers.json}';

    protected $description = 'Read-only: export a product group\'s sub-products and their own ICPs to a JSON file for portfolio:import-offers.';

    public function handle(): int
    {
        $parent = (string) $this->option('parent');
        if (! Schema::hasTable('g2g_product_offers')) {
            $this->error('This environment has no g2g_product_offers table.');

            return self::FAILURE;
        }
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $counts = DB::table('g2g_product_offers')->where('parent_product', $parent)->whereNotNull('sub_institute_id')
                ->select('sub_institute_id', DB::raw('COUNT(*) AS n'))->groupBy('sub_institute_id')->get();
            if ($counts->count() !== 1) {
                $this->error($counts->isEmpty() ? "No offers in \"{$parent}\"." : 'Several organisations have this group. Choose one with --tenant=:');
                foreach ($counts as $c) {
                    $this->line("  --tenant={$c->sub_institute_id}   ({$c->n} offers)");
                }

                return self::FAILURE;
            }
            $tenant = (int) $counts->first()->sub_institute_id;
        }

        $offers = DB::table('g2g_product_offers')->where('sub_institute_id', $tenant)->where('parent_product', $parent)->orderBy('offer_id')->get();
        $ids = $offers->pluck('offer_id')->map(fn ($v) => (string) $v)->all();
        $profiles = [];
        if (Schema::hasTable('g2g_icp_profiles')) {
            foreach (DB::table('g2g_icp_profiles')->where('sub_institute_id', $tenant)->where('key', 'like', 'sub_%')   // (the underscore matches any one character: harmless, the ICP must still list one of these offers)
                ->get() as $p) {
                $config = is_string($p->config) ? json_decode($p->config, true) : (array) $p->config;
                if (array_intersect(array_map('strval', (array) ($config['offer_ids'] ?? [])), $ids) !== []) {
                    $profiles[] = $p;
                }
            }
        }
        $file = (string) $this->option('file');
        @mkdir(dirname(base_path($file)), 0777, true);
        file_put_contents(str_starts_with($file, '/') || preg_match('/^[A-Za-z]:/', $file) ? $file : base_path($file), json_encode(['parent' => $parent, 'offers' => $offers, 'icp_profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($offers as $o) {
            $this->line('  ' . str_pad((string) $o->offer_id, 9) . ' ' . $o->name);
        }
        $this->info("Saved {$offers->count()} offer(s) and " . count($profiles) . " sub-product ICP(s) of \"{$parent}\" (organisation {$tenant}) to {$file}. Nothing was changed.");

        return self::SUCCESS;
    }
}
