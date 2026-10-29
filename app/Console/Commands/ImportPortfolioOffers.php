<?php

namespace App\Console\Commands;

use App\Domain\Signals\Products\SubProductIcp;
use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes ONE product group's sub-products (offers) in this environment the same as another environment's, from a file made by
 * `portfolio:export-offers`. A DRY RUN unless --apply, and every --apply first saves a backup of what it is about to change or delete.
 *
 *   php artisan portfolio:import-offers                          dry run: shows what would change (file storage/app/offers.json)
 *   php artisan portfolio:import-offers --apply                  adds and updates; deletes nothing
 *   php artisan portfolio:import-offers --apply --replace        also deletes the group's other offers (and their own ICPs), so it ends up the same
 *   php artisan portfolio:import-offers storage/app/offers_backup_20261029_101500.json --apply --replace    puts a backup back
 *
 * An offer already present (same organisation and Offer ID) is updated; one that is not is added. Only columns this table has are written, the
 * organisation is always this environment's (--tenant, asked for when there is more than one), and who confirmed readiness elsewhere is not
 * carried over. Nothing outside the group's offers and their sub-product ICPs is touched.
 */
class ImportPortfolioOffers extends Command
{
    protected $signature = 'portfolio:import-offers {file=storage/app/offers.json : the file made by portfolio:export-offers}
        {--tenant= : sub_institute_id to write into (asked for when several organisations have this group)}
        {--apply : write (default: dry run)}
        {--replace : also delete this group\'s offers that are not in the file}';

    protected $description = 'Make a product group\'s sub-products match a file from another environment (dry run unless --apply; backs up first).';

    /** Never copied from the file: the row's own id, and who confirmed readiness (that person is not in this environment). */
    private const SKIP = ['id', 'readiness_confirmed_by', 'readiness_confirmed_at', 'created_by', 'updated_by'];

    public function handle(): int
    {
        $given = (string) $this->argument('file');
        $path = is_file($given) ? $given : base_path($given);
        if (! is_file($path)) {
            $this->error("Cannot read {$given}. Make it with: php artisan portfolio:export-offers");

            return self::FAILURE;
        }
        if (! Schema::hasTable('g2g_product_offers')) {
            $this->error('This environment has no g2g_product_offers table.');

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $offers = array_values(array_filter((array) ($data['offers'] ?? []), fn ($o) => is_array($o) && ($o['offer_id'] ?? '') !== '' && ($o['name'] ?? '') !== ''));
        if ($offers === []) {
            $this->error('The file has no offers.');

            return self::FAILURE;
        }
        $ids = array_map(fn ($o) => (string) $o['offer_id'], $offers);
        if (count($ids) !== count(array_unique($ids))) {
            $this->error('The file lists an Offer ID twice. Make the file from ONE organisation.');

            return self::FAILURE;
        }
        $parent = (string) ($data['parent'] ?? ($offers[0]['parent_product'] ?? ''));

        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $counts = DB::table('g2g_product_offers')->where('parent_product', $parent)->whereNotNull('sub_institute_id')
                ->select('sub_institute_id', DB::raw('COUNT(*) AS n'))->groupBy('sub_institute_id')->get();
            if ($counts->count() !== 1) {
                $this->error($counts->isEmpty() ? "No organisation here has \"{$parent}\" yet: say which one with --tenant=<id>." : 'Several organisations here have this group. Choose yours with --tenant=:');
                foreach ($counts as $c) {
                    $this->line("  --tenant={$c->sub_institute_id}   ({$c->n} offers now)");
                }

                return self::FAILURE;
            }
            $tenant = (int) $counts->first()->sub_institute_id;
            $this->line("Organisation {$tenant} (the only one here with \"{$parent}\").");
        }

        $apply = (bool) $this->option('apply');
        $columns = array_flip(ColumnExists::all('g2g_product_offers'));
        $others = $this->option('replace') && $parent !== ''
            ? DB::table('g2g_product_offers')->where('sub_institute_id', $tenant)->where('parent_product', $parent)->whereNotIn('offer_id', $ids)->get()
            : collect();

        // A backup of exactly what this run is about to change or delete, in the export format: import it again (with --replace) to put it back.
        if ($apply) {
            $touched = DB::table('g2g_product_offers')->where('sub_institute_id', $tenant)->whereIn('offer_id', $ids)->get()->concat($others);
            $keys = $touched->map(fn ($o) => SubProductIcp::key((string) $o->offer_id))->all();
            $profiles = Schema::hasTable('g2g_icp_profiles') && $keys !== [] ? DB::table('g2g_icp_profiles')->where('sub_institute_id', $tenant)->whereIn('key', $keys)->get() : collect();
            $backup = storage_path('app/offers_backup_' . now()->format('Ymd_His') . '.json');
            @mkdir(dirname($backup), 0777, true);
            file_put_contents($backup, json_encode(['parent' => $parent, 'offers' => $touched->values(), 'icp_profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('Backup of what will change: ' . $backup);
        }

        $add = $update = 0;
        foreach ($offers as $o) {
            $values = array_diff_key(array_intersect_key($o, $columns), array_flip(self::SKIP));
            $values['sub_institute_id'] = $tenant;
            $exists = DB::table('g2g_product_offers')->where('sub_institute_id', $tenant)->where('offer_id', $o['offer_id'])->first();
            $this->line(($exists ? 'update ' : 'add    ') . str_pad((string) $o['offer_id'], 9) . ' ' . $o['name']);
            $exists ? $update++ : $add++;
            if (! $apply) {
                continue;
            }
            if ($exists) {
                DB::table('g2g_product_offers')->where('id', $exists->id)->update($values + ['updated_at' => now()]);
            } else {
                DB::table('g2g_product_offers')->insert($values + ['created_at' => now(), 'updated_at' => now()]);
            }
        }

        $icpDone = 0;
        if (Schema::hasTable('g2g_icp_profiles')) {
            $profileCols = array_flip(ColumnExists::all('g2g_icp_profiles'));
            foreach ((array) ($data['icp_profiles'] ?? []) as $p) {
                if (! is_array($p) || ! str_starts_with((string) ($p['key'] ?? ''), 'sub_')) {
                    continue;
                }
                $config = is_string($p['config'] ?? null) ? json_decode($p['config'], true) : ($p['config'] ?? null);
                if (array_intersect(array_map('strval', (array) ($config['offer_ids'] ?? [])), $ids) === []) {
                    continue;   // a sub-product ICP of an offer that is not in the file
                }
                $values = array_diff_key(array_intersect_key($p, $profileCols), array_flip(['id', 'created_by', 'updated_by']));
                $values['sub_institute_id'] = $tenant;
                $this->line('icp    ' . $p['key']);
                $icpDone++;
                if ($apply) {
                    $row = DB::table('g2g_icp_profiles')->where('sub_institute_id', $tenant)->where('key', $p['key'])->first();
                    $row ? DB::table('g2g_icp_profiles')->where('id', $row->id)->update($values + ['updated_at' => now()])
                        : DB::table('g2g_icp_profiles')->insert($values + ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        foreach ($others as $x) {
            $this->warn('delete ' . str_pad((string) $x->offer_id, 9) . ' ' . $x->name . ($apply ? '' : '   (would be deleted)'));
            if ($apply) {
                if (Schema::hasTable('g2g_icp_profiles')) {
                    DB::table('g2g_icp_profiles')->where('sub_institute_id', $tenant)->where('key', SubProductIcp::key((string) $x->offer_id))->delete();
                }
                DB::table('g2g_product_offers')->where('id', $x->id)->delete();
            }
        }

        $this->info(($apply ? 'Done: ' : 'DRY RUN, nothing written: ') . "{$add} to add, {$update} to update, {$icpDone} sub-product ICP(s), {$others->count()} to delete."
            . ($apply ? '' : ' Run it again with --apply (and --replace to delete the others) to do it.'));

        return self::SUCCESS;
    }
}
