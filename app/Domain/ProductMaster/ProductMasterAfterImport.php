<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\Schema;

/**
 * What an import of Portfolio offers does AFTER it has written them (slice 15, WPA1, item 2; audit gap G-D2).
 *
 * `portfolio:import-offers`, `g2g:import-portfolio` and the G2GPortfolioSeeder write `g2g_product_offers` with the query builder, so the write-through of the Product Master
 * (ProductMasterSync::throughOffer) never ran for them: an imported Sub-product had no master row, and a Sub-product the import deleted kept a row pointing at nothing, until
 * somebody remembered `product-master:repair`. At the end of every REAL run (a dry run writes nothing) they call run() here: the repair for the organisation (the backfill that
 * creates what is missing, then the sync that makes every row agree with its source), then the checker's verdict, and the exact command to repeat it.
 *
 * `$tenant` is the organisation the offers were written for; null or 0 means they belong to none (the shared foundation offers), which the repair keeps under organisation 0.
 *
 * It never throws and never changes what the command returns: the offers are saved, so a master that cannot follow is a printed warning with the line that fixes it
 * ("a failing master write never changes the answer"). A host with no master table gets one sentence and nothing else.
 */
final class ProductMasterAfterImport
{
    /**
     * @return list<array{0: 'line'|'warn', 1: string}> what the caller prints, in order
     */
    public static function run(?int $tenant): array
    {
        $scope = max(0, (int) $tenant);
        $fix = "php artisan product-master:repair --tenant={$scope} --apply";
        $who = $scope > 0 ? "organisation {$scope}" : 'the shared rows (offers of no organisation)';

        if (! Schema::hasTable('g2g_product_master')) {
            return [['line', "Product Master: this database has no g2g_product_master table, so there is nothing to bring in step. After its migration has run: {$fix}"]];
        }

        $out = [];
        try {
            $repair = (new ProductMasterRepair())->run($scope, true);
            $out[] = ['line', "Product Master brought in step for {$who}: " . array_sum($repair['created']) . ' row(s) created, ' . array_sum($repair['synced']) . ' changed.'];
            foreach ($repair['issues'] as $issue) {
                $out[] = ['warn', "  ! {$issue}"];
            }
        } catch (\Throwable $e) {
            return [['warn', "The offers were saved, but the Product Master could not follow ({$e->getMessage()}). Bring it in step with: {$fix}"]];
        }

        try {
            $check = (new ProductIntegrity(null, $scope > 0 ? $scope : null))->check();
            $out[] = [$check['errors'] > 0 ? 'warn' : 'line', "Product Master check: {$check['errors']} error(s), {$check['warnings']} warning(s)" . ($scope > 0 ? " for organisation {$scope}." : ' (every organisation).')];
            foreach (array_slice($check['findings'], 0, 5) as $finding) {
                $out[] = ['warn', "  {$finding['code']}: {$finding['message']}"];
            }
        } catch (\Throwable $e) {
            $out[] = ['warn', "Product Master check could not run ({$e->getMessage()}): php artisan product-master:check"];
        }
        $out[] = ['line', "To repeat the repair: {$fix}"];

        return $out;
    }
}
