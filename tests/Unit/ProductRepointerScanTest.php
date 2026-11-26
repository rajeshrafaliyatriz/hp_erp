<?php

namespace Tests\Unit;

use App\Domain\ProductMaster\ProductPointers;
use App\Domain\ProductMaster\ProductRepointer;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the catalogue merge (slice 15, P2-B2, defect D6). The merge walks ProductPointers::toCrmProduct(), so a pointer added there is moved without
 * anybody remembering it; what a walk cannot know is which pointers sit in a UNIQUE key (a plain UPDATE would then break the merge), and that is what this file
 * reads from the migrations:
 *
 *   1. every registered pointer at a catalogue item is either repointed by the merge or listed in ProductRepointer::leftAlone() with a written reason, and the
 *      hand-written anchor below says which, so a registry row added later fails HERE until its handling has been decided;
 *   2. every unique key in a migration that contains a registered pointer column is declared in ProductRepointer::uniqueKeys() with the OTHER columns of the key
 *      (the merge then drops the duplicate's row where the survivor already holds the pair), and a table whose key holds two pointer columns is in selfPairs()
 *      (a bundle may not contain itself);
 *   3. nothing is declared that no migration has (a stale declaration would hide a change of the schema).
 *
 * Plain PHPUnit, no database: it only reads files, like tests/Unit/ProductPointersScanTest.php and OrganisationReferencesScanTest.php.
 */
class ProductRepointerScanTest extends TestCase
{
    /**
     * Every pointer at a catalogue item known when the merge was written, listed by hand: the independent anchor. "table.column" => the other columns of the unique
     * key it sits in (empty: no unique key, a plain move). All eleven move.
     */
    private const KNOWN = [
        'crm_quote_line_items.product_id' => [],
        'crm_sales_order_line_items.product_id' => [],
        'crm_invoice_line_items.product_id' => [],
        'crm_opportunity_products.product_id' => [],
        'crm_price_book_items.product_id' => ['price_book_id'],
        'crm_product_bundle_items.parent_product_id' => ['component_product_id'],
        'crm_product_bundle_items.component_product_id' => ['parent_product_id'],
        'crm_tickets.product_id' => [],
        'crm_assets.product_id' => [],
        'crm_faqs.product_id' => [],
        'crm_campaigns.product_id' => [],
    ];

    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /** @return list<string> "table.column" of every registered pointer at a catalogue item */
    private function registered(): array
    {
        return array_map(fn (array $e): string => $e['table'] . '.' . $e['column'], ProductPointers::toCrmProduct());
    }

    // ---- the registry against the merge ----

    public function test_every_registered_pointer_is_repointed_or_left_alone_with_a_reason_and_the_anchor_agrees(): void
    {
        $registered = $this->registered();
        $known = array_keys(self::KNOWN);
        sort($registered);
        sort($known);
        $this->assertSame($known, $registered, 'ProductPointers::toCrmProduct() and the anchor in this test must list the same pointers. A pointer was added or removed: decide what the merge does with it (a plain move, a unique key, or left alone with a reason), then change the anchor.');

        $handling = ProductRepointer::handling();
        $this->assertEqualsCanonicalizing($registered, array_keys($handling), 'the merge says what it does with every registered pointer, and with nothing else');
        foreach ($handling as $key => $how) {
            if ($how['moves']) {
                $this->assertSame(self::KNOWN[$key], $how['unique'], "{$key}: the unique key the merge knows about is the one the anchor lists");
                $this->assertNull($how['reason'], "{$key}: a pointer that moves has no excuse");
            } else {
                $this->assertGreaterThanOrEqual(30, strlen((string) $how['reason']), "{$key}: a pointer the merge leaves alone needs a written reason a reviewer can disagree with");
            }
        }
        $this->assertSame([], array_filter($handling, fn (array $how): bool => ! $how['moves']), 'all eleven move today; if one is left alone on purpose, this assertion is the place that says so');
    }

    public function test_what_is_left_alone_and_what_is_declared_are_real_pointers_of_the_registry(): void
    {
        $registered = $this->registered();
        foreach (array_keys(ProductRepointer::leftAlone()) as $key) {
            $this->assertContains($key, $registered, "{$key} is listed as left alone but is not a registered pointer at a catalogue item");
        }
        foreach (array_keys(ProductRepointer::uniqueKeys()) as $key) {
            $this->assertContains($key, $registered, "{$key} has a declared unique key but is not a registered pointer at a catalogue item");
        }
        foreach (ProductRepointer::selfPairs() as $table => $columns) {
            $this->assertCount(2, $columns, "{$table}: a self-pair is exactly two pointer columns");
            foreach ($columns as $column) {
                $this->assertContains("{$table}.{$column}", $registered, "{$table}.{$column} is in a self-pair but is not a registered pointer");
            }
        }
    }

    // ---- the migrations against the declarations ----

    /**
     * Every unique key in the files of one folder that contains a registered pointer column, as findings.
     *
     * @return array{missing: list<string>, found: list<string>} what the migrations hold that is not declared, and every "table.column key" they do hold
     */
    private function scan(string $dir): array
    {
        $missing = [];
        $found = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $code = $this->code($path);
            $tables = $this->ownTables($code);
            foreach ($this->uniqueKeys($code) as $columns) {
                $pointers = [];
                foreach (ProductPointers::toCrmProduct() as $e) {
                    if (in_array($e['table'], $tables, true) && in_array($e['column'], $columns, true)) {
                        $pointers[] = $e;
                    }
                }
                foreach ($pointers as $e) {
                    $key = $e['table'] . '.' . $e['column'];
                    $others = array_values(array_diff($columns, [$e['column']]));
                    $found[] = $key . ' [' . implode(', ', $columns) . ']';
                    $declared = ProductRepointer::uniqueKeys()[$key] ?? null;
                    if ($declared === null || array_values(array_diff($declared, $others)) !== [] || array_values(array_diff($others, $declared)) !== []) {
                        $missing[] = basename($path) . ": {$key} is in the unique key (" . implode(', ', $columns) . ') but ProductRepointer::uniqueKeys() declares ' . ($declared === null ? 'nothing' : '(' . implode(', ', $declared) . ')');
                    }
                }
                $tablesOfPointers = array_values(array_unique(array_map(fn (array $e): string => $e['table'], $pointers)));
                foreach ($tablesOfPointers as $table) {
                    $pointerColumns = array_values(array_filter(array_map(fn (array $e): ?string => $e['table'] === $table ? $e['column'] : null, $pointers)));
                    if (count($pointerColumns) >= 2) {
                        $declared = ProductRepointer::selfPairs()[$table] ?? [];
                        if (array_values(array_diff($pointerColumns, $declared)) !== []) {
                            $missing[] = basename($path) . ": {$table} has a unique key with two pointer columns (" . implode(', ', $pointerColumns) . ') and is not in ProductRepointer::selfPairs()';
                        }
                    }
                }
            }
        }

        return ['missing' => $missing, 'found' => $found];
    }

    public function test_every_unique_key_that_holds_a_registered_pointer_is_declared_so_the_merge_cannot_break_on_it(): void
    {
        $scan = $this->scan(self::migrations());

        $this->assertSame([], $scan['missing'], "a unique key the merge does not know about would break it:\n" . implode("\n", $scan['missing']));
        $this->assertNotEmpty($scan['found'], 'the scan found the unique keys of the price book and bundle tables, so it cannot pass by looking at nothing');
    }

    public function test_the_scan_finds_the_three_unique_keys_that_exist_and_every_declaration_has_a_real_key_behind_it(): void
    {
        $scan = $this->scan(self::migrations());
        $found = array_map(fn (string $f): string => explode(' [', $f)[0], $scan['found']);

        $this->assertEqualsCanonicalizing(
            ['crm_price_book_items.product_id', 'crm_product_bundle_items.parent_product_id', 'crm_product_bundle_items.component_product_id'],
            array_values(array_unique($found)),
            'the unique keys that include a pointer at a catalogue item today',
        );
        foreach (array_keys(ProductRepointer::uniqueKeys()) as $key) {
            $this->assertContains($key, $found, "{$key} is declared as being in a unique key, but no migration has one: a stale declaration");
        }
        $this->assertSame(['crm_product_bundle_items'], array_keys(ProductRepointer::selfPairs()), 'the only table whose unique key holds two pointers is the bundle');
    }

    public function test_the_scan_can_fail_a_new_unique_key_on_a_pointer_that_nobody_declared_is_reported(): void
    {
        $dir = sys_get_temp_dir() . '/pm-repoint-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/2099_01_01_000000_add_unique_to_faqs.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('crm_faqs', function (\$table) {\n            \$table->unique(['product_id', 'faq_no'], 'faq_pair');\n        });\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000001_unique_on_the_pointer_alone.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('crm_assets', function (\$table) {\n            \$table->unsignedBigInteger('product_id')->unique();\n        });\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000002_raw_sql.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        DB::statement('ALTER TABLE `crm_tickets` ADD UNIQUE KEY `t_pair` (`product_id`, `ticket_no`)');\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000003_unrelated.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('crm_widgets', function (\$table) {\n            \$table->unique(['product_id', 'name']);\n        });\n    }\n};\n");

            $scan = $this->scan($dir);

            $text = implode("\n", $scan['missing']);
            $this->assertStringContainsString('crm_faqs.product_id is in the unique key (product_id, faq_no)', $text, 'a Blueprint unique with two columns');
            $this->assertStringContainsString('crm_assets.product_id is in the unique key (product_id)', $text, 'a unique on the pointer column alone, chained on its definition');
            $this->assertStringContainsString('crm_tickets.product_id is in the unique key (product_id, ticket_no)', $text, 'a raw ALTER TABLE ... UNIQUE KEY');
            $this->assertStringNotContainsString('crm_widgets', $text, 'a table that is not in the registry is not this guard\'s business');
            $this->assertCount(3, $scan['missing']);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    // ---- reading migrations (the same way tests/Unit/ProductPointersScanTest.php does) ----

    /** The code of a PHP file with every comment removed (documentation that names a unique key is not one). */
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
     * The column lists of every unique key a migration declares: `->unique([...])`, `->unique('a')`, a column definition chained with `->unique()`, and raw
     * `UNIQUE KEY (...)` / `UNIQUE INDEX (...)` in a statement.
     *
     * @return list<list<string>>
     */
    private function uniqueKeys(string $code): array
    {
        $keys = [];
        if (preg_match_all('/->\s*unique\(\s*(\[[^\]]*\]|[\'"][^\'"]+[\'"])/', $code, $m) > 0) {
            foreach ($m[1] as $list) {
                preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/i', $list, $cols);
                $keys[] = $cols[1];
            }
        }
        // `$table->unsignedBigInteger('product_id')->unique()` : the key is the column the definition names first.
        if (preg_match_all('/->\s*\w+\(\s*[\'"]([a-z0-9_]+)[\'"][^;]*?\)\s*->\s*unique\(\s*\)/', $code, $m) > 0) {
            foreach ($m[1] as $column) {
                $keys[] = [$column];
            }
        }
        if (preg_match_all('/UNIQUE\s+(?:KEY|INDEX)\s*(?:`?[a-z0-9_]+`?\s*)?\(([^)]*)\)/i', $code, $m) > 0) {
            foreach ($m[1] as $list) {
                preg_match_all('/`?([a-z0-9_]+)`?/i', $list, $cols);
                $keys[] = $cols[1];
            }
        }

        return $keys;
    }
}
