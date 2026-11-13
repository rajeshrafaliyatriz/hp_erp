<?php

namespace Tests\Unit;

use App\Domain\ProductMaster\CatalogueFields;
use PHPUnit\Framework\TestCase;

/**
 * The permanent guard behind "no old product field is left behind" (slice 15, P2-C; the owner's request of 13 Nov 2026).
 *
 * The one Sub-product form writes the catalogue row through App\Domain\ProductMaster\CatalogueFields, the same map the CRM Products form uses. This test reads every
 * migration that works on `crm_products` and fails when a column of the table is neither
 *   - a field of CatalogueFields::MAP (or its type column), so both doors read and write it, nor
 *   - one of CatalogueFields::systemColumns(), the short list of columns the system fills, each with a written reason of 30 characters or more.
 * So a column added to the table later cannot be forgotten: whoever adds it must map it (and so write and read it) or explain why no form touches it.
 *
 * How a migration is read (plain PHPUnit, no database: it only reads files, like tests/Unit/ProductPointersScanTest.php): comments are removed; a migration counts
 * when it works on the table (a literal Schema::create / Schema::table name, a CREATE TABLE / ALTER TABLE name, or a `const TABLE`); the columns are the Blueprint
 * calls that define one ($table->string('sku'), ->decimal('unit_price', 15, 2), id(), timestamps(), softDeletes() ...), a raw ADD COLUMN, and a `const COLUMN`
 * (the product_master_id migration names its column in a constant). A migration that works on several tables gives all its columns to each of them, so the scan
 * can only ask for more, never for less. A column that a later migration drops is still reported until it is allow-listed or removed from the scan's reach.
 */
class CatalogueFieldsScanTest extends TestCase
{
    private const TABLE = 'crm_products';

    /**
     * The 31 columns the table has on the dev database (SHOW COLUMNS FROM crm_products, read on 13 Nov 2026, in the order the host lists them), written out by hand:
     * the independent anchor, so the scan cannot pass by finding nothing, and a column that a migration loses fails here and not silently.
     */
    private const KNOWN = [
        'id', 'legacy_id', 'item_type', 'product_no', 'name', 'sku', 'category', 'gl_account', 'description', 'unit_price', 'cost_price', 'commission_rate', 'currency',
        'tax_rate_id', 'is_active', 'vendor', 'qty_in_stock', 'qty_in_demand', 'reorder_level', 'weight', 'image', 'usage_unit', 'assigned_to', 'created_by', 'updated_by',
        'deleted_by', 'sub_institute_id', 'created_at', 'updated_at', 'deleted_at', 'product_master_id',
    ];

    /** The Blueprint methods that define a column named by their first argument. */
    private const DEFINING_METHODS = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime', 'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignUlid',
        'foreignUuid', 'geometry', 'increments', 'integer', 'ipAddress', 'json', 'jsonb', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger', 'mediumText', 'set',
        'smallIncrements', 'smallInteger', 'string', 'text', 'time', 'timeTz', 'timestamp', 'timestampTz', 'tinyIncrements', 'tinyInteger', 'tinyText', 'ulid',
        'unsignedBigInteger', 'unsignedDecimal', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'uuid', 'year',
    ];

    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /** The code of a PHP file with every comment removed (documentation that names a column is not a definition of it). */
    private function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** @return list<string> the tables a migration works on, as far as they can be read from it */
    private function ownTables(string $code): array
    {
        $tables = [];
        foreach ([
            '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]/',
            '/(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+`?([a-z0-9_]+)`?/i',
            '/\bconst\s+TABLE(?:_NAME)?\s*=\s*[\'"]([a-z0-9_]+)[\'"]/',
        ] as $pattern) {
            preg_match_all($pattern, $code, $m);
            foreach ($m[1] as $name) {
                $tables[$name] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * Every column the migrations of one folder define on the table.
     *
     * @return array<string, list<string>> column => the files that define it
     */
    private function columnsDefined(string $dir): array
    {
        $found = [];
        $add = function (string $column, string $file) use (&$found): void {
            if (! in_array($file, $found[$column] ?? [], true)) {
                $found[$column][] = $file;
            }
        };

        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $code = $this->code($path);
            if (! in_array(self::TABLE, $this->ownTables($code), true)) {
                continue;
            }
            $file = basename($path);

            // A Blueprint call that names a column: $table->string('sku', 100), ->decimal('unit_price', 15, 2), ->unsignedBigInteger('assigned_to') ...
            preg_match_all('/->\s*(?:' . implode('|', self::DEFINING_METHODS) . ')\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m);
            foreach ($m[1] as $column) {
                $add($column, $file);
            }
            // A Blueprint call that defines columns without naming them, or with a default name.
            if (preg_match('/->\s*id\(\s*\)/', $code) === 1) {
                $add('id', $file);
            }
            if (preg_match_all('/->\s*id\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m) > 0) {
                foreach ($m[1] as $column) {
                    $add($column, $file);
                }
            }
            if (preg_match('/->\s*(?:timestamps|timestampsTz|nullableTimestamps)\(/', $code) === 1) {
                $add('created_at', $file);
                $add('updated_at', $file);
            }
            if (preg_match('/->\s*softDeletes(?:Tz)?\(\s*\)/', $code) === 1) {
                $add('deleted_at', $file);
            }
            if (preg_match_all('/->\s*softDeletes(?:Tz)?\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m) > 0) {
                foreach ($m[1] as $column) {
                    $add($column, $file);
                }
            }
            // Raw SQL: ALTER TABLE ... ADD COLUMN `x` ...
            preg_match_all('/ADD\s+COLUMN\s+`?([a-z][a-z0-9_]*)`?/i', $code, $m);
            foreach ($m[1] as $column) {
                $add($column, $file);
            }
            // A column named in a constant, the way 2026_12_11_160000_add_product_master_id_to_crm_products.php does it.
            preg_match_all('/\bconst\s+COLUMN\s*=\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m);
            foreach ($m[1] as $column) {
                $add($column, $file);
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $mapped  the columns the field map writes (and the type column)
     * @param  array<string, string>  $system  column => reason
     * @return list<string> "file: column" of every column a migration defines that is in neither
     */
    private function unmapped(string $dir, array $mapped, array $system): array
    {
        $known = array_flip(array_merge($mapped, array_keys($system)));
        $missing = [];
        foreach ($this->columnsDefined($dir) as $column => $files) {
            if (! isset($known[$column])) {
                foreach ($files as $file) {
                    $missing[] = "{$file}: {$column}";
                }
            }
        }
        sort($missing);

        return $missing;
    }

    /** A folder holding the given migration texts, for a test that adds a column to a sample migration. @param array<string, string> $files file name => PHP text */
    private function sampleDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/catalogue-scan-' . bin2hex(random_bytes(4));
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

    public function test_every_column_of_crm_products_is_in_the_field_map_or_explained(): void
    {
        $missing = $this->unmapped(self::migrations(), CatalogueFields::columns(), CatalogueFields::systemColumns());

        $this->assertSame([], $missing, "A column of crm_products is neither in App\\Domain\\ProductMaster\\CatalogueFields::MAP (so the CRM Products form and the one Sub-product form both "
            . "read and write it) nor in CatalogueFields::systemColumns() with a written reason. Map it, or explain why no form touches it:\n  " . implode("\n  ", $missing));
    }

    public function test_the_scan_really_reads_the_migrations_so_it_cannot_pass_by_looking_at_nothing(): void
    {
        $found = array_keys($this->columnsDefined(self::migrations()));
        sort($found);
        $known = self::KNOWN;
        sort($known);

        $this->assertSame($known, $found, 'the migrations define exactly the 31 columns the dev database has: a column found here and not on the list is new (map it), a column on the list and not found is a hole in the scan');
    }

    public function test_the_map_and_the_allow_list_have_no_stale_contradictory_or_unexplained_entries(): void
    {
        $defined = array_keys($this->columnsDefined(self::migrations()));
        $map = CatalogueFields::columns();
        $system = CatalogueFields::systemColumns();

        foreach ($map as $column) {
            $this->assertContains($column, $defined, "the field map writes '{$column}' but no migration defines it on crm_products: a typo, or a column that was renamed");
        }
        foreach ($system as $column => $reason) {
            $this->assertContains($column, $defined, "systemColumns() lists '{$column}' but no migration defines it on crm_products: remove the entry");
            $this->assertNotContains($column, $map, "'{$column}' is both mapped and allow-listed: it is one or the other");
            $this->assertGreaterThanOrEqual(30, strlen(trim($reason)), "'{$column}' needs a written reason of 30 characters or more, one a reviewer can disagree with");
        }
        $this->assertSame(array_values(array_unique($map)), $map, 'a column is mapped twice');
        $this->assertSame(count(CatalogueFields::MAP), count(array_unique(array_values(CatalogueFields::MAP))), 'two request keys write one column');
        $this->assertSame([], array_values(array_intersect(array_keys(CatalogueFields::MAP), array_keys(CatalogueFields::SELL_ALIASES))), 'an alias is another name for a field, never a field of its own');
        foreach (CatalogueFields::SELL_ALIASES as $alias => $key) {
            $this->assertArrayHasKey($key, CatalogueFields::MAP, "the alias '{$alias}' points at '{$key}', which is not a field of the map");
        }
    }

    public function test_every_service_only_exclusion_is_a_mapped_column(): void
    {
        foreach (CatalogueFields::PRODUCT_ONLY as $column) {
            $this->assertContains($column, CatalogueFields::columns(), "{$column} is nulled for a service, so it must be a column the map writes");
        }
    }

    public function test_the_controller_and_the_writer_both_use_the_one_map_and_neither_keeps_a_copy(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['app/Http/Controllers/Api/Crm/CrmProductController.php', 'app/Domain/ProductMaster/ProductWriter.php'] as $relative) {
            $code = $this->code($root . '/' . $relative);
            // assertTrue with a message: a failing assertStringContainsString would print the whole source file.
            $this->assertTrue(str_contains($code, 'CatalogueFields::'), "{$relative} must read and write the catalogue through CatalogueFields");
            $this->assertFalse(str_contains($code, "'glAccount' => 'gl_account'"), "{$relative} keeps a private copy of the field map: use CatalogueFields::MAP");
            $this->assertFalse(str_contains($code, "'qtyInStock' => 'qty_in_stock'"), "{$relative} keeps a private copy of the field map: use CatalogueFields::MAP");
        }
    }

    // ------------------------------------------------------------------ the guard can fail

    public function test_removing_any_field_from_the_map_makes_the_scan_fail_and_names_the_column(): void
    {
        $this->assertSame([], $this->unmapped(self::migrations(), CatalogueFields::columns(), CatalogueFields::systemColumns()), 'the baseline is clean, so a finding below is the removal');

        foreach (CatalogueFields::columns() as $column) {
            $without = array_values(array_diff(CatalogueFields::columns(), [$column]));
            $missing = $this->unmapped(self::migrations(), $without, CatalogueFields::systemColumns());

            $this->assertNotSame([], $missing, "removing '{$column}' from the map must be reported");
            $this->assertTrue(
                count(array_filter($missing, fn (string $line): bool => str_ends_with($line, ": {$column}"))) >= 1,
                "removing '{$column}' from the map must be reported by file and column; got " . implode('; ', $missing),
            );
        }
    }

    public function test_removing_a_system_column_from_the_allow_list_makes_the_scan_fail(): void
    {
        foreach (array_keys(CatalogueFields::systemColumns()) as $column) {
            $system = CatalogueFields::systemColumns();
            unset($system[$column]);
            $missing = $this->unmapped(self::migrations(), CatalogueFields::columns(), $system);

            $this->assertNotSame([], array_filter($missing, fn (string $line): bool => str_ends_with($line, ": {$column}")), "removing '{$column}' from the allow-list must be reported");
        }
    }

    public function test_a_column_added_to_a_sample_migration_makes_the_scan_fail(): void
    {
        $dir = $this->sampleDir([
            '2027_01_01_000000_add_warranty_to_crm_products.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('crm_products', function (\$table) {\n            \$table->unsignedSmallInteger('warranty_months')->nullable();\n        });\n    }\n};\n",
            '2027_01_02_000000_raw_add_to_crm_products.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        DB::statement('ALTER TABLE `crm_products` ADD COLUMN `barcode` VARCHAR(40) NULL');\n    }\n};\n",
            '2027_01_03_000000_const_column.php' => "<?php\nreturn new class {\n    private const TABLE = 'crm_products';\n\n    private const COLUMN = 'hsn_code';\n\n    public function up(): void\n    {\n        DB::statement('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . self::COLUMN . '` VARCHAR(20) NULL');\n    }\n};\n",
            '2027_01_04_000000_another_table.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('crm_quotes', function (\$table) {\n            \$table->string('warranty_text')->nullable();\n        });\n    }\n};\n",
            '2027_01_05_000000_comment_only.php' => "<?php\n// Schema::table('crm_products') once had \$table->string('legacy_note');\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('crm_products', function (\$table) {\n            // \$table->string('only_in_a_comment');\n            \$table->index('sku');\n        });\n    }\n};\n",
        ]);

        try {
            $missing = $this->unmapped($dir, CatalogueFields::columns(), CatalogueFields::systemColumns());
        } finally {
            $this->removeDir($dir);
        }

        $this->assertSame([
            '2027_01_01_000000_add_warranty_to_crm_products.php: warranty_months',
            '2027_01_02_000000_raw_add_to_crm_products.php: barcode',
            '2027_01_03_000000_const_column.php: hsn_code',
        ], $missing, 'a Blueprint column, a raw ADD COLUMN and a constant-named column are found; another table\'s column, a comment and an index are not');
    }

    public function test_an_allow_list_entry_or_a_map_entry_silences_exactly_its_own_column(): void
    {
        $dir = $this->sampleDir([
            '2027_02_01_000000_add_warranty_to_crm_products.php' => "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('crm_products', function (\$table) {\n            \$table->unsignedSmallInteger('warranty_months')->nullable();\n        });\n    }\n};\n",
        ]);

        try {
            $none = $this->unmapped($dir, CatalogueFields::columns(), CatalogueFields::systemColumns());
            $allowed = $this->unmapped($dir, CatalogueFields::columns(), CatalogueFields::systemColumns() + ['warranty_months' => 'a column the system fills, with a reason that is long enough to read']);
            $mapped = $this->unmapped($dir, [...CatalogueFields::columns(), 'warranty_months'], CatalogueFields::systemColumns());
            $other = $this->unmapped($dir, CatalogueFields::columns(), CatalogueFields::systemColumns() + ['warranty_month' => 'a different column name, so this must not silence the real one']);
        } finally {
            $this->removeDir($dir);
        }

        $this->assertCount(1, $none);
        $this->assertSame([], $allowed, 'an allow-list entry silences its column');
        $this->assertSame([], $mapped, 'a map entry silences its column');
        $this->assertCount(1, $other, 'an entry for a different column does not');
    }
}
