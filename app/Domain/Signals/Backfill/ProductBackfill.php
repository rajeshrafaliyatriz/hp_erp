<?php

namespace App\Domain\Signals\Backfill;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductResolver;
use App\Support\ColumnExists;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * Fills `g2g_company_opportunities.product_master_id` for the signals that have none, through the Product Master resolver (S1) and nothing else:
 * a signal's title or text is never matched against product names, and a signal whose stored values reach no row stays empty and is counted.
 *
 * The stored values are tried through ProductResolver::forSignal, which gives the most specific row they support:
 *   relevant_offer_id  the sub-product of the matched Product Portfolio offer,
 *   product_key        the product the signal was researched for,
 *   business_fit       the product value the feed stores.
 * product_key is tried before business_fit. The offer wins when it belongs to the same product (or when no product value resolves); when it
 * belongs to a DIFFERENT product the product value wins and the offer is not used (counted as `offer_unused`). A signal is reported under the
 * path that decided its row.
 *
 * A value already in product_master_id is never touched. Any of the three source columns may be missing on a host; a missing one is skipped.
 */
final class ProductBackfill extends TenantBackfill
{
    public const PATHS = ['relevant_offer_id', 'product_key', 'business_fit'];

    private const CHUNK = 500;

    private ProductResolver $resolver;

    /** @var array<string, array{0: ?int, 1: ?string, 2: bool}> "offer|key|fit" => [row id, path, offer unused]; the answer only depends on the three values */
    private array $answers = [];

    public function __construct(?ProductResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new ProductResolver;
    }

    protected function process(Connection $db, int $tenant, bool $apply): array
    {
        $schema = Schema::connection($db->getName());
        if (! $schema->hasTable('g2g_product_master')) {
            throw new BackfillBlocked('g2g_product_master does not exist on this connection: run the Product Master migrations first.');
        }
        $hasColumn = ColumnExists::has(self::TABLE, 'product_master_id');
        if (! $hasColumn && $apply) {
            throw new BackfillBlocked(self::TABLE . '.product_master_id does not exist on this connection: run its migration first.');
        }
        $sources = array_values(array_filter(self::PATHS, fn (string $c) => ColumnExists::has(self::TABLE, $c)));
        if ($sources === []) {
            throw new BackfillBlocked(self::TABLE . ' has none of ' . implode(', ', self::PATHS) . ' on this connection, so there is nothing to resolve a product from.');
        }
        $offersTable = $schema->hasTable('g2g_product_offers');

        $report = [
            'without_product' => 0,
            'already_set' => 0,
            'resolved' => array_fill_keys(self::PATHS, 0),
            'written' => 0,
            'unresolved' => ['no_values' => 0, 'no_match' => 0],
            'offer_unused' => 0,
            'issues' => [],
        ];
        if (! $hasColumn) {
            $report['issues'][] = 'product_master_id does not exist on this connection yet, so every signal is treated as having no product.';
        }
        foreach (array_diff(self::PATHS, $sources) as $missing) {
            $report['issues'][] = "{$missing} does not exist on this connection, so that path was skipped.";
        }
        if (! $offersTable && in_array('relevant_offer_id', $sources, true)) {
            $report['issues'][] = 'g2g_product_offers does not exist on this connection, so offers were not looked up.';
        }
        $this->answers = [];

        if ($hasColumn) {
            $report['already_set'] = $db->table(self::TABLE)->where('sub_institute_id', $tenant)->whereNotNull('product_master_id')->count();
        }
        $query = $db->table(self::TABLE)->where('sub_institute_id', $tenant);
        if ($hasColumn) {
            $query->whereNull('product_master_id');
        }

        $query->select(array_merge(['id'], $sources))->chunkById(self::CHUNK, function ($rows) use ($db, $tenant, $apply, $offersTable, &$report) {
            foreach ($rows as $row) {
                $report['without_product']++;
                $offer = $offersTable ? $this->text($row, 'relevant_offer_id') : '';
                $key = $this->text($row, 'product_key');
                $fit = $this->text($row, 'business_fit');
                [$productId, $path, $offerUnused] = $this->answer($tenant, $offer, $key, $fit);

                if ($offerUnused) {
                    $report['offer_unused']++;
                }
                if ($productId === null) {
                    $report['unresolved'][($offer . $key . $fit) === '' ? 'no_values' : 'no_match']++;

                    continue;
                }

                $report['resolved'][$path]++;
                if ($apply) {
                    // The extra IS NULL makes "never overwrite" hold even if someone sets the row between the read and this write.
                    $report['written'] += $db->table(self::TABLE)->where('id', $row->id)->where('sub_institute_id', $tenant)->whereNull('product_master_id')
                        ->update(['product_master_id' => $productId]);
                }
            }
        });

        return $report;
    }

    /** @return array{0: ?int, 1: ?string, 2: bool} the Product Master row id, the path that decided it, and whether an offer id was present but not used */
    private function answer(int $tenant, string $offer, string $key, string $fit): array
    {
        return $this->answers[$tenant . '|' . $offer . '|' . $key . '|' . $fit] ??= $this->resolve($tenant, $offer, $key, $fit);
    }

    /** @return array{0: ?int, 1: ?string, 2: bool} */
    private function resolve(int $tenant, string $offer, string $key, string $fit): array
    {
        $productValue = null;
        $productPath = null;
        foreach (['product_key' => $key, 'business_fit' => $fit] as $path => $value) {
            if ($value !== '' && $this->resolver->product($tenant, $value) !== null) {
                $productValue = $value;
                $productPath = $path;

                break;
            }
        }

        $hit = $this->resolver->forSignal($tenant, $productValue, $offer === '' ? null : $offer);
        if ($hit === null) {
            return [null, null, false];
        }

        $viaOffer = $hit->kind === ProductMaster::KIND_SUB_PRODUCT;

        return [(int) $hit->id, $viaOffer ? 'relevant_offer_id' : $productPath, $offer !== '' && ! $viaOffer];
    }

    private function text(object $row, string $column): string
    {
        return property_exists($row, $column) ? trim((string) $row->{$column}) : '';
    }
}
