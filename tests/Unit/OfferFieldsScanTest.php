<?php

namespace Tests\Unit;

use App\Domain\ProductMaster\MigrationColumns;
use App\Domain\ProductMaster\OfferFields;
use PHPUnit\Framework\TestCase;

/**
 * The permanent guard behind "no About field is left behind" (S15 WPA2-H3W3, guard H3; the twin of tests/Unit/CatalogueFieldsScanTest.php for `crm_products`).
 *
 * The one Sub-product form writes the About section onto `g2g_product_offers` through App\Domain\ProductMaster\OfferFields. This test reads every migration that
 * works on that table (through App\Domain\ProductMaster\MigrationColumns, the same reader the W3 warning of `product-master:check` uses) and fails, by file and
 * column, when a column of the table is neither
 *   - a field of OfferFields::ABOUT_MAP or OfferFields::HEADER, so the form writes it, nor
 *   - one of OfferFields::systemOfferColumns(), the short list of columns the system or another screen fills, each with a written reason of 30 characters or more.
 * It also fails when ProductWriter keeps a private copy of the map. Plain PHPUnit, no database: it only reads files.
 */
class OfferFieldsScanTest extends TestCase
{
    private const TABLE = 'g2g_product_offers';

    /**
     * The 29 columns the migrations give the table, written out by hand (the create migration 2026_09_30_220000 has 26, the readiness migration 2026_10_08_120000
     * adds readiness_confirmed_by and readiness_confirmed_at, the ICP migration 2026_10_27_200000 adds icp_config): the independent anchor, so the scan cannot pass
     * by finding nothing, and a column that a migration loses fails here and not silently.
     */
    private const KNOWN = [
        'id', 'sub_institute_id', 'offer_id', 'name', 'parent_product', 'modules_components', 'what_it_does', 'needs_solved', 'primary_segments', 'trigger_signals',
        'deployment_model', 'readiness_status', 'readiness_confirmed', 'typical_deal_band', 'pricing_model', 'implementation_effort', 'delivery_owner', 'bundles_with',
        'proof_points', 'partner_sellable', 'notes', 'is_user_edited', 'created_by', 'updated_by', 'created_at', 'updated_at',
        'readiness_confirmed_by', 'readiness_confirmed_at', 'icp_config',
    ];

    /** The same anchor for the other three tables the W3 warning reads (read in source from their migrations on 23 Nov 2026). */
    private const KNOWN_OTHERS = [
        'g2g_product_master' => [
            'id', 'sub_institute_id', 'product_key', 'kind', 'parent_id', 'name', 'status', 'origin', 'signal_key', 'portfolio_name', 'offer_row_id', 'crm_product_id',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ],
        'g2g_signal_products' => [
            'id', 'sub_institute_id', 'key', 'label', 'business_fit', 'icp_product_line', 'portfolio_names', 'status', 'created_by', 'created_at', 'updated_at',
        ],
        'crm_products' => [
            'id', 'legacy_id', 'item_type', 'product_no', 'name', 'sku', 'category', 'gl_account', 'description', 'unit_price', 'cost_price', 'commission_rate', 'currency',
            'tax_rate_id', 'is_active', 'vendor', 'qty_in_stock', 'qty_in_demand', 'reorder_level', 'weight', 'image', 'usage_unit', 'assigned_to', 'created_by', 'updated_by',
            'deleted_by', 'sub_institute_id', 'created_at', 'updated_at', 'deleted_at', 'product_master_id',
        ],
    ];

    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /** @param list<string> $mapped @param array<string, string> $system @return list<string> "file: column" of every column a migration defines that is in neither */
    private function unmapped(string $dir, array $mapped, array $system): array
    {
        $known = array_flip(array_merge($mapped, array_keys($system)));
        $missing = [];
        foreach (MigrationColumns::columns($dir, self::TABLE) as $column => $files) {
            if (! isset($known[$column])) {
                foreach ($files as $file) {
                    $missing[] = "{$file}: {$column}";
                }
            }
        }
        sort($missing);

        return $missing;
    }

    /** A folder holding the given migration texts. @param array<string, string> $files file name => PHP text */
    private function sampleDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/offer-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($files as $name => $text) {
            file_put_contents($dir . '/' . $name, $text);
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    // ------------------------------------------------------------------ the guard

    public function test_every_column_of_g2g_product_offers_is_in_the_field_map_or_explained(): void
    {
        $missing = $this->unmapped(self::migrations(), OfferFields::columns(), OfferFields::systemOfferColumns());

        $this->assertSame([], $missing, "A column of g2g_product_offers is neither in App\\Domain\\ProductMaster\\OfferFields::ABOUT_MAP / HEADER (so the one Sub-product form "
            . "writes it) nor in OfferFields::systemOfferColumns() with a written reason. Map it, or explain why no field of this door touches it:\n  " . implode("\n  ", $missing));
    }

    public function test_the_scan_really_reads_the_migrations_so_it_cannot_pass_by_looking_at_nothing(): void
    {
        $found = array_keys(MigrationColumns::columns(self::migrations(), self::TABLE));
        sort($found);
        $known = self::KNOWN;
        sort($known);

        $this->assertSame($known, $found, 'the migrations define exactly the 29 columns of g2g_product_offers: a column found here and not on the list is new (map it or explain it), a column on the list and not found is a hole in the scan');
    }

    public function test_the_shared_reader_finds_the_columns_of_the_other_three_tables_the_host_check_reads(): void
    {
        foreach (self::KNOWN_OTHERS as $table => $known) {
            $found = array_keys(MigrationColumns::columns(self::migrations(), $table));
            sort($found);
            sort($known);
            $this->assertSame($known, $found, "the migrations define exactly these columns of {$table}");
        }
    }

    public function test_a_migration_that_works_on_two_tables_gives_each_table_only_its_own_columns(): void
    {
        // 2026_10_08_120000 adds two columns to g2g_product_offers and also creates g2g_offer_readiness_log (offer_row_id, action, actor_id, note ...).
        $offers = array_keys(MigrationColumns::columns(self::migrations(), self::TABLE));
        $log = array_keys(MigrationColumns::columns(self::migrations(), 'g2g_offer_readiness_log'));

        foreach (['offer_row_id', 'action', 'actor_id', 'note'] as $column) {
            $this->assertNotContains($column, $offers, "'{$column}' is a column of g2g_offer_readiness_log, not of g2g_product_offers");
            $this->assertContains($column, $log);
        }
        $this->assertContains('readiness_confirmed_by', $offers);
        $this->assertNotContains('readiness_confirmed_by', $log);
    }

    public function test_the_map_and_the_allow_list_have_no_stale_contradictory_or_unexplained_entries(): void
    {
        $defined = array_keys(MigrationColumns::columns(self::migrations(), self::TABLE));
        $map = OfferFields::columns();
        $system = OfferFields::systemOfferColumns();

        foreach ($map as $column) {
            $this->assertContains($column, $defined, "the field map writes '{$column}' but no migration defines it on g2g_product_offers: a typo, or a column that was renamed");
        }
        foreach ($system as $column => $reason) {
            $this->assertContains($column, $defined, "systemOfferColumns() lists '{$column}' but no migration defines it on g2g_product_offers: remove the entry");
            $this->assertNotContains($column, $map, "'{$column}' is both mapped and allow-listed: it is one or the other");
            $this->assertGreaterThanOrEqual(30, strlen(trim($reason)), "'{$column}' needs a written reason of 30 characters or more, one a reviewer can disagree with");
        }
        $this->assertSame(array_values(array_unique($map)), $map, 'a column is mapped twice');
        $this->assertSame(13, count(OfferFields::ABOUT_MAP), 'the About section has 13 fields');
        $this->assertSame(16, count($map), 'the offer has 16 fields the form writes (13 About fields, its name, its group and its readiness)');
        foreach (OfferFields::ABOUT_LISTS as $column) {
            $this->assertContains($column, OfferFields::aboutColumns(), "'{$column}' is a list column but is not an About column");
        }
    }

    public function test_the_writer_takes_the_map_from_offerfields_and_keeps_no_private_copy(): void
    {
        $root = dirname(__DIR__, 2);
        $code = MigrationColumns::code($root . '/app/Domain/ProductMaster/ProductWriter.php');

        // assertTrue with a message: a failing assertStringContainsString would print the whole source file.
        $this->assertTrue(str_contains($code, 'OfferFields::ABOUT_MAP'), 'ProductWriter must read the About map from OfferFields::ABOUT_MAP');
        $this->assertSame(0, preg_match('/\bconst\s+ABOUT_(?:MAP|LISTS)\b/', $code), 'ProductWriter keeps a private ABOUT_MAP / ABOUT_LISTS constant: use the ones of OfferFields');
        $this->assertFalse(str_contains($code, "'whatItDoes' => 'what_it_does'"), 'ProductWriter keeps a private copy of the About map: use OfferFields::ABOUT_MAP');
        $this->assertFalse(str_contains($code, "'modulesComponents' => 'modules_components'"), 'ProductWriter keeps a private copy of the About map: use OfferFields::ABOUT_MAP');

        // No other file of the application holds a second copy either.
        $copies = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), "'oftenSoldTogether' => 'bundles_with'")) {
                $copies[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        $this->assertSame(['app/Domain/ProductMaster/OfferFields.php'], $copies, 'the About map lives in exactly one file');
    }

    // ------------------------------------------------------------------ the guard can fail

    public function test_removing_any_field_from_the_map_makes_the_scan_fail_and_names_the_column(): void
    {
        $this->assertSame([], $this->unmapped(self::migrations(), OfferFields::columns(), OfferFields::systemOfferColumns()), 'the baseline is clean, so a finding below is the removal');

        foreach (OfferFields::columns() as $column) {
            $without = array_values(array_diff(OfferFields::columns(), [$column]));
            $missing = $this->unmapped(self::migrations(), $without, OfferFields::systemOfferColumns());

            $this->assertNotSame([], $missing, "removing '{$column}' from the map must be reported");
            $this->assertTrue(
                count(array_filter($missing, fn (string $line): bool => str_ends_with($line, ": {$column}"))) >= 1,
                "removing '{$column}' from the map must be reported by file and column; got " . implode('; ', $missing),
            );
        }
    }

    public function test_removing_a_system_column_from_the_allow_list_makes_the_scan_fail(): void
    {
        foreach (array_keys(OfferFields::systemOfferColumns()) as $column) {
            $system = OfferFields::systemOfferColumns();
            unset($system[$column]);
            $missing = $this->unmapped(self::migrations(), OfferFields::columns(), $system);

            $this->assertNotSame([], array_filter($missing, fn (string $line): bool => str_ends_with($line, ": {$column}")), "removing '{$column}' from the allow-list must be reported");
        }
    }

    public function test_a_column_added_to_a_sample_migration_makes_the_scan_fail(): void
    {
        $dir = $this->sampleDir([
            '2027_01_01_000000_add_warranty_to_g2g_product_offers.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('g2g_product_offers', function (\$table) {\n            \$table->unsignedSmallInteger('warranty_months')->nullable();\n        });\n    }\n};\n",
            '2027_01_02_000000_raw_add_to_g2g_product_offers.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        DB::statement('ALTER TABLE `g2g_product_offers` ADD COLUMN `barcode` VARCHAR(40) NULL');\n    }\n};\n",
            '2027_01_03_000000_const_column.php' => "<?php\nreturn new class {\n    private const TABLE = 'g2g_product_offers';\n\n    private const COLUMN = 'hsn_code';\n\n    public function up(): void\n    {\n        DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . self::COLUMN . '` VARCHAR(20) NULL');\n    }\n};\n",
            '2027_01_04_000000_another_table.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('g2g_partners', function (\$table) {\n            \$table->string('warranty_text')->nullable();\n        });\n    }\n};\n",
            '2027_01_05_000000_comment_only.php' => "<?php\n// Schema::table('g2g_product_offers') once had \$table->string('legacy_note');\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('g2g_product_offers', function (\$table) {\n            // \$table->string('only_in_a_comment');\n            \$table->index('offer_id');\n        });\n    }\n};\n",
            // One file, two tables: the columns of the second are not the first one's (the over-attribution the old reader of the catalogue scan allowed).
            '2027_01_06_000000_two_tables.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('g2g_product_offers', function (\$table) {\n            \$table->string('region_hint', 20)->nullable();\n        });\n        Schema::create('g2g_offer_log', function (\$table) {\n            \$table->id();\n            \$table->string('log_only_column')->nullable();\n        });\n    }\n};\n",
        ]);

        try {
            $missing = $this->unmapped($dir, OfferFields::columns(), OfferFields::systemOfferColumns());
        } finally {
            $this->removeDir($dir);
        }

        $this->assertSame([
            '2027_01_01_000000_add_warranty_to_g2g_product_offers.php: warranty_months',
            '2027_01_02_000000_raw_add_to_g2g_product_offers.php: barcode',
            '2027_01_03_000000_const_column.php: hsn_code',
            '2027_01_06_000000_two_tables.php: region_hint',
        ], $missing, 'a Blueprint column, a raw ADD COLUMN and a constant-named column are found; another table\'s column (also one in the same file), a comment and an index are not');
    }

    public function test_an_allow_list_entry_or_a_map_entry_silences_exactly_its_own_column(): void
    {
        $dir = $this->sampleDir([
            '2027_02_01_000000_add_warranty_to_g2g_product_offers.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('g2g_product_offers', function (\$table) {\n            \$table->unsignedSmallInteger('warranty_months')->nullable();\n        });\n    }\n};\n",
        ]);

        try {
            $none = $this->unmapped($dir, OfferFields::columns(), OfferFields::systemOfferColumns());
            $allowed = $this->unmapped($dir, OfferFields::columns(), OfferFields::systemOfferColumns() + ['warranty_months' => 'a column the system fills, with a reason that is long enough to read']);
            $mapped = $this->unmapped($dir, [...OfferFields::columns(), 'warranty_months'], OfferFields::systemOfferColumns());
            $other = $this->unmapped($dir, OfferFields::columns(), OfferFields::systemOfferColumns() + ['warranty_month' => 'a different column name, so this must not silence the real one']);
        } finally {
            $this->removeDir($dir);
        }

        $this->assertCount(1, $none);
        $this->assertSame([], $allowed, 'an allow-list entry silences its column');
        $this->assertSame([], $mapped, 'a map entry silences its column');
        $this->assertCount(1, $other, 'an entry for a different column does not');
    }
}
