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

    // ------------------------------------------------------------------ E2 (P2-F): the writer's rules against the size of each column

    /** The size of a text column in bytes (a character may take four of them in utf8mb4). */
    private const TEXT_BYTES = ['tinyText' => 255, 'text' => 65535, 'mediumText' => 16777215, 'longText' => 4294967295];

    /** The size of a whole-number column in bytes. */
    private const INT_BYTES = ['tinyInteger' => 1, 'smallInteger' => 2, 'mediumInteger' => 3, 'integer' => 4, 'bigInteger' => 8];

    /**
     * The request keys of the map that are NOT checked against a column, each with the reason: `productMasterId` is the Product the record sits under, never a key of the
     * Sell section (CatalogueFields::fromSell skips it; ProductWriter::parentOf reads it as `productId` and keeps only a positive whole number), and its migration names the
     * column in a constant, which this scan does not resolve.
     */
    private const NOT_A_SELL_RULE = ['productMasterId' => 'the record\'s own Product: read as productId by the writer, which keeps only a positive whole number; not a Sell key'];

    /** The string length a `string()` column gets when the migration names none: Schema::defaultStringLength() in the service provider (255 when it sets none). */
    private function defaultStringLength(): int
    {
        $code = $this->code(dirname(__DIR__, 2) . '/app/Providers/AppServiceProvider.php');

        return preg_match('/Schema::defaultStringLength\(\s*(\d+)\s*\)/', $code, $m) === 1 ? (int) $m[1] : 255;
    }

    /**
     * What the migrations of one folder say about the type of each column of the table: Blueprint calls only (string, char, the text family, decimal, the integer family,
     * boolean). A column can have several definitions (a later migration may change it); every one is kept and a rule must fit the narrowest.
     *
     * @return array<string, list<array{kind: string, length?: int, precision?: int, scale?: int, unsigned?: bool, bytes?: int, file: string}>>
     */
    private function columnTypes(string $dir): array
    {
        $default = $this->defaultStringLength();
        $found = [];
        $methods = 'string|char|tinyText|text|mediumText|longText|decimal|unsignedDecimal|tinyInteger|unsignedTinyInteger|smallInteger|unsignedSmallInteger|mediumInteger|unsignedMediumInteger|integer|unsignedInteger|bigInteger|unsignedBigInteger|boolean';

        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $code = $this->code($path);
            if (! in_array(self::TABLE, $this->ownTables($code), true)) {
                continue;
            }
            preg_match_all('/->\s*(' . $methods . ')\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]\s*(?:,\s*(\d+))?\s*(?:,\s*(\d+))?\s*\)/', $code, $all, PREG_SET_ORDER);
            foreach ($all as $m) {
                [, $method, $column] = $m;
                $unsigned = str_starts_with($method, 'unsigned');
                $base = $unsigned ? lcfirst(substr($method, 8)) : $method;
                $file = basename($path);
                if ($base === 'string' || $base === 'char') {
                    $def = ['kind' => 'char', 'length' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : ($base === 'char' ? 255 : $default)];
                } elseif (isset(self::TEXT_BYTES[$base])) {
                    $def = ['kind' => 'text', 'bytes' => self::TEXT_BYTES[$base]];
                } elseif ($base === 'decimal') {
                    $def = ['kind' => 'decimal', 'precision' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 8, 'scale' => isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 2, 'unsigned' => $unsigned];
                } elseif (isset(self::INT_BYTES[$base])) {
                    $def = ['kind' => 'int', 'bytes' => self::INT_BYTES[$base], 'unsigned' => $unsigned];
                } else {
                    $def = ['kind' => 'bool'];
                }
                $found[$column][] = $def + ['file' => $file];
            }
        }

        return $found;
    }

    /** @return array<string, string> the tokens of one Laravel rule string: name => parameter ('' when it has none) */
    private function ruleTokens(string $rule): array
    {
        $tokens = [];
        foreach (explode('|', $rule) as $part) {
            [$name, $param] = array_pad(explode(':', $part, 2), 2, '');
            $tokens[$name] = $param;
        }

        return $tokens;
    }

    /**
     * What a rule lets in that the column it writes cannot hold, one line per problem. A rule must say what kind of value it is (string, numeric, integer, boolean)
     * and bound it: `max` for text, `min` and `max` for a number (a whole number with no bound is bounded by PHP's own integer, which is the column's own size for a bigint).
     *
     * @param  array<string, string>  $rules  request key => rule
     * @param  array<string, string>  $columnOf  request key => column
     * @param  array<string, list<array<string, mixed>>>  $types  column => definitions
     * @return list<string>
     */
    private function tooWide(array $rules, array $columnOf, array $types): array
    {
        $out = [];
        $n = fn (float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        foreach ($columnOf as $key => $column) {
            if (! isset($rules[$key])) {
                $out[] = "{$key}: no rule at all for the column {$column}";

                continue;
            }
            if (! isset($types[$column])) {
                $out[] = "{$key}: the type of the column {$column} cannot be read from the migrations";

                continue;
            }
            $t = $this->ruleTokens($rules[$key]);
            $min = array_key_exists('min', $t) && is_numeric($t['min']) ? (float) $t['min'] : null;
            $max = array_key_exists('max', $t) && is_numeric($t['max']) ? (float) $t['max'] : null;
            foreach ($types[$column] as $def) {
                switch ($def['kind']) {
                    case 'char':
                        if (! isset($t['string'])) {
                            $out[] = "{$key}: the column {$column} holds text but the rule has no 'string'";
                        } elseif ($max === null || $max > $def['length']) {
                            $out[] = "{$key}: the rule allows " . ($max === null ? 'any length' : "{$max} characters") . " and {$column} holds {$def['length']}";
                        }
                        break;
                    case 'text':
                        $characters = intdiv((int) $def['bytes'], 4);
                        if (! isset($t['string'])) {
                            $out[] = "{$key}: the column {$column} holds text but the rule has no 'string'";
                        } elseif ($max === null || $max > $characters) {
                            $out[] = "{$key}: the rule allows " . ($max === null ? 'any length' : "{$max} characters") . " and {$column} holds {$def['bytes']} bytes (at most {$characters} characters of four bytes)";
                        }
                        break;
                    case 'decimal':
                        $largest = (float) (($def['precision'] - $def['scale'] > 0 ? str_repeat('9', $def['precision'] - $def['scale']) : '0') . ($def['scale'] > 0 ? '.' . str_repeat('9', $def['scale']) : ''));
                        $lowest = $def['unsigned'] ? 0.0 : -$largest;
                        if (! isset($t['numeric'])) {
                            $out[] = "{$key}: the column {$column} holds a number but the rule has no 'numeric'";
                        }
                        if ($max === null || $max > $largest) {
                            $out[] = "{$key}: the rule allows " . ($max === null ? 'any size' : "up to " . $n($max)) . " and {$column} (decimal {$def['precision']},{$def['scale']}) holds up to " . $n((float) $largest);
                        }
                        if ($min === null || $min < $lowest) {
                            $out[] = "{$key}: the rule allows " . ($min === null ? 'any negative' : "down to " . $n($min)) . " and {$column} holds down to " . $n((float) $lowest);
                        }
                        break;
                    case 'int':
                        $bits = 8 * (int) $def['bytes'];
                        $largest = $def['unsigned'] ? (2 ** $bits) - 1 : (2 ** ($bits - 1)) - 1;
                        $lowest = $def['unsigned'] ? 0 : -(2 ** ($bits - 1));
                        if (! isset($t['integer'])) {
                            $out[] = "{$key}: the column {$column} holds a whole number but the rule has no 'integer'";
                        }
                        if (($max ?? (float) PHP_INT_MAX) > $largest) {
                            $out[] = "{$key}: the rule allows " . ($max === null ? 'any whole number' : "up to " . $n($max)) . " and {$column} holds up to " . $n((float) $largest);
                        }
                        if (($min ?? (float) PHP_INT_MIN) < $lowest) {
                            $out[] = "{$key}: the rule allows " . ($min === null ? 'any negative whole number' : "down to " . $n($min)) . " and {$column} holds down to " . $n((float) $lowest);
                        }
                        break;
                    case 'bool':
                        if (! isset($t['boolean'])) {
                            $out[] = "{$key}: the column {$column} holds a flag but the rule has no 'boolean'";
                        }
                        break;
                }
            }
        }

        return $out;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} [the writer's rule for every Sell key and the name, the column each request key writes (aliases included)] */
    private function writerRulesAndColumns(): array
    {
        $columnOf = array_diff_key(CatalogueFields::MAP, self::NOT_A_SELL_RULE);
        foreach (CatalogueFields::SELL_ALIASES as $alias => $key) {
            $columnOf[$alias] = CatalogueFields::MAP[$key];
        }
        $rules = CatalogueFields::sellRules() + ['name' => CatalogueFields::rules(forUpdate: true)['name']];

        return [$rules, $columnOf];
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

    // ------------------------------------------------------------------ E2 (P2-F): rules against the size of the columns they write

    public function test_e2_every_rule_the_writer_applies_to_a_sell_field_fits_the_column_it_writes_for_strings_and_numbers(): void
    {
        [$rules, $columnOf] = $this->writerRulesAndColumns();

        $findings = $this->tooWide($rules, $columnOf, $this->columnTypes(self::migrations()));

        $this->assertSame([], $findings, "A rule of the one-record writer lets in more than its column of crm_products can hold (a value past the column is a database error under strict "
            . "mode: a 500, not a 422). Tighten the rule in App\\Domain\\ProductMaster\\CatalogueFields::limits():\n  " . implode("\n  ", $findings));
    }

    public function test_e2_the_scan_really_reads_the_column_types_so_it_cannot_pass_by_reading_nothing(): void
    {
        $types = $this->columnTypes(self::migrations());
        // The independent anchor: the types `SHOW COLUMNS FROM crm_products` gave on the dev database on 14 Nov 2026 (docs/agentic-crm/_evidence/s15/p2f-dev-columns-2026-11-14.txt).
        $anchor = [
            'name' => ['char', 191], 'sku' => ['char', 100], 'category' => ['char', 100], 'gl_account' => ['char', 191], 'currency' => ['char', 10], 'vendor' => ['char', 191],
            'usage_unit' => ['char', 191], 'product_no' => ['char', 100], 'image' => ['char', 191],
            'description' => ['text', 65535],
            'unit_price' => ['decimal', 15, 2], 'cost_price' => ['decimal', 15, 2], 'commission_rate' => ['decimal', 5, 2], 'qty_in_stock' => ['decimal', 15, 2],
            'qty_in_demand' => ['decimal', 12, 2], 'weight' => ['decimal', 10, 3],
            'reorder_level' => ['int', 4, true], 'tax_rate_id' => ['int', 8, true], 'assigned_to' => ['int', 8, true],
            'is_active' => ['bool'],
        ];
        $read = [];
        foreach ($anchor as $column => $_) {
            $this->assertArrayHasKey($column, $types, "the scan found no type for {$column}");
            $this->assertCount(1, $types[$column], "{$column} is defined once");
            $def = $types[$column][0];
            $read[$column] = match ($def['kind']) {
                'char' => ['char', $def['length']],
                'text' => ['text', $def['bytes']],
                'decimal' => ['decimal', $def['precision'], $def['scale']],
                'int' => ['int', $def['bytes'], $def['unsigned']],
                default => ['bool'],
            };
        }
        $this->assertSame($anchor, $read, 'the scan reads the same types as the database');
    }

    public function test_e2_every_map_key_has_a_rule_and_an_alias_has_the_rule_of_its_field(): void
    {
        [$rules, $columnOf] = $this->writerRulesAndColumns();

        foreach ($columnOf as $key => $column) {
            $this->assertArrayHasKey($key, $rules, "the request key '{$key}' writes '{$column}' and has no rule");
        }
        foreach (CatalogueFields::SELL_ALIASES as $alias => $key) {
            $this->assertSame($rules[$key], $rules[$alias], "the alias '{$alias}' is checked by the rule of '{$key}'");
        }
        $this->assertSame([], array_keys(array_diff_key(array_diff_key($rules, $columnOf), ['name' => 1])), 'a rule of the Sell section names a request key that writes no column');
        foreach (self::NOT_A_SELL_RULE as $key => $why) {
            $this->assertArrayHasKey($key, CatalogueFields::MAP, "'{$key}' is exempt but is not a key of the map any more: remove the exemption");
            $this->assertGreaterThanOrEqual(30, strlen($why));
            $this->assertArrayNotHasKey($key, $rules, "'{$key}' is exempt because it is not a Sell field, so the Sell rules must not have a rule for it");
        }
    }

    public function test_e2_a_boundary_is_judged_exactly_one_past_the_column_is_reported_and_the_largest_value_is_not(): void
    {
        $types = $this->columnTypes(self::migrations());
        $judge = fn (string $key, string $column, string $rule): array => $this->tooWide([$key => $rule], [$key => $column], $types);

        $fits = [
            ['sku', 'sku', 'nullable|string|max:100'], ['category', 'category', 'string|max:100'], ['glAccount', 'gl_account', 'string|max:191'], ['currency', 'currency', 'string|max:10'],
            ['description', 'description', 'string|max:16383'],
            ['unitPrice', 'unit_price', 'numeric|min:0|max:9999999999999.99'], ['unitPrice', 'unit_price', 'numeric|min:-9999999999999.99|max:9999999999999.99'],
            ['qtyInDemand', 'qty_in_demand', 'numeric|min:0|max:9999999999.99'], ['weight', 'weight', 'numeric|min:0|max:9999999.999'],
            ['commissionRate', 'commission_rate', 'numeric|min:0|max:999.99'],
            ['reorderLevel', 'reorder_level', 'integer|min:0|max:4294967295'], ['taxRateId', 'tax_rate_id', 'integer|min:0'], ['isActive', 'is_active', 'nullable|boolean'],
        ];
        foreach ($fits as [$key, $column, $rule]) {
            $this->assertSame([], $judge($key, $column, $rule), "{$rule} fits {$column}");
        }
        $overflows = [
            ['sku', 'sku', 'string|max:101'], ['sku', 'sku', 'string'], ['sku', 'sku', 'max:100'], ['currency', 'currency', 'string|max:11'],
            ['description', 'description', 'string|max:16384'], ['description', 'description', 'string'],
            ['unitPrice', 'unit_price', 'numeric|min:0|max:10000000000000'], ['unitPrice', 'unit_price', 'numeric|min:0'], ['unitPrice', 'unit_price', 'numeric|max:9999999999999.99'],
            ['unitPrice', 'unit_price', 'numeric|min:-10000000000000|max:9999999999999.99'], ['unitPrice', 'unit_price', 'min:0|max:9999999999999.99'],
            ['qtyInDemand', 'qty_in_demand', 'numeric|min:0|max:10000000000'], ['weight', 'weight', 'numeric|min:0|max:10000000'],
            ['commissionRate', 'commission_rate', 'numeric|min:0|max:1000'],
            ['reorderLevel', 'reorder_level', 'integer|min:0|max:4294967296'], ['reorderLevel', 'reorder_level', 'integer|min:0'], ['reorderLevel', 'reorder_level', 'integer|min:-1|max:4294967295'],
            ['taxRateId', 'tax_rate_id', 'integer'], ['taxRateId', 'tax_rate_id', 'min:0'], ['isActive', 'is_active', 'nullable'],
        ];
        foreach ($overflows as [$key, $column, $rule]) {
            $this->assertNotSame([], $judge($key, $column, $rule), "{$rule} lets in more than {$column} holds");
            $this->assertStringStartsWith($key . ':', $judge($key, $column, $rule)[0], 'a finding names the request key');
        }
        $this->assertSame(["x: no rule at all for the column sku"], $this->tooWide([], ['x' => 'sku'], $types));
        $this->assertSame(["x: the type of the column no_such_column cannot be read from the migrations"], $this->tooWide(['x' => 'string|max:5'], ['x' => 'no_such_column'], $types));
    }

    public function test_e2_loosening_any_one_token_of_any_rule_is_reported_and_names_the_key(): void
    {
        [$rules, $columnOf] = $this->writerRulesAndColumns();
        $types = $this->columnTypes(self::migrations());
        $this->assertSame([], $this->tooWide($rules, $columnOf, $types), 'the baseline is clean, so a finding below is the loosening');
        $far = '1000000000000000000000000000000';
        $variants = 0;

        foreach ($rules as $key => $rule) {
            foreach ($this->ruleTokens($rule) as $token => $param) {
                if (! in_array($token, ['string', 'numeric', 'integer', 'boolean', 'min', 'max'], true)) {
                    continue;
                }
                // One variant takes the token out, another pushes its bound far past any column (a type token has no bound).
                $variants2 = ['without' => implode('|', array_filter(explode('|', $rule), fn (string $part): bool => explode(':', $part, 2)[0] !== $token))];
                if ($token === 'max') {
                    $variants2['far'] = implode('|', array_map(fn (string $part): string => explode(':', $part, 2)[0] === 'max' ? 'max:' . $far : $part, explode('|', $rule)));
                }
                if ($token === 'min') {
                    $variants2['far'] = implode('|', array_map(fn (string $part): string => explode(':', $part, 2)[0] === 'min' ? 'min:-' . $far : $part, explode('|', $rule)));
                }
                foreach ($variants2 as $how => $loosened) {
                    $findings = $this->tooWide([$key => $loosened], [$key => $columnOf[$key]], $types);
                    $this->assertNotSame([], $findings, "the rule of '{$key}' with its '{$token}' ({$how}) lets in more than {$columnOf[$key]} holds, and the scan must say so");
                    $variants++;
                }
            }
        }
        $this->assertGreaterThan(60, $variants, 'the scan loosened every token of every rule');
    }

    public function test_e2_the_writer_takes_its_sell_rules_from_the_one_class_and_keeps_no_rule_of_its_own_for_a_field(): void
    {
        $code = $this->code(dirname(__DIR__, 2) . '/app/Domain/ProductMaster/ProductWriter.php');

        $this->assertTrue(str_contains($code, 'CatalogueFields::sellRules()'), 'ProductWriter must take the rules of the Sell section from CatalogueFields::sellRules()');
        $this->assertSame(0, preg_match("/'sell\\.[A-Za-z]+'\\s*=>/", $code), "ProductWriter keeps a private rule for a Sell field ('sell.x' => ...): put it in CatalogueFields::limits(), where the scan reads it");
    }
}
