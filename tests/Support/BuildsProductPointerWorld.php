<?php

namespace Tests\Support;

use App\Domain\ProductMaster\ProductPointers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database the product integrity tests run on (S15 plan section 2.1; tests/Feature/ProductIntegrityTest.php), on in-memory sqlite.
 *
 * The master table comes from its real migration, so its unique keys are the real ones. The Portfolio offers and the CRM catalogue are minimal tables with
 * the columns the checker reads. Every OTHER table is built FROM THE REGISTRY (ProductPointers::all()): one minimal table per pointer, with its pointer column,
 * its organisation column or the document row that carries the organisation, and the columns the census sums. A pointer added to the registry therefore
 * gets its table here without an edit, and the per-pointer tests run for it.
 *
 * Every row is stamped with STAMP so a test can prove that a run changed nothing, `updated_at` included.
 */
trait BuildsProductPointerWorld
{
    protected const T = 1;

    protected const T2 = 2;

    protected const STAMP = '2026-01-01 00:00:00';

    private int $productSeq = 0;

    protected function buildProductPointerWorld(): void
    {
        (require base_path('database/migrations/2026_12_07_090000_create_g2g_product_master_table.php'))->up();

        Schema::create('g2g_product_offers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('offer_id', 50);
            $t->string('name', 191);
            $t->string('parent_product', 50)->default('');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('product_no', 100)->nullable();
            $t->string('name', 191);
            $t->unsignedBigInteger('product_master_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        foreach (ProductPointers::all() as $entry) {
            $this->ensurePointerTable($entry);
        }
    }

    /** @param array<string, mixed> $e a registry entry */
    private function ensurePointerTable(array $e): void
    {
        $table = $e['table'];
        if (! Schema::hasTable($table)) {
            Schema::create($table, fn ($t) => $t->id());
        }
        $add = function (string $column, \Closure $define) use ($table): void {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, $define);
            }
        };
        $add($e['column'], fn ($t) => $t->unsignedBigInteger($e['column'])->nullable());
        $add('updated_at', fn ($t) => $t->timestamp('updated_at')->nullable());
        if (is_string($e['scope'])) {
            $add($e['scope'], fn ($t) => $t->unsignedBigInteger($e['scope'])->nullable());
        } else {
            $scope = $e['scope'];
            $add($scope['via'], fn ($t) => $t->unsignedBigInteger($scope['via'])->nullable());
            if (! Schema::hasTable($scope['parent'])) {
                Schema::create($scope['parent'], function ($t) use ($scope) {
                    $t->id();
                    $t->unsignedBigInteger($scope['parent_tenant'])->nullable();
                    $t->timestamp('updated_at')->nullable();
                });
            }
        }
        foreach ($e['measures'] as $measure) {
            $add($measure, fn ($t) => $t->decimal($measure, 15, 2)->default(0));
        }
    }

    // ------------------------------------------------------------------ rows

    /** The registry entry of one column. @return array<string, mixed> */
    protected function entry(string $table, string $column): array
    {
        foreach (ProductPointers::all() as $e) {
            if ($e['table'] === $table && $e['column'] === $column) {
                return $e;
            }
        }
        $this->fail("{$table}.{$column} is not in the registry");
    }

    /** @param array<string, mixed> $over */
    protected function master(array $over = []): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId($over + [
            'sub_institute_id' => self::T, 'product_key' => 'k' . ++$this->productSeq, 'kind' => 'sub_product', 'name' => 'Item', 'status' => 'active', 'origin' => 'manual',
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
    }

    /** A product (level 1). Organisation 0 is shared. */
    protected function product(string $key, string $name, ?string $portfolio, int $tenant = 0): int
    {
        return $this->master(['sub_institute_id' => $tenant, 'product_key' => $key, 'kind' => 'product', 'name' => $name, 'portfolio_name' => $portfolio, 'origin' => $tenant === 0 ? 'builtin' : 'custom']);
    }

    protected function offer(?int $tenant, string $offerId, string $name, string $group): int
    {
        return (int) DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => $tenant, 'offer_id' => $offerId, 'name' => $name, 'parent_product' => $group, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    /** @param array<string, mixed> $over */
    protected function crm(int $tenant, string $name, ?int $line = null, array $over = []): int
    {
        return (int) DB::table('crm_products')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'product_no' => 'P' . ++$this->productSeq, 'name' => $name, 'product_master_id' => $line,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP, 'deleted_at' => null,
        ]);
    }

    /**
     * One row in the table of a registered pointer, holding $value. The organisation is written on the row itself or on a document row made for it; the
     * rows of crm_products are put in the Recycle Bin so they are not live catalogue items that lack a master row.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $over
     * @return int the id of the row that holds the pointer
     */
    protected function pointRow(array $entry, int $tenant, ?int $value, array $over = []): int
    {
        $table = $entry['table'];
        if ($table === ProductPointers::CRM_PRODUCTS) {
            return $this->crm($tenant, 'Pointer row', $value, ['deleted_at' => self::STAMP] + $over);
        }

        $row = [$entry['column'] => $value, 'updated_at' => self::STAMP] + $over;
        if (is_string($entry['scope'])) {
            $row[$entry['scope']] = $tenant;
        } elseif ($entry['scope']['via'] !== $entry['column']) {
            $scope = $entry['scope'];
            $parent = $scope['parent'] === ProductPointers::CRM_PRODUCTS
                ? $this->crm($tenant, 'Document', null, ['deleted_at' => self::STAMP])
                : (int) DB::table($scope['parent'])->insertGetId([$scope['parent_tenant'] => $tenant, 'updated_at' => self::STAMP]);
            $row[$scope['via']] = $parent;
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    /** A line on a document of an organisation, for the census tests. @param array<string, mixed> $over */
    protected function line(string $table, int $tenant, ?int $product, float $quantity, float $total, array $over = []): int
    {
        $column = 'product_id';

        return $this->pointRow($this->entry($table, $column), $tenant, $product, ['quantity' => $quantity, 'line_total' => $total] + $over);
    }

    /**
     * A small, coherent world: nothing in it is wrong. Returns the ids the tests build their corruption from.
     *
     * Organisation 1: its product Scholar K-12 (it was a shared one before the isolation), its own product Payroll, a sub-product with BOTH pointers (offer + catalogue item), a
     * sub-product with an offer only, a catalogue-only item, a catalogue item in the Recycle Bin. Organisation 2: one catalogue-only item. And one row in every
     * pointer table, pointing at valid targets of its own organisation.
     *
     * @return array<string, int>
     */
    protected function cleanWorld(): array
    {
        $w = [];
        // The Product the Sub-products hang under. It was a SHARED Product (organisation 0) until the isolation of 24 Nov 2026; it is organisation 1's own now, and the key is still called
        // `shared` because dozens of tests name it. A test of what a row of organisation 0 does uses leftover() below.
        $w['shared'] = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', self::T);
        $w['payroll'] = $this->product('payroll', 'Payroll', 'Payroll', self::T);

        $w['offerA'] = $this->offer(self::T, 'P1', 'Scholar One', 'Scholar K-12');
        $w['crmA'] = $this->crm(self::T, 'Scholar One', $w['shared']);
        $w['both'] = $this->master(['name' => 'Scholar One', 'product_key' => 'scholar/p1', 'parent_id' => $w['shared'], 'offer_row_id' => $w['offerA'], 'crm_product_id' => $w['crmA'], 'origin' => 'portfolio']);

        $w['offerB'] = $this->offer(self::T, 'P2', 'Scholar Two', 'scholar k-12');
        $w['offerOnly'] = $this->master(['name' => 'Scholar Two', 'product_key' => 'scholar/p2', 'parent_id' => $w['shared'], 'offer_row_id' => $w['offerB'], 'origin' => 'portfolio']);

        $w['crmC'] = $this->crm(self::T, 'Training service', $w['shared']);
        $w['crmOnly'] = $this->master(['kind' => 'item', 'name' => 'Training service', 'product_key' => 'crm/p3', 'parent_id' => $w['shared'], 'crm_product_id' => $w['crmC'], 'origin' => 'crm']);

        $w['binned'] = $this->crm(self::T, 'Old item', null, ['deleted_at' => self::STAMP]);

        $w['crmD'] = $this->crm(self::T2, 'Their item', null);
        $w['theirs'] = $this->master(['sub_institute_id' => self::T2, 'kind' => 'item', 'name' => 'Their item', 'product_key' => 'crm/d', 'crm_product_id' => $w['crmD'], 'origin' => 'crm']);

        foreach (ProductPointers::toCrmProduct() as $e) {
            $this->pointRow($e, self::T, $w['crmA']);
        }
        foreach (ProductPointers::toMaster() as $e) {
            $this->pointRow($e, self::T, isset($e['target_kind']) ? $w['shared'] : $w['both']);
        }

        return $w;
    }

    /** Every row of every table, for "nothing changed". @return array<string, list<array<string, mixed>>> */
    protected function dumpEverything(): array
    {
        $all = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $all[$name] = DB::table($name)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        ksort($all);

        return $all;
    }
}
