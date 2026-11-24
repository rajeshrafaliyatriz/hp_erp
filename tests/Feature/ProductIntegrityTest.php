<?php

namespace Tests\Feature;

use App\Console\Commands\ProductMasterCheckCommand;
use App\Domain\ProductMaster\MigrationColumns;
use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductPointers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductPointerWorld;
use Tests\TestCase;

/**
 * The read-only product checker (S15 plan section 2.1): `php artisan product-master:check` and the ProductIntegrity class behind it, on in-memory sqlite.
 *
 * A clean world passes; for each invariant there is ONE corrupt world that names the offending row (and the pointer invariants run once for EVERY pointer in the
 * registry, built from it); the census is proved by taking one, changing the database one way at a time, and reading the difference; and the command is proved
 * read-only twice over (every table is the same afterwards, and every statement it sent was a SELECT).
 */
class ProductIntegrityTest extends TestCase
{
    use BuildsProductPointerWorld;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductPointerWorld();
        $this->completeColumnsForTheSchemaWarning();
    }

    /**
     * The command also runs W3 (schema drift, tests/Feature/ProductSchemaDriftTest.php), and this world builds the offers and catalogue tables minimal. Every column
     * their migrations declare is added (all nullable text: only the names matter to W3), so a clean world is still clean and W3 does not drown the other findings.
     */
    private function completeColumnsForTheSchemaWarning(): void
    {
        foreach ([ProductPointers::OFFERS, ProductPointers::CRM_PRODUCTS] as $table) {
            foreach (array_keys(MigrationColumns::columns(MigrationColumns::directory(), $table)) as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn ($t) => $t->text($column)->nullable());
                }
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function check(?int $tenant = null): array
    {
        return (new ProductIntegrity(null, $tenant))->check();
    }

    /** @return array<string, mixed> */
    private function census(?int $tenant = null): array
    {
        return (new ProductIntegrity(null, $tenant))->census();
    }

    /** @return array<string, int> the invariants that fired, with their counts */
    private function fired(array $report): array
    {
        return array_filter($report['counts']);
    }

    /** @return list<array<string, mixed>> */
    private function findings(array $report, string $code): array
    {
        return array_values(array_filter($report['findings'], fn (array $f): bool => $f['code'] === $code));
    }

    /** @return array{0: int, 1: string} exit code and output */
    private function runCommand(array $options = []): array
    {
        $exit = Artisan::call('product-master:check', $options);

        return [$exit, Artisan::output()];
    }

    private function tempFile(): string
    {
        $path = sys_get_temp_dir() . '/pm-census-' . bin2hex(random_bytes(4)) . '.json';
        $this->files[] = $path;

        return $path;
    }

    /** The unique keys of the real table forbid two rows with one pointer; I3 is the proof where they are absent, so the tests that need duplicates drop them. */
    private function dropPointerUniqueKeys(): void
    {
        Schema::table('g2g_product_master', function ($t) {
            $t->dropUnique('g2g_pm_tenant_offer');
            $t->dropUnique('g2g_pm_tenant_crm');
        });
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function everyPointer(): iterable
    {
        foreach (ProductPointers::all() as $e) {
            yield "{$e['table']}.{$e['column']}" => [$e['table'], $e['column']];
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> the pointers that can cross to another organisation (a bundle's parent is its own organisation by definition) */
    public static function crossablePointers(): iterable
    {
        foreach (ProductPointers::all() as $e) {
            if (! is_array($e['scope']) || $e['scope']['via'] !== $e['column']) {
                yield "{$e['table']}.{$e['column']}" => [$e['table'], $e['column']];
            }
        }
    }

    // ------------------------------------------------------------------ a clean world

    public function test_a_clean_world_passes_every_invariant_and_examines_every_pointer(): void
    {
        $this->cleanWorld();

        $report = $this->check();

        $this->assertSame([], $this->fired($report), 'a coherent world fires nothing');
        $this->assertSame(0, $report['errors']);
        $this->assertSame(0, $report['warnings']);
        $this->assertSame([], $report['findings']);
        $this->assertSame([], $report['skipped'], 'every table of the registry is on this host');
        $this->assertSame(22, $report['checked']['pointers'], 'all 22 registered pointers were examined');
        $this->assertSame(6, $report['checked']['masterRows']);
        $this->assertSame(3, $report['checked']['catalogueItems'], 'three live items (the binned ones are not live)');
        $this->assertSame(2, $report['checked']['offers']);
        $this->assertSame(array_keys(ProductIntegrity::CODES), array_keys($report['counts']));
    }

    public function test_the_command_says_ok_and_exits_zero_on_a_clean_world(): void
    {
        $this->cleanWorld();

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('product-master:check (READ ONLY)', $output);
        $this->assertStringContainsString('connection sqlite', $output, 'it prints which server it read');
        $this->assertStringContainsString('session read-only: not needed on sqlite', $output, 'it asked the session to be read-only (sqlite has no such setting)');
        $this->assertStringContainsString('Scope: every organisation', $output);
        $this->assertStringContainsString('22 pointers in 20 tables (12 at crm_products.id, 10 at g2g_product_master.id); 22 examined on this host', $output);
        $this->assertStringContainsString('RESULT: OK (0 errors, 0 warning(s))', $output);
        foreach (array_keys(ProductIntegrity::CODES) as $code) {
            $this->assertMatchesRegularExpression('/^\s+' . $code . '\s+(error|warning)\s+0\s+\S/m', $output, "{$code} is listed with its count");
        }
    }

    public function test_a_pointer_into_a_leftover_row_of_organisation_0_is_not_a_crossing_and_the_row_itself_is_not_isolated(): void
    {
        // CHANGED ON PURPOSE (S15 follow-up, 24 Nov 2026): nothing is shared any more, so a row of organisation 0 is a leftover. The registry's tolerance for a pointer into it is unchanged
        // (no I1, no I1T: that is not what is wrong), and the one thing wrong is reported once, as `not_isolated`, on the row.
        $shared = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', 0);
        $sharedCrm = $this->crm(0, 'Shared catalogue item', null, ['deleted_at' => self::STAMP]);
        foreach (ProductPointers::all() as $e) {
            $this->pointRow($e, self::T, $e['target'] === ProductPointers::CRM_PRODUCTS ? $sharedCrm : $shared);
        }

        $report = $this->check();

        $this->assertSame(['not_isolated' => 1], $this->fired($report), 'a pointer at organisation 0 is neither I1 nor I1T for any of the 22 pointers; the leftover Product is the one finding');
    }

    public function test_a_pointer_with_no_value_or_with_zero_is_not_a_pointer(): void
    {
        foreach (ProductPointers::all() as $e) {
            $this->pointRow($e, self::T, null);
            $this->pointRow($e, self::T, 0);
        }

        $this->assertSame([], $this->fired($this->check()));
    }

    // ------------------------------------------------------------------ I1: a pointer at a row that is not there

    #[DataProvider('everyPointer')]
    public function test_every_registered_pointer_that_points_at_nothing_is_an_I1_error_naming_the_table_the_column_and_the_row(string $table, string $column): void
    {
        $entry = $this->entry($table, $column);
        $row = $this->pointRow($entry, self::T, 999999);

        $report = $this->check();

        $this->assertSame(['I1' => 1], $this->fired($report), "{$table}.{$column} holding 999999 is the only thing wrong");
        $this->assertSame(1, $report['errors']);
        $finding = $this->findings($report, 'I1')[0];
        $this->assertSame($table, $finding['table']);
        $this->assertSame($column, $finding['column']);
        $this->assertSame($row, $finding['id'], 'the offending row is named');
        $this->assertSame(999999, $finding['value']);
        $this->assertSame('error', $finding['severity']);
        $this->assertStringContainsString("{$table}.{$column}: row {$row} points at {$entry['target']} 999999, which does not exist", $finding['message']);
    }

    #[DataProvider('crossablePointers')]
    public function test_every_registered_pointer_into_another_organisation_is_counted_as_an_I1T_error(string $table, string $column): void
    {
        $entry = $this->entry($table, $column);
        $theirs = $entry['target'] === ProductPointers::CRM_PRODUCTS
            ? $this->crm(self::T2, 'Theirs', null, ['deleted_at' => self::STAMP])
            : $this->product('theirs', 'Theirs', 'Theirs', self::T2);
        $row = $this->pointRow($entry, self::T, $theirs);

        $report = $this->check();

        $this->assertSame(['I1T' => 1], $this->fired($report), "{$table}.{$column} of organisation 1 pointing at a row of organisation 2");
        $finding = $this->findings($report, 'I1T')[0];
        $this->assertSame($table, $finding['table']);
        $this->assertSame($column, $finding['column']);
        $this->assertSame($row, $finding['id']);
        $this->assertSame(self::T, $finding['tenant'], 'the organisation of the row that holds the pointer');
        $this->assertStringContainsString("row {$row} of organisation 1 points at {$entry['target']} {$theirs} of organisation 2", $finding['message']);
    }

    public function test_the_product_line_of_a_catalogue_item_must_be_a_product_not_a_sub_product(): void
    {
        $sub = $this->master(['kind' => 'sub_product', 'product_key' => 'sub/x']);
        $item = $this->crm(self::T, 'Wrongly lined', $sub, ['deleted_at' => self::STAMP]);

        $report = $this->check();

        $this->assertSame(['I1' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I1')[0];
        $this->assertSame('crm_products', $finding['table']);
        $this->assertSame($item, $finding['id']);
        $this->assertStringContainsString("points at g2g_product_master {$sub}, which is a sub_product, not a product", $finding['message']);
    }

    public function test_the_other_nine_master_pointers_may_point_at_a_sub_product(): void
    {
        $sub = $this->master(['kind' => 'sub_product', 'product_key' => 'sub/x']);
        foreach (ProductPointers::toMaster() as $e) {
            if (! isset($e['target_kind'])) {
                $this->pointRow($e, self::T, $sub);
            }
        }

        $this->assertSame([], $this->fired($this->check()));
    }

    public function test_a_whole_database_run_still_sees_a_line_whose_document_is_gone_but_an_organisation_run_cannot_place_it(): void
    {
        $line = DB::table('crm_quote_line_items')->insertGetId(['quote_id' => 777, 'product_id' => 999999, 'updated_at' => self::STAMP]);

        $this->assertSame(['I1' => 1], $this->fired($this->check()), 'the line is an orphan whatever happened to its quote');
        $this->assertSame($line, $this->findings($this->check(), 'I1')[0]['id']);
        $this->assertSame([], $this->fired($this->check(self::T)), 'its organisation cannot be told, so an organisation run does not claim it');
    }

    public function test_the_findings_are_capped_but_the_count_is_exact(): void
    {
        $entry = $this->entry('crm_tickets', 'product_id');
        for ($i = 0; $i < ProductIntegrity::SAMPLE + 10; $i++) {
            $this->pointRow($entry, self::T, 900000 + $i);
        }

        $report = $this->check();

        $this->assertSame(ProductIntegrity::SAMPLE + 10, $report['counts']['I1'], 'every orphan is counted');
        $this->assertCount(ProductIntegrity::SAMPLE, $this->findings($report, 'I1'), 'only the first ones are kept as findings');
        $this->assertSame(ProductIntegrity::SAMPLE + 10, $report['errors']);

        [$exit, $output] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[I1] ... and ' . (ProductIntegrity::SAMPLE + 10 - 25) . ' more', $output, 'the command prints 25 and says how many more');
    }

    public function test_findings_of_one_kind_that_come_from_many_master_rows_are_capped_too_and_still_counted(): void
    {
        // The pointer queries only fetch SAMPLE rows each, so the cap that stops a flood from many ROWS is a separate guard and needs its own proof.
        for ($i = 0; $i < ProductIntegrity::SAMPLE + 5; $i++) {
            $this->master(['offer_row_id' => 800000 + $i, 'product_key' => 'bad/' . $i]);
        }

        $report = $this->check();

        $this->assertSame(ProductIntegrity::SAMPLE + 5, $report['counts']['I2']);
        $this->assertSame(ProductIntegrity::SAMPLE + 5, $report['errors']);
        $found = $this->findings($report, 'I2');
        $this->assertCount(ProductIntegrity::SAMPLE, $found, 'fifty are kept, in row order');
        $this->assertSame(1, $found[0]['id']);
        $this->assertSame(ProductIntegrity::SAMPLE, $found[ProductIntegrity::SAMPLE - 1]['id']);
    }

    public function test_the_findings_of_one_kind_from_several_pointers_share_one_cap(): void
    {
        foreach (['crm_tickets', 'crm_assets', 'crm_faqs', 'crm_campaigns'] as $table) {
            for ($i = 0; $i < 15; $i++) {
                $this->pointRow($this->entry($table, 'product_id'), self::T, 700000 + $i);
            }
        }

        $report = $this->check();

        $this->assertSame(60, $report['counts']['I1'], 'four tables of fifteen orphans each');
        $this->assertCount(ProductIntegrity::SAMPLE, $this->findings($report, 'I1'), 'one cap for the kind, not one per table');
    }

    // ------------------------------------------------------------------ I2: the master row's own pointers

    public function test_a_master_row_whose_catalogue_item_is_missing_is_an_I2_error_naming_the_row(): void
    {
        $row = $this->master(['kind' => 'item', 'crm_product_id' => 4242]);

        $report = $this->check();

        $this->assertSame(['I2' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I2')[0];
        $this->assertSame($row, $finding['id']);
        $this->assertSame("master row {$row}: crm_product_id 4242 does not exist in crm_products", $finding['message']);
    }

    public function test_a_master_row_whose_offer_is_missing_is_an_I2_error_naming_the_row(): void
    {
        $row = $this->master(['offer_row_id' => 5151]);

        $report = $this->check();

        $this->assertSame(['I2' => 1], $this->fired($report));
        $this->assertSame("master row {$row}: offer_row_id 5151 does not exist in g2g_product_offers", $this->findings($report, 'I2')[0]['message']);
    }

    public function test_a_master_row_whose_parent_is_missing_or_is_not_a_product_is_an_I2_error(): void
    {
        $missing = $this->master(['parent_id' => 6161]);
        $sub = $this->master(['kind' => 'sub_product', 'product_key' => 'sub/y']);
        $child = $this->master(['parent_id' => $sub, 'product_key' => 'sub/z']);

        $report = $this->check();

        $this->assertSame(['I2' => 2], $this->fired($report));
        $messages = array_column($this->findings($report, 'I2'), 'message');
        $this->assertContains("master row {$missing}: parent_id 6161 does not exist in g2g_product_master", $messages);
        $this->assertContains("master row {$child}: parent_id {$sub} is a sub_product, not a product", $messages);
    }

    public function test_a_master_row_may_not_point_at_a_row_of_another_organisation_or_at_an_about_with_no_organisation(): void
    {
        $theirsCrm = $this->crm(self::T2, 'Theirs', null, ['deleted_at' => self::STAMP]);
        $theirsOffer = $this->offer(self::T2, 'X1', 'Theirs', 'Theirs');
        $theirsProduct = $this->product('theirs', 'Theirs', 'Theirs', self::T2);
        $sharedOffer = $this->offer(null, 'S1', 'Shared offer', 'Theirs');

        $a = $this->master(['kind' => 'item', 'crm_product_id' => $theirsCrm, 'product_key' => 'a']);
        $b = $this->master(['offer_row_id' => $theirsOffer, 'product_key' => 'b']);
        $c = $this->master(['parent_id' => $theirsProduct, 'product_key' => 'c']);
        $d = $this->master(['offer_row_id' => $sharedOffer, 'product_key' => 'd']);

        $report = $this->check();

        $this->assertSame(['I2' => 4, 'I4' => 2, 'not_isolated' => 1], $this->fired($report), 'four rows point across; the two offers they point at have no master row of their own organisation; and the About with no organisation is not isolated');
        $by = [];
        foreach ($this->findings($report, 'I2') as $f) {
            $by[$f['id']] = $f['message'];
        }
        $this->assertStringContainsString("crm_product_id {$theirsCrm} belongs to organisation 2", $by[$a]);
        $this->assertStringContainsString("offer_row_id {$theirsOffer} belongs to organisation 2", $by[$b]);
        $this->assertStringContainsString("parent_id {$theirsProduct} belongs to organisation 2", $by[$c]);
        $this->assertStringContainsString("offer_row_id {$sharedOffer} belongs to organisation 0", $by[$d], 'an organisation\'s row may not hold the shared offer');
    }

    public function test_a_leftover_shared_row_holding_a_leftover_offer_is_two_not_isolated_findings_and_nothing_else(): void
    {
        $sharedOffer = $this->offer(null, 'S1', 'Shared offer', 'Scholar K-12');
        $parent = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', 0);
        $this->master(['sub_institute_id' => 0, 'product_key' => 'scholar/s1', 'name' => 'Shared offer', 'offer_row_id' => $sharedOffer, 'parent_id' => $parent]);

        // the two master rows of organisation 0 (the Product and the Sub-product) and the About with no organisation: three leftovers, nothing else wrong
        $this->assertSame(['not_isolated' => 3], $this->fired($this->check()));
    }

    // ------------------------------------------------------------------ I3: two live rows, one pointer

    public function test_two_live_master_rows_of_one_organisation_sharing_a_catalogue_item_are_an_I3_error_naming_both(): void
    {
        $this->dropPointerUniqueKeys();
        $crm = $this->crm(self::T, 'Twice', null);
        $a = $this->master(['kind' => 'item', 'name' => 'Twice', 'crm_product_id' => $crm, 'product_key' => 'a']);
        $b = $this->master(['kind' => 'item', 'name' => 'Twice', 'crm_product_id' => $crm, 'product_key' => 'b']);

        $report = $this->check();

        $this->assertSame(['I3' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I3')[0];
        $this->assertSame("master rows {$a}, {$b} (organisation 1) are all live and all hold crm_product_id {$crm}", $finding['message']);
        $this->assertSame($a, $finding['id']);
    }

    public function test_two_live_master_rows_sharing_an_offer_are_an_I3_error(): void
    {
        $this->dropPointerUniqueKeys();
        $offer = $this->offer(self::T, 'P1', 'Twice', 'Scholar K-12');
        $parent = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', self::T);
        $a = $this->master(['name' => 'Twice', 'offer_row_id' => $offer, 'parent_id' => $parent, 'product_key' => 'a']);
        $b = $this->master(['name' => 'Twice', 'offer_row_id' => $offer, 'parent_id' => $parent, 'product_key' => 'b']);

        $report = $this->check();

        $this->assertSame(['I3' => 1], $this->fired($report));
        $this->assertStringContainsString("master rows {$a}, {$b} (organisation 1) are all live and all hold offer_row_id {$offer}", $this->findings($report, 'I3')[0]['message']);
    }

    public function test_an_archived_twin_or_the_same_pointer_in_another_organisation_is_not_an_I3_error(): void
    {
        $this->dropPointerUniqueKeys();
        $crm = $this->crm(self::T, 'Once', null);
        $this->master(['kind' => 'item', 'name' => 'Once', 'crm_product_id' => $crm, 'product_key' => 'live']);
        $this->master(['kind' => 'item', 'name' => 'Once', 'crm_product_id' => $crm, 'product_key' => 'old', 'status' => 'archived']);
        $this->master(['sub_institute_id' => self::T2, 'kind' => 'item', 'name' => 'Once', 'crm_product_id' => $crm, 'product_key' => 'other']);

        $this->assertSame(0, $this->check()['counts']['I3']);
    }

    public function test_the_unique_keys_of_the_real_table_already_refuse_a_duplicate_so_i3_is_a_second_line_of_defence(): void
    {
        $crm = $this->crm(self::T, 'Once', null);
        $this->master(['kind' => 'item', 'crm_product_id' => $crm, 'product_key' => 'a']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->master(['kind' => 'item', 'crm_product_id' => $crm, 'product_key' => 'b']);
    }

    // ------------------------------------------------------------------ I4: nobody points at it

    public function test_a_live_catalogue_item_with_no_master_row_is_an_I4_error_naming_the_item(): void
    {
        $lonely = $this->crm(self::T, 'Lonely item');

        $report = $this->check();

        $this->assertSame(['I4' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I4')[0];
        $this->assertSame($lonely, $finding['id']);
        $this->assertSame("catalogue item {$lonely} (organisation 1, 'Lonely item') has no master row", $finding['message']);
    }

    public function test_a_catalogue_item_in_the_recycle_bin_or_one_with_an_archived_master_row_is_fine(): void
    {
        $this->crm(self::T, 'Binned', null, ['deleted_at' => self::STAMP]);
        $kept = $this->crm(self::T, 'Archived', null);
        $this->master(['kind' => 'item', 'name' => 'Archived', 'crm_product_id' => $kept, 'status' => 'archived']);

        $this->assertSame(0, $this->check()['counts']['I4']);
    }

    public function test_a_catalogue_item_whose_master_row_belongs_to_another_organisation_has_no_master_row(): void
    {
        $item = $this->crm(self::T, 'Mislaid');
        $this->master(['sub_institute_id' => self::T2, 'kind' => 'item', 'name' => 'Mislaid', 'crm_product_id' => $item]);

        $report = $this->check();

        $this->assertSame(1, $report['counts']['I4']);
        $this->assertSame($item, $this->findings($report, 'I4')[0]['id']);
    }

    public function test_a_portfolio_offer_with_no_master_row_is_an_I4_error_naming_the_offer(): void
    {
        $offer = $this->offer(self::T, 'P9', 'Unwired offer', 'Scholar K-12');

        $report = $this->check();

        $this->assertSame(['I4' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I4')[0];
        $this->assertSame($offer, $finding['id']);
        $this->assertSame("Portfolio offer {$offer} (P9, organisation 1, 'Unwired offer') has no master row", $finding['message']);
    }

    public function test_a_shared_offer_needs_a_master_row_of_organisation_zero(): void
    {
        $offer = $this->offer(null, 'S1', 'Shared offer', 'Scholar K-12');
        $parent = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', 0);
        $this->master(['sub_institute_id' => 0, 'product_key' => 'scholar/s1', 'name' => 'Shared offer', 'offer_row_id' => $offer, 'parent_id' => $parent]);
        $this->assertSame(0, $this->check()['counts']['I4'], 'organisation 0 covers an offer that belongs to nobody');

        DB::table('g2g_product_master')->where('offer_row_id', $offer)->update(['sub_institute_id' => self::T]);
        $report = $this->check();
        $this->assertSame(1, $report['counts']['I4'], 'the same row filed under organisation 1 does not cover it');
        $this->assertSame($offer, $this->findings($report, 'I4')[0]['id']);
    }

    // ------------------------------------------------------------------ I5: the stores agree

    public function test_a_master_name_that_differs_from_its_offer_is_an_I5_error_naming_the_row_and_both_names(): void
    {
        $w = $this->cleanWorld();
        DB::table('g2g_product_offers')->where('id', $w['offerB'])->update(['name' => 'Scholar Two (edited in the Portfolio)']);

        $report = $this->check();

        $this->assertSame(['I5' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I5')[0];
        $this->assertSame($w['offerOnly'], $finding['id']);
        $this->assertSame("master row {$w['offerOnly']}: the names disagree: master 'Scholar Two', Portfolio offer {$w['offerB']} 'Scholar Two (edited in the Portfolio)'", $finding['message']);
    }

    public function test_a_master_name_that_differs_from_its_catalogue_item_is_an_I5_error(): void
    {
        $w = $this->cleanWorld();
        DB::table('crm_products')->where('id', $w['crmC'])->update(['name' => 'Training (renamed in the catalogue)']);

        $report = $this->check();

        $this->assertSame(['I5' => 1], $this->fired($report));
        $this->assertSame($w['crmOnly'], $this->findings($report, 'I5')[0]['id']);
        $this->assertStringContainsString("catalogue item {$w['crmC']} 'Training (renamed in the catalogue)'", $this->findings($report, 'I5')[0]['message']);
    }

    public function test_on_a_row_with_both_pointers_the_offer_and_the_item_may_not_disagree_with_each_other(): void
    {
        $w = $this->cleanWorld();
        DB::table('g2g_product_offers')->where('id', $w['offerA'])->update(['name' => 'Scholar One (Portfolio)']);
        DB::table('crm_products')->where('id', $w['crmA'])->update(['name' => 'Scholar One (catalogue)']);

        $report = $this->check();

        $this->assertSame(['I5' => 1], $this->fired($report), 'one finding for the row, listing every name');
        $message = $this->findings($report, 'I5')[0]['message'];
        $this->assertStringContainsString("master 'Scholar One'", $message);
        $this->assertStringContainsString("Portfolio offer {$w['offerA']} 'Scholar One (Portfolio)'", $message);
        $this->assertStringContainsString("catalogue item {$w['crmA']} 'Scholar One (catalogue)'", $message);
    }

    public function test_names_are_compared_exactly_after_trimming_so_whitespace_is_not_a_difference_but_case_is(): void
    {
        $w = $this->cleanWorld();
        DB::table('g2g_product_offers')->where('id', $w['offerB'])->update(['name' => "  Scholar Two \t"]);
        $this->assertSame(0, $this->check()['counts']['I5'], 'surrounding whitespace is not a difference');

        DB::table('g2g_product_offers')->where('id', $w['offerB'])->update(['name' => 'scholar two']);
        $this->assertSame(1, $this->check()['counts']['I5'], 'a change of case is a difference that a rename mirror has to carry');
    }

    public function test_an_offer_filed_under_another_product_than_the_masters_parent_is_an_I5_error(): void
    {
        $w = $this->cleanWorld();
        DB::table('g2g_product_offers')->where('id', $w['offerA'])->update(['parent_product' => 'Enterprise Brain']);

        $report = $this->check();

        $this->assertSame(['I5' => 1], $this->fired($report));
        $finding = $this->findings($report, 'I5')[0];
        $this->assertSame($w['both'], $finding['id']);
        $this->assertSame("master row {$w['both']}: Portfolio offer {$w['offerA']} is under 'Enterprise Brain' but its parent product {$w['shared']} is 'Scholar K-12'", $finding['message']);
    }

    public function test_an_offer_under_a_product_but_a_master_row_without_a_parent_is_an_I5_error(): void
    {
        $w = $this->cleanWorld();
        DB::table('g2g_product_master')->where('id', $w['offerOnly'])->update(['parent_id' => null]);

        $report = $this->check();

        $this->assertSame(['I5' => 1], $this->fired($report));
        $this->assertStringContainsString('but the master row has no parent product', $this->findings($report, 'I5')[0]['message']);
    }

    public function test_a_catalogue_items_product_line_that_differs_from_the_masters_parent_is_an_I5_error_on_both_kinds_of_row(): void
    {
        $w = $this->cleanWorld();
        $other = $this->product('payroll2', 'Payroll 2', 'Payroll 2', self::T);
        DB::table('crm_products')->where('id', $w['crmC'])->update(['product_master_id' => $other]);
        DB::table('crm_products')->where('id', $w['crmA'])->update(['product_master_id' => null]);

        $report = $this->check();

        $this->assertSame(['I5' => 2], $this->fired($report));
        $by = [];
        foreach ($this->findings($report, 'I5') as $f) {
            $by[$f['id']] = $f['message'];
        }
        $this->assertSame("master row {$w['crmOnly']}: parent_id is {$w['shared']} but catalogue item {$w['crmC']} has product_master_id {$other}", $by[$w['crmOnly']], 'a row that holds only the catalogue pointer');
        $this->assertSame("master row {$w['both']}: parent_id is {$w['shared']} but catalogue item {$w['crmA']} has product_master_id 0", $by[$w['both']], 'a row that holds both');
    }

    public function test_what_did_not_resolve_is_reported_once_as_I2_and_is_not_compared_again_as_I5(): void
    {
        $this->master(['kind' => 'item', 'name' => 'Ghost', 'crm_product_id' => 4242, 'offer_row_id' => 5151, 'parent_id' => 6161]);

        $this->assertSame(['I2' => 3], $this->fired($this->check()));
    }

    // ------------------------------------------------------------------ W1, W2: warnings only

    public function test_a_live_master_row_pointing_at_a_binned_catalogue_item_is_a_warning_and_does_not_fail_the_run(): void
    {
        $w = $this->cleanWorld();
        DB::table('crm_products')->where('id', $w['crmC'])->update(['deleted_at' => self::STAMP]);

        $report = $this->check();

        $this->assertSame(['W1' => 1], $this->fired($report));
        $this->assertSame(0, $report['errors']);
        $this->assertSame(1, $report['warnings']);
        $finding = $this->findings($report, 'W1')[0];
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame($w['crmOnly'], $finding['id']);
        $this->assertSame("master row {$w['crmOnly']} is live but its catalogue item {$w['crmC']} is in the Recycle Bin", $finding['message']);

        [$exit, $output] = $this->runCommand();
        $this->assertSame(0, $exit, 'a warning is printed, it does not fail the run');
        $this->assertStringContainsString('RESULT: OK (0 errors, 1 warning(s))', $output);
        $this->assertStringContainsString("[W1] master row {$w['crmOnly']} is live", $output);
    }

    public function test_an_archived_master_row_pointing_at_a_binned_item_is_not_a_warning(): void
    {
        $w = $this->cleanWorld();
        DB::table('crm_products')->where('id', $w['crmC'])->update(['deleted_at' => self::STAMP]);
        DB::table('g2g_product_master')->where('id', $w['crmOnly'])->update(['status' => 'archived']);

        $this->assertSame([], $this->fired($this->check()));
    }

    public function test_a_leftover_master_row_holding_a_catalogue_pointer_is_a_warning_naming_the_row_and_is_not_isolated(): void
    {
        $crm = $this->crm(0, 'Shared item');
        $row = $this->master(['sub_institute_id' => 0, 'kind' => 'item', 'name' => 'Shared item', 'crm_product_id' => $crm, 'product_key' => 'shared/crm']);

        $report = $this->check();

        $this->assertSame(['not_isolated' => 1, 'W2' => 1], $this->fired($report));
        $this->assertSame(1, $report['errors'], 'the leftover itself is an error now (not_isolated); the catalogue pointer on it stays a warning');
        $finding = $this->findings($report, 'W2')[0];
        $this->assertSame($row, $finding['id']);
        $this->assertSame("master row {$row} is shared (organisation 0) but holds crm_product_id {$crm}", $finding['message']);
    }

    public function test_the_command_fails_on_an_error_names_the_row_and_still_prints_the_warnings(): void
    {
        $w = $this->cleanWorld();
        DB::table('crm_products')->where('id', $w['crmC'])->update(['deleted_at' => self::STAMP]);
        $lonely = $this->crm(self::T, 'Lonely item');

        [$exit, $output] = $this->runCommand();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("[I4] catalogue item {$lonely} (organisation 1, 'Lonely item') has no master row", $output);
        $this->assertStringContainsString('[W1]', $output);
        $this->assertStringContainsString('RESULT: FAILED (1 error(s), 1 warning(s))', $output);
    }

    // ------------------------------------------------------------------ one organisation at a time

    public function test_an_organisation_run_looks_only_at_that_organisations_rows(): void
    {
        $this->cleanWorld();
        $one = $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, 111111);
        $oneLine = $this->line('crm_quote_line_items', self::T, 222222, 1, 10);
        $two = $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T2, 333333);
        $twoLine = $this->line('crm_quote_line_items', self::T2, 444444, 1, 10);
        $twoLonely = $this->crm(self::T2, 'Lonely item of two');
        $oneMaster = $this->master(['offer_row_id' => 5151, 'product_key' => 'bad/one']);
        $twoMaster = $this->master(['sub_institute_id' => self::T2, 'offer_row_id' => 6161, 'product_key' => 'bad/two']);

        $all = $this->check();
        $first = $this->check(self::T);
        $second = $this->check(self::T2);

        $this->assertSame(['I1' => 4, 'I2' => 2, 'I4' => 1], $this->fired($all));
        $this->assertSame(['I1' => 2, 'I2' => 1], $this->fired($first));
        $this->assertEqualsCanonicalizing([$one, $oneLine], array_column($this->findings($first, 'I1'), 'id'));
        $this->assertSame([$oneMaster], array_column($this->findings($first, 'I2'), 'id'), 'a master row of organisation 2 is not organisation 1\'s finding');
        $this->assertSame(['I1' => 2, 'I2' => 1, 'I4' => 1], $this->fired($second));
        $this->assertEqualsCanonicalizing([$two, $twoLine], array_column($this->findings($second, 'I1'), 'id'));
        $this->assertSame([$twoMaster], array_column($this->findings($second, 'I2'), 'id'));
        $this->assertSame($twoLonely, $this->findings($second, 'I4')[0]['id']);
        $this->assertSame(6, $first['checked']['masterRows'], 'organisation 1\'s master rows: its two products, the three sub-products and the broken one');
        $this->assertSame(2, $second['checked']['masterRows']);

        [$exit, $output] = $this->runCommand(['--tenant' => 1]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Scope: organisation 1', $output);
        $this->assertStringContainsString("row {$one} points at", $output);
        $this->assertStringNotContainsString("row {$two} points at", $output);
    }

    public function test_the_leftover_rows_are_checked_with_tenant_zero_and_a_bad_tenant_is_refused(): void
    {
        $crm = $this->crm(0, 'Shared item');
        $this->master(['sub_institute_id' => 0, 'kind' => 'item', 'name' => 'Shared item', 'crm_product_id' => $crm, 'product_key' => 'shared/crm']);

        $this->assertSame(['not_isolated' => 1, 'W2' => 1], $this->fired($this->check(0)));
        $this->assertSame([], $this->fired($this->check(self::T)), 'organisation 1 does not own the leftover rows');

        [$exit, $output] = $this->runCommand(['--tenant' => '0']);
        $this->assertSame(1, $exit, 'organisation 0 is a real scope (the leftovers), and a leftover is an error now: not_isolated');
        $this->assertStringContainsString('Scope: organisation 0', $output);
        $this->assertStringContainsString('[W2]', $output);
        $this->assertStringContainsString('[not_isolated]', $output);

        [$exit, $output] = $this->runCommand(['--tenant' => 'six']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--tenant must be a whole number', $output);
    }

    // ------------------------------------------------------------------ what the host does not have

    public function test_a_table_the_host_does_not_have_is_skipped_and_listed_never_an_error(): void
    {
        $this->cleanWorld();
        Schema::drop('crm_leads');
        Schema::drop('gtm_playbooks');

        $report = $this->check();

        $this->assertSame([], $this->fired($report));
        $this->assertSame(20, $report['checked']['pointers']);
        $this->assertContains('crm_leads.product_master_id: the table is not on this host', $report['skipped']);
        $this->assertContains('gtm_playbooks.product_master_id: the table is not on this host', $report['skipped']);

        [$exit, $output] = $this->runCommand();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Skipped on this host', $output);
        $this->assertStringContainsString('crm_leads.product_master_id: the table is not on this host', $output);
    }

    public function test_a_column_the_host_does_not_have_yet_is_skipped_and_the_product_line_check_with_it(): void
    {
        $w = $this->cleanWorld();
        Schema::table('crm_products', fn ($t) => $t->dropColumn('product_master_id'));
        DB::table('g2g_product_master')->where('id', $w['crmOnly'])->update(['parent_id' => $w['payroll']]);

        $report = $this->check();

        $this->assertSame([], $this->fired($report), 'without crm_products.product_master_id there is no product line to compare');
        $this->assertContains('crm_products.product_master_id: the column is not on this host', $report['skipped']);
    }

    public function test_a_host_without_the_master_table_still_checks_the_pointers_at_the_catalogue(): void
    {
        $this->cleanWorld();
        $orphan = $this->pointRow($this->entry('crm_quote_line_items', 'product_id'), self::T, 999999);
        Schema::drop('g2g_product_master');

        $report = $this->check();

        $this->assertSame(['I1' => 1], $this->fired($report), 'the 12 pointers at crm_products.id do not need the master table');
        $this->assertSame($orphan, $this->findings($report, 'I1')[0]['id']);
        $this->assertSame(12, $report['checked']['pointers'], 'the 10 pointers at the master table are skipped');
        $this->assertContains('crm_leads.product_master_id: its target table g2g_product_master is not on this host', $report['skipped']);
        $this->assertContains('g2g_product_master: the table is not on this host, so I2, I3, I4, I5, W1 and W2 were skipped', $report['skipped']);
    }

    public function test_a_host_without_the_catalogue_or_the_offers_table_still_checks_the_master_rows_and_says_what_it_left_out(): void
    {
        $this->cleanWorld();
        Schema::drop('crm_products');
        Schema::drop('g2g_product_offers');

        $report = $this->check();

        $this->assertSame([], $this->fired($report), 'what cannot be followed is not an error');
        $this->assertContains('crm_products: the table is not on this host, so the catalogue pointers of the master rows were not followed', $report['skipped']);
        $this->assertContains('g2g_product_offers: the table is not on this host, so the offer pointers of the master rows were not followed', $report['skipped']);
        $this->assertContains('crm_products: the table (or its deleted_at) is not on this host, so I4 for catalogue items was skipped', $report['skipped']);
        $this->assertContains('g2g_product_offers: the table is not on this host, so I4 for Portfolio offers was skipped', $report['skipped']);
        $this->assertSame(0, $report['checked']['catalogueItems'] + $report['checked']['offers']);
        $this->assertSame(6, $report['checked']['masterRows'], 'the master rows themselves were still read');
    }

    public function test_an_organisation_run_skips_a_pointer_whose_organisation_this_host_cannot_find_but_a_whole_database_run_still_reads_it(): void
    {
        $line = $this->line('crm_quote_line_items', self::T, 999999, 1, 10);
        Schema::drop('crm_quotes');

        $whole = $this->check();
        $one = $this->check(self::T);

        $this->assertSame(['I1' => 1], $this->fired($whole), 'without its document table the line can still be tested for an orphan product');
        $this->assertSame($line, $this->findings($whole, 'I1')[0]['id']);
        $this->assertSame(22, $whole['checked']['pointers']);
        $this->assertSame([], $this->fired($one));
        $this->assertSame(21, $one['checked']['pointers']);
        $this->assertContains('crm_quote_line_items.product_id: cannot be limited to one organisation on this host', $one['skipped']);
        $this->assertContains('crm_quote_line_items: cannot be limited to one organisation on this host', $this->census(self::T)['skipped']);
    }

    // ------------------------------------------------------------------ the census

    /** A world with lines on documents of both organisations. @return array<string, int> */
    private function lineWorld(): array
    {
        $w = $this->cleanWorld();
        foreach (['crm_quote_line_items', 'crm_sales_order_line_items', 'crm_invoice_line_items', 'crm_purchase_order_line_items'] as $table) {
            DB::table($table)->delete();
        }
        $this->line('crm_quote_line_items', self::T, $w['crmA'], 2, 100.50);
        $this->line('crm_quote_line_items', self::T, $w['crmA'], 1, 50.25);
        $this->line('crm_quote_line_items', self::T, $w['crmC'], 3, 300);
        $this->line('crm_quote_line_items', self::T, null, 1, 10);
        $this->line('crm_quote_line_items', self::T2, $w['crmD'], 5, 500);
        $this->line('crm_sales_order_line_items', self::T, $w['crmA'], 4, 400);
        $this->line('crm_invoice_line_items', self::T, $w['crmC'], 2, 220.10);
        $this->line('crm_invoice_line_items', self::T2, $w['crmD'], 1, 99.90);
        // The clean world has one opportunity row (amount 0); these two carry the values of two deals.
        $this->pointRow($this->entry('crm_opportunities', 'product_master_id'), self::T, $w['both'], ['amount' => 1000.50]);
        $this->pointRow($this->entry('crm_opportunities', 'product_master_id'), self::T2, $w['theirs'], ['amount' => 250]);

        return $w;
    }

    public function test_the_census_counts_rows_sums_and_the_rows_of_every_product(): void
    {
        $w = $this->lineWorld();

        $census = $this->census();

        $this->assertSame(ProductIntegrity::FORMAT, $census['format']);
        $this->assertNull($census['tenant']);
        $this->assertSame(array_values(ProductPointers::tables()), array_keys($census['tables']), 'one entry per registered table, in registry order');
        $quotes = $census['tables']['crm_quote_line_items'];
        $this->assertSame(5, $quotes['rows']);
        $this->assertSame(['quantity' => '12.00', 'line_total' => '960.75'], $quotes['sums']);
        $this->assertSame(4, $quotes['pointers']['product_id']['set'], 'one of the five lines has no product');
        $this->assertSame([$w['crmA'] => 2, $w['crmC'] => 1, $w['crmD'] => 1], $quotes['pointers']['product_id']['by_product']);

        $this->assertSame(1, $census['tables']['crm_sales_order_line_items']['rows']);
        $this->assertSame(['quantity' => '4.00', 'line_total' => '400.00'], $census['tables']['crm_sales_order_line_items']['sums']);
        $this->assertSame(2, $census['tables']['crm_invoice_line_items']['rows']);
        $this->assertSame('320.00', number_format((float) $census['tables']['crm_invoice_line_items']['sums']['line_total'], 2, '.', ''));
        $this->assertSame(0, $census['tables']['crm_purchase_order_line_items']['rows']);
        $this->assertSame(['quantity' => '0.00', 'line_total' => '0.00'], $census['tables']['crm_purchase_order_line_items']['sums']);

        $bundles = $census['tables']['crm_product_bundle_items']['pointers'];
        $this->assertSame(['parent_product_id', 'component_product_id'], array_keys($bundles), 'both ends of a bundle are counted');
        $this->assertArrayHasKey('by_product', $bundles['component_product_id']);
        $this->assertArrayNotHasKey('by_product', $census['tables']['crm_tickets']['pointers']['product_id'], 'rows per product are counted for the line tables only');
        $this->assertArrayNotHasKey('by_product', $census['tables']['crm_leads']['pointers']['product_master_id']);
        $this->assertSame(['list_price' => '0.00'], $census['tables']['crm_price_book_items']['sums']);
        $deals = $census['tables']['crm_opportunities'];
        $this->assertSame(4, $deals['rows'], 'two from the clean world (one is the document an opportunity product hangs on) and the two deals');
        $this->assertSame(['amount' => '1250.50'], $deals['sums'], 'the value of the deals is summed: a repointed product must not move it');
        $this->assertSame(3, $deals['pointers']['product_master_id']['set']);
    }

    public function test_the_census_of_one_organisation_counts_its_lines_through_their_documents(): void
    {
        $w = $this->lineWorld();

        $census = $this->census(self::T);

        $this->assertSame(self::T, $census['tenant']);
        $quotes = $census['tables']['crm_quote_line_items'];
        $this->assertSame(4, $quotes['rows'], 'the line of organisation 2 is not counted');
        $this->assertSame(['quantity' => '7.00', 'line_total' => '460.75'], $quotes['sums']);
        $this->assertSame([$w['crmA'] => 2, $w['crmC'] => 1], $quotes['pointers']['product_id']['by_product']);
        $this->assertSame(1, $census['tables']['crm_invoice_line_items']['rows']);
        $this->assertSame(3, $census['tables']['crm_opportunities']['rows'], 'organisation 2\'s deal is not counted');
        $this->assertSame(['amount' => '1000.50'], $census['tables']['crm_opportunities']['sums']);
    }

    public function test_the_census_is_the_same_twice_and_differences_of_equal_censuses_are_empty(): void
    {
        $this->lineWorld();

        $this->assertSame($this->census(), $this->census());
        $this->assertSame([], ProductIntegrity::differences($this->census(), $this->census()));
    }

    public function test_a_census_on_a_host_without_a_table_or_a_column_leaves_it_out_and_says_so(): void
    {
        $this->lineWorld();
        $full = $this->census();
        Schema::drop('crm_assets');
        Schema::table('crm_invoice_line_items', fn ($t) => $t->dropColumn('line_total'));

        $census = $this->census();

        $this->assertArrayNotHasKey('crm_assets', $census['tables']);
        $this->assertSame(['quantity' => '3.00'], $census['tables']['crm_invoice_line_items']['sums'], 'the sum of a column the host lacks is left out');
        $this->assertContains('crm_assets: the table is not on this host', $census['skipped']);
        $this->assertContains('crm_invoice_line_items.line_total: the column is not on this host, so its sum was skipped', $census['skipped']);
        $differences = ProductIntegrity::differences($full, $census);
        $this->assertContains('crm_assets: in the snapshot, not on this host now', $differences);
        $this->assertContains('crm_invoice_line_items: sum of line_total 320.00 -> absent', $differences);
    }

    public function test_differences_names_every_kind_of_change(): void
    {
        $w = $this->lineWorld();
        $before = $this->census();

        DB::table('crm_quote_line_items')->where('id', DB::table('crm_quote_line_items')->orderBy('id')->value('id'))->delete();
        $line = DB::table('crm_sales_order_line_items')->first();
        DB::table('crm_sales_order_line_items')->where('id', $line->id)->update(['quantity' => 5, 'product_id' => $w['crmC']]);
        $this->line('crm_invoice_line_items', self::T, $w['crmA'], 1, 1);
        DB::table('crm_opportunities')->where('amount', 250)->update(['amount' => 300]);
        $after = $this->census();

        $differences = ProductIntegrity::differences($before, $after);

        $this->assertContains('crm_quote_line_items: rows 5 -> 4', $differences);
        $this->assertContains('crm_quote_line_items.product_id: rows with a product 4 -> 3', $differences, 'the deleted line had a product, so this count moves too');
        $this->assertContains('crm_quote_line_items: sum of quantity 12.00 -> 10.00', $differences);
        $this->assertContains('crm_quote_line_items: sum of line_total 960.75 -> 860.25', $differences);
        $this->assertContains("crm_quote_line_items.product_id: rows for product {$w['crmA']} 2 -> 1", $differences);
        $this->assertContains('crm_sales_order_line_items: sum of quantity 4.00 -> 5.00', $differences);
        $this->assertContains("crm_sales_order_line_items.product_id: rows for product {$w['crmA']} 1 -> 0", $differences);
        $this->assertContains("crm_sales_order_line_items.product_id: rows for product {$w['crmC']} 0 -> 1", $differences);
        $this->assertContains('crm_invoice_line_items: rows 2 -> 3', $differences);
        $this->assertContains('crm_opportunities: sum of amount 1250.50 -> 1300.50', $differences);
        $this->assertSame([], array_filter($differences, fn (string $d): bool => str_starts_with($d, 'crm_tickets') || str_starts_with($d, 'crm_leads')), 'nothing else moved');
    }

    public function test_differences_names_a_table_or_a_pointer_that_is_on_one_side_only(): void
    {
        $base = ['format' => ProductIntegrity::FORMAT, 'tenant' => null, 'skipped' => [], 'tables' => ['t1' => ['rows' => 1, 'sums' => [], 'pointers' => ['a' => ['set' => 1], 'b' => ['set' => 0]]]]];
        $other = $base;
        unset($other['tables']['t1']['pointers']['b']);
        $other['tables']['t2'] = ['rows' => 0, 'sums' => [], 'pointers' => []];

        $this->assertSame(['t1.b: in the snapshot, not on this host now', 't2: not in the snapshot, on this host now'], ProductIntegrity::differences($base, $other));
        $this->assertSame(['t1.b: not in the snapshot, on this host now', 't2: in the snapshot, not on this host now'], ProductIntegrity::differences($other, $base));
    }

    public function test_differences_refuses_a_file_that_is_not_a_census_or_covers_another_organisation(): void
    {
        $census = $this->census();

        $this->assertStringContainsString('not a census written by this command', ProductIntegrity::differences(['format' => 'something else'], $census)[0]);
        $other = $census;
        $other['tenant'] = 6;
        $this->assertSame(['the two censuses cover different organisations (organisation 6 and every organisation)'], ProductIntegrity::differences($other, $census));
    }

    // ------------------------------------------------------------------ --snapshot and --compare

    public function test_snapshot_writes_the_census_and_never_overwrites_a_file(): void
    {
        $w = $this->lineWorld();
        $path = $this->tempFile();

        [$exit, $output] = $this->runCommand(['--snapshot' => $path]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString("Census written to {$path}: 20 tables, 8 rows in the line tables.", $output);
        $file = json_decode((string) file_get_contents($path), true);
        $this->assertSame(ProductIntegrity::FORMAT, $file['format']);
        $this->assertSame('sqlite', $file['meta']['connection']);
        $this->assertSame('product-master:check --snapshot', $file['meta']['command']);
        $this->assertSame(0, $file['meta']['checks']['errors']);
        $this->assertArrayHasKey('taken_at', $file['meta']);
        $this->assertSame(5, $file['tables']['crm_quote_line_items']['rows']);
        $this->assertSame([$w['crmA'] => 2, $w['crmC'] => 1, $w['crmD'] => 1], $file['tables']['crm_quote_line_items']['pointers']['product_id']['by_product']);

        $bytes = file_get_contents($path);
        [$again, $message] = $this->runCommand(['--snapshot' => $path]);
        $this->assertSame(2, $again, 'a baseline is never overwritten by accident');
        $this->assertStringContainsString('Refusing to overwrite', $message);
        $this->assertSame($bytes, file_get_contents($path), 'and the file is untouched');

        [$exit, $message] = $this->runCommand(['--snapshot' => sys_get_temp_dir() . '/no-such-folder-' . bin2hex(random_bytes(3)) . '/x.json']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('does not exist or cannot be written', $message);
    }

    public function test_a_snapshot_compared_at_once_shows_no_difference(): void
    {
        $this->lineWorld();
        $path = $this->tempFile();
        $this->runCommand(['--snapshot' => $path]);

        [$exit, $output] = $this->runCommand(['--compare' => $path]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('no difference in any row count, sum or rows-per-product', $output);
    }

    public function test_compare_after_one_deleted_line_shows_exactly_that_difference(): void
    {
        $w = $this->lineWorld();
        $path = $this->tempFile();
        $this->runCommand(['--snapshot' => $path]);
        $line = DB::table('crm_quote_line_items')->where('quantity', 3)->first();
        DB::table('crm_quote_line_items')->where('id', $line->id)->delete();

        [$exit, $output] = $this->runCommand(['--compare' => $path]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('5 difference(s)', $output, 'exactly the five things one deleted line changes, and nothing else');
        $this->assertStringContainsString('crm_quote_line_items: rows 5 -> 4', $output);
        $this->assertStringContainsString('crm_quote_line_items: sum of quantity 12.00 -> 9.00', $output);
        $this->assertStringContainsString('crm_quote_line_items: sum of line_total 960.75 -> 660.75', $output);
        $this->assertStringContainsString('crm_quote_line_items.product_id: rows with a product 4 -> 3', $output);
        $this->assertStringContainsString("crm_quote_line_items.product_id: rows for product {$w['crmC']} 1 -> 0", $output);
        $this->assertSame(5, substr_count($output, "\n  crm_"), 'five lines, none from any other table');
        $this->assertStringContainsString('RESULT: FAILED', $output);
    }

    public function test_compare_sees_a_changed_total_or_quantity_even_when_every_row_is_still_there(): void
    {
        $this->lineWorld();
        $path = $this->tempFile();
        $this->runCommand(['--snapshot' => $path]);
        DB::table('crm_invoice_line_items')->orderBy('id')->limit(1)->update(['line_total' => 999.99, 'quantity' => 7]);

        [$exit, $output] = $this->runCommand(['--compare' => $path]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('crm_invoice_line_items: sum of quantity 3.00 -> 8.00', $output);
        $this->assertStringContainsString('crm_invoice_line_items: sum of line_total 320.00 -> 1099.89', $output);
        $this->assertStringContainsString('2 difference(s)', $output, 'no row count and no rows-per-product moved: only the two sums');
    }

    public function test_compare_sees_a_line_that_now_points_at_another_product(): void
    {
        $w = $this->lineWorld();
        $path = $this->tempFile();
        $this->runCommand(['--snapshot' => $path]);
        DB::table('crm_quote_line_items')->where('quantity', 3)->update(['product_id' => $w['crmA']]);

        [$exit, $output] = $this->runCommand(['--compare' => $path]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("crm_quote_line_items.product_id: rows for product {$w['crmA']} 2 -> 3", $output);
        $this->assertStringContainsString("crm_quote_line_items.product_id: rows for product {$w['crmC']} 1 -> 0", $output);
        $this->assertStringContainsString('2 difference(s)', $output, 'counts and sums are the same; only the product moved');
    }

    public function test_compare_and_snapshot_together_report_against_the_old_file_and_write_the_new_one(): void
    {
        $this->lineWorld();
        $old = $this->tempFile();
        $new = $this->tempFile();
        $this->runCommand(['--snapshot' => $old]);
        DB::table('crm_quote_line_items')->where('quantity', 3)->delete();

        [$exit, $output] = $this->runCommand(['--compare' => $old, '--snapshot' => $new]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('5 difference(s)', $output);
        $this->assertStringContainsString("Census written to {$new}", $output);
        $this->assertSame(4, json_decode((string) file_get_contents($new), true)['tables']['crm_quote_line_items']['rows'], 'the new file holds the database as it is now');
        $this->assertSame(5, json_decode((string) file_get_contents($old), true)['tables']['crm_quote_line_items']['rows'], 'and the old file is untouched');
        [$again] = $this->runCommand(['--compare' => $new]);
        $this->assertSame(0, $again, 'the new file is the new baseline');
    }

    public function test_compare_adopts_the_organisation_of_the_file_and_refuses_a_different_one(): void
    {
        $this->lineWorld();
        $path = $this->tempFile();
        $this->runCommand(['--snapshot' => $path, '--tenant' => 1]);
        $this->assertSame(1, json_decode((string) file_get_contents($path), true)['tenant']);
        DB::table('crm_quote_line_items')->where('quantity', 5)->delete();

        [$exit, $output] = $this->runCommand(['--compare' => $path]);
        $this->assertSame(0, $exit, 'organisation 2\'s line is outside the file\'s scope: ' . $output);
        $this->assertStringContainsString('The census file was taken for organisation 1', $output);

        [$exit, $output] = $this->runCommand(['--compare' => $path, '--tenant' => 2]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('covers organisation 1 but --tenant asks for organisation 2', $output);

        [$exit, $output] = $this->runCommand(['--compare' => $path, '--tenant' => 1]);
        $this->assertSame(0, $exit, $output);
    }

    public function test_compare_refuses_a_missing_or_foreign_file(): void
    {
        [$exit, $output] = $this->runCommand(['--compare' => sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(3)) . '.json']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('does not exist or cannot be read', $output);

        $bad = $this->tempFile();
        file_put_contents($bad, '{not json');
        [$exit, $output] = $this->runCommand(['--compare' => $bad]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('is not valid JSON', $output);

        file_put_contents($bad, json_encode(['format' => 'other', 'tables' => []]));
        [$exit, $output] = $this->runCommand(['--compare' => $bad]);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('not a census written by this command', $output);
    }

    // ------------------------------------------------------------------ read only

    public function test_the_command_changes_nothing_and_sends_only_select_statements(): void
    {
        $this->lineWorld();
        $this->pointRow($this->entry('crm_tickets', 'product_id'), self::T, 999999);
        $this->crm(self::T, 'Lonely item');
        $snapshot = $this->tempFile();
        $before = $this->dumpEverything();
        $this->assertNotEmpty($before['g2g_product_master']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        [$exit] = $this->runCommand(['--snapshot' => $snapshot]);
        [$compared] = $this->runCommand(['--compare' => $snapshot]);
        $this->runCommand(['--tenant' => 2]);
        $this->assertSame(1, $compared, 'the compare path ran to the end (the errors of the world still fail it)');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, $exit, 'the world has errors, and the command still only reads');
        $this->assertSame($before, $this->dumpEverything(), 'every row of every table, updated_at included, is as it was');
        $this->assertGreaterThan(100, count($log), 'the log is not empty, so "all selects" means something');
        $notReads = array_values(array_filter(array_map(fn (array $q): string => trim($q['query']), $log), fn (string $sql): bool => ! preg_match('/^(select|pragma|show)\b/i', $sql)));
        $this->assertSame([], $notReads, 'every statement the command sent was a SELECT (or a read-only PRAGMA)');
    }

    public function test_the_session_is_made_read_only_on_a_server_that_can_and_left_alone_on_sqlite(): void
    {
        $this->assertSame('SET SESSION TRANSACTION READ ONLY', ProductIntegrity::READ_ONLY_SQL);
        $this->assertSame('SELECT @@SESSION.tx_read_only AS ro', ProductIntegrity::READ_ONLY_CHECK_SQL);
        $this->assertSame('not needed on sqlite', (new ProductIntegrity)->lockSessionReadOnly());
    }

    // ------------------------------------------------------------------ which host it may read

    public function test_without_a_database_option_only_the_dev_host_may_be_read(): void
    {
        $connections = [
            'mysql' => ['driver' => 'mysql', 'host' => '202.47.117.220'],
            'live' => ['driver' => 'mysql', 'host' => '128.199.17.97'],
            'local' => ['driver' => 'mysql', 'host' => '127.0.0.1'],
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ];

        $this->assertNull(ProductMasterCheckCommand::refusal(null, 'mysql', $connections), 'the dev host is the default');
        $this->assertNull(ProductMasterCheckCommand::refusal(null, 'sqlite', $connections), 'the test database has no host');
        $this->assertStringContainsString("Refusing to read host '128.199.17.97'", (string) ProductMasterCheckCommand::refusal(null, 'live', $connections), 'a machine whose default connection is production is refused');
        $this->assertStringContainsString("Refusing to read host '127.0.0.1'", (string) ProductMasterCheckCommand::refusal(null, 'local', $connections));
        $this->assertNull(ProductMasterCheckCommand::refusal('live', 'mysql', $connections), 'naming the connection is the deliberate act');
        $this->assertNull(ProductMasterCheckCommand::refusal('local', 'sqlite', $connections));
        $this->assertSame("There is no database connection named 'nope'.", ProductMasterCheckCommand::refusal('nope', 'mysql', $connections));
        $this->assertSame("There is no database connection named 'gone'.", ProductMasterCheckCommand::refusal(null, 'gone', $connections));
    }

    public function test_the_command_refuses_a_default_connection_on_another_host_before_it_touches_the_database(): void
    {
        // 127.0.0.1 on a closed port: if the guard were missing the command would fail at once instead of waiting for a remote host.
        config(['database.default' => 'faraway', 'database.connections.faraway' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '9', 'database' => 'x', 'username' => 'u-secret-name', 'password' => 'S3cretPassw0rd']]);

        [$exit, $output] = $this->runCommand();

        $this->assertSame(2, $exit);
        $this->assertStringContainsString("Refusing to read host '127.0.0.1'", $output);
        $this->assertStringNotContainsString('RESULT', $output, 'it stopped before reading anything');
        $this->assertStringNotContainsString('S3cretPassw0rd', $output, 'no credential is printed');
        $this->assertStringNotContainsString('u-secret-name', $output);
    }

    public function test_an_explicitly_named_connection_is_read_and_its_host_is_printed(): void
    {
        $this->cleanWorld();

        [$exit, $output] = $this->runCommand(['--database' => 'sqlite']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('connection sqlite (sqlite)', $output);

        [$exit, $output] = $this->runCommand(['--database' => 'nope']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString("There is no database connection named 'nope'.", $output);
    }

    // ------------------------------------------------------------------ the registry drives the fixture

    public function test_the_fixture_has_a_table_and_a_pointer_column_for_every_registry_entry(): void
    {
        foreach (ProductPointers::all() as $e) {
            $this->assertTrue(Schema::hasColumn($e['table'], $e['column']), "{$e['table']}.{$e['column']}");
        }
        $this->assertCount(22, iterator_to_array(self::everyPointer()), 'one case per pointer in the provider of the I1 test');
    }
}
