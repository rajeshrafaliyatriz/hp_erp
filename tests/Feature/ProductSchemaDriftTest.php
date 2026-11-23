<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\MigrationColumns;
use App\Domain\ProductMaster\ProductIntegrity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * W3, the schema-drift warning of ProductIntegrity / `php artisan product-master:check` (S15 WPA2-H3W3, 23 Nov 2026), on in-memory sqlite.
 *
 * The fixture host has the four product tables with EXACTLY the columns their migrations declare (read through MigrationColumns, the reader the scan tests use).
 * Then one column is taken away, or one is added, and the warning must name the table and the column; the command prints it, counts it as a warning and still
 * exits 0. Nothing here touches any database but the in-memory sqlite of the test.
 */
class ProductSchemaDriftTest extends TestCase
{
    private const TABLES = ['crm_products', 'g2g_product_offers', 'g2g_product_master', 'g2g_signal_products'];

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLES as $table) {
            $columns = array_keys(MigrationColumns::columns(MigrationColumns::directory(), $table));
            $this->assertNotSame([], $columns, "the fixture needs the columns of {$table} from its migrations");
            Schema::create($table, function ($t) use ($columns): void {
                foreach ($columns as $column) {
                    $column === 'id' ? $t->id() : $t->text($column)->nullable();
                }
            });
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} exit code and output */
    private function runCommand(): array
    {
        $exit = Artisan::call('product-master:check');

        return [$exit, Artisan::output()];
    }

    /** @return list<string> the messages of the findings */
    private function messages(array $drift): array
    {
        return array_map(fn (array $f): string => $f['message'], $drift['findings']);
    }

    // ------------------------------------------------------------------ a host that matches its migrations

    public function test_a_host_whose_columns_are_exactly_the_migrations_columns_has_no_drift(): void
    {
        $drift = (new ProductIntegrity())->schemaDrift();

        $this->assertSame(0, $drift['count'], json_encode($drift['findings']));
        $this->assertSame([], $drift['findings']);
        $this->assertSame([], $drift['skipped']);
        $this->assertSame(4, $drift['checked'], 'all four tables were compared');

        $report = (new ProductIntegrity())->checkWithSchema();
        $this->assertSame(0, $report['counts']['W3']);
        $this->assertSame(0, $report['warnings']);
        $this->assertSame(0, $report['errors']);
        $this->assertSame(array_keys(ProductIntegrity::CODES), array_keys($report['counts']));
        $this->assertSame(['crm_products', 'g2g_product_offers', 'g2g_product_master', 'g2g_signal_products'], ProductIntegrity::SCHEMA_TABLES);
    }

    public function test_the_command_lists_w3_with_a_zero_and_exits_zero_on_a_matching_host(): void
    {
        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/^\s+W3\s+warning\s+0\s+\S/m', $output, 'W3 is listed with its count');
        $this->assertStringContainsString('RESULT: OK (0 errors, 0 warning(s))', $output);
    }

    // ------------------------------------------------------------------ a column the host lacks

    public function test_a_migration_column_the_host_lacks_is_named_with_its_table_column_and_migration(): void
    {
        Schema::table('g2g_product_offers', fn ($t) => $t->dropColumn('icp_config'));

        $drift = (new ProductIntegrity())->schemaDrift();

        $this->assertSame(1, $drift['count']);
        $this->assertCount(1, $drift['findings']);
        $finding = $drift['findings'][0];
        $this->assertSame('W3', $finding['code']);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('g2g_product_offers', $finding['table']);
        $this->assertSame('icp_config', $finding['column']);
        $this->assertSame('migration_only', $finding['kind']);
        $this->assertStringContainsString('g2g_product_offers.icp_config', $finding['message']);
        $this->assertStringContainsString('2026_10_27_200000_add_icp_config_to_g2g_product_offers_table.php', $finding['message'], 'it names the migration that defines the column');
        $this->assertStringContainsString('this host lacks it', $finding['message']);
    }

    public function test_the_command_prints_the_missing_column_counts_a_warning_and_still_exits_zero(): void
    {
        Schema::table('g2g_product_offers', fn ($t) => $t->dropColumn('icp_config'));

        [$exit, $output] = $this->runCommand();

        $this->assertSame(0, $exit, 'a warning never changes the exit code: ' . $output);
        $this->assertMatchesRegularExpression('/^\s+W3\s+warning\s+1\s+\S/m', $output);
        $this->assertStringContainsString('[W3] g2g_product_offers.icp_config', $output);
        $this->assertStringContainsString('RESULT: OK (0 errors, 1 warning(s))', $output);
    }

    // ------------------------------------------------------------------ a column no migration defines

    public function test_a_host_column_that_no_migration_defines_is_named_with_its_table_and_column(): void
    {
        Schema::table('crm_products', fn ($t) => $t->text('loyalty_points')->nullable());

        $drift = (new ProductIntegrity())->schemaDrift();

        $this->assertSame(1, $drift['count']);
        $finding = $drift['findings'][0];
        $this->assertSame('crm_products', $finding['table']);
        $this->assertSame('loyalty_points', $finding['column']);
        $this->assertSame('host_only', $finding['kind']);
        $this->assertStringContainsString('crm_products.loyalty_points', $finding['message']);
        $this->assertStringContainsString('no migration defines it', $finding['message']);

        [$exit, $output] = $this->runCommand();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('[W3] crm_products.loyalty_points', $output);
        $this->assertStringContainsString('RESULT: OK (0 errors, 1 warning(s))', $output);
    }

    public function test_both_directions_across_several_tables_are_all_reported_and_counted(): void
    {
        Schema::table('g2g_product_master', fn ($t) => $t->dropColumn('origin'));
        Schema::table('g2g_signal_products', fn ($t) => $t->text('extra_one')->nullable());
        Schema::table('g2g_product_offers', fn ($t) => $t->text('extra_two')->nullable());

        $drift = (new ProductIntegrity())->schemaDrift();
        $messages = implode("\n", $this->messages($drift));

        $this->assertSame(3, $drift['count']);
        $this->assertStringContainsString('g2g_product_master.origin', $messages);
        $this->assertStringContainsString('g2g_signal_products.extra_one', $messages);
        $this->assertStringContainsString('g2g_product_offers.extra_two', $messages);

        $report = (new ProductIntegrity())->checkWithSchema();
        $this->assertSame(3, $report['counts']['W3']);
        $this->assertSame(3, $report['warnings']);
        $this->assertSame(0, $report['errors'], 'W3 is a warning, never an error');
    }

    public function test_names_are_compared_without_regard_to_case(): void
    {
        Schema::table('g2g_signal_products', fn ($t) => $t->renameColumn('label', 'LABEL'));

        $this->assertSame(0, (new ProductIntegrity())->schemaDrift()['count'], 'a host that spells a column in capitals is not drift');
    }

    // ------------------------------------------------------------------ what is skipped, what is capped, what is read

    public function test_a_table_the_host_does_not_have_is_skipped_and_listed_never_a_finding(): void
    {
        Schema::drop('g2g_signal_products');

        $drift = (new ProductIntegrity())->schemaDrift();

        $this->assertSame(0, $drift['count']);
        $this->assertSame(3, $drift['checked']);
        $this->assertSame(['g2g_signal_products: the table is not on this host, so W3 did not compare its columns'], $drift['skipped']);

        [$exit, $output] = $this->runCommand();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('g2g_signal_products: the table is not on this host, so W3 did not compare its columns', $output);
    }

    public function test_a_folder_that_declares_nothing_for_a_table_is_skipped_so_a_reading_failure_is_not_a_flood_of_warnings(): void
    {
        $dir = sys_get_temp_dir() . '/drift-empty-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;

        $drift = (new ProductIntegrity())->schemaDrift($dir);

        $this->assertSame(0, $drift['count']);
        $this->assertSame(0, $drift['checked']);
        $this->assertCount(4, $drift['skipped']);
        $this->assertStringContainsString('no migration in', $drift['skipped'][0]);
    }

    public function test_a_sample_migration_folder_decides_what_is_declared(): void
    {
        $dir = sys_get_temp_dir() . '/drift-sample-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->dirs[] = $dir;
        file_put_contents($dir . '/2027_01_01_000000_create_g2g_signal_products.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('g2g_signal_products', function (\$table) {\n            \$table->id();\n            \$table->string('key', 20);\n            \$table->string('brand_new_column', 20);\n        });\n    }\n};\n");

        $drift = (new ProductIntegrity())->schemaDrift($dir);
        $messages = implode("\n", $this->messages($drift));

        $this->assertSame(1, $drift['checked'], 'only the table this folder works on was compared; the other three are listed as skipped');
        $this->assertStringContainsString('g2g_signal_products.brand_new_column: a migration (2027_01_01_000000_create_g2g_signal_products.php) defines it but this host lacks it', $messages);
        $this->assertStringContainsString('g2g_signal_products.label: this host has it but no migration defines it', $messages);
    }

    public function test_the_findings_are_capped_but_the_count_is_exact(): void
    {
        Schema::table('crm_products', function ($t): void {
            for ($i = 1; $i <= ProductIntegrity::SAMPLE + 7; $i++) {
                $t->text('stray_' . $i)->nullable();
            }
        });

        $drift = (new ProductIntegrity())->schemaDrift();

        $this->assertSame(ProductIntegrity::SAMPLE + 7, $drift['count']);
        $this->assertCount(ProductIntegrity::SAMPLE, $drift['findings']);
    }

    public function test_check_itself_never_runs_w3_so_the_other_callers_and_their_minimal_tables_are_untouched(): void
    {
        Schema::table('g2g_product_offers', fn ($t) => $t->dropColumn('icp_config'));

        $report = (new ProductIntegrity())->check();

        $this->assertSame(0, $report['counts']['W3']);
        $this->assertSame(0, $report['warnings']);
    }

    public function test_w3_only_reads(): void
    {
        Schema::table('g2g_product_offers', fn ($t) => $t->dropColumn('icp_config'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new ProductIntegrity())->checkWithSchema();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertGreaterThan(4, count($log), 'the log is not empty, so "all reads" means something');
        $notReads = array_values(array_filter(array_map(fn (array $q): string => trim($q['query']), $log), fn (string $sql): bool => ! preg_match('/^(select|pragma|show)\b/i', $sql)));
        $this->assertSame([], $notReads, 'every statement was a SELECT, a PRAGMA or a SHOW');
    }
}
