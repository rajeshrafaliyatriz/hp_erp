<?php

namespace Tests\Unit;

use App\Domain\ProductMaster\ProductPointers;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the product registry (S15 plan section 2.1): reads every migration, finds every column whose name says it points at a product (it ends in
 * product_id, is product_master_id, or contains crm_product), and fails when one is neither in ProductPointers nor in the allow-lists below with a reason.
 * So a table added later that points at a product cannot be forgotten: a merge of two records of one product would leave its rows on the retired one, and the
 * checker would not look at them. This is the same idea as tests/Unit/OrganisationReferencesScanTest.php, adapted to how the product migrations are written
 * (most of them name their table in a constant and loop over columns, so a "table.column" cannot always be read from a Blueprint call).
 *
 * How a migration is read: comments are removed; every quoted or back-ticked word that looks like a pointer is a MENTION of a column; the tables a file works
 * on are the literal Schema::create / Schema::table names, the CREATE TABLE / ALTER TABLE names and `const TABLE` values (a table that is only the target of
 * a foreign key is not one of them). A mention is covered when one of the file's own tables carries that column in the registry (or in the allow-list). A file
 * whose own tables cannot be read is covered by any registered table it names. Plain PHPUnit, no database: it only reads files.
 */
class ProductPointersScanTest extends TestCase
{
    /** Every pointer known when the registry was written, listed by hand: the independent anchor (a registry that loses a row must fail here, not pass quietly). */
    private const KNOWN_CRM_PRODUCT = [
        'crm_assets.product_id', 'crm_campaigns.product_id', 'crm_faqs.product_id', 'crm_invoice_line_items.product_id', 'crm_opportunity_products.product_id',
        'crm_price_book_items.product_id', 'crm_product_bundle_items.parent_product_id', 'crm_product_bundle_items.component_product_id',
        'crm_quote_line_items.product_id', 'crm_sales_order_line_items.product_id', 'crm_tickets.product_id',
    ];

    private const KNOWN_MASTER = [
        'crm_campaigns.product_master_id', 'crm_leads.product_master_id', 'crm_opportunities.product_master_id', 'crm_products.product_master_id',
        'g2g_company_opportunities.product_master_id', 'g2g_product_claims.product_master_id', 'g2g_research_runs.product_master_id', 'gtm_campaigns.product_master_id',
        'gtm_outreach_messages.product_master_id', 'gtm_playbooks.product_master_id',
    ];

    private const KNOWN_OWN = ['g2g_product_master.crm_product_id', 'g2g_product_master.offer_row_id', 'g2g_product_master.parent_id'];

    /** The third family (slice 15, WPA1, G-E2): the numeric pointers at the About row (g2g_product_offers.id), listed by hand like the two above. */
    private const KNOWN_OFFER = ['g2g_offer_readiness_log.offer_row_id'];

    /**
     * Words the scan finds in the migrations that match the pattern and are not columns, each with the reason.
     *
     * @var array<string, string> word => reason
     */
    private const NOT_COLUMNS = [
        'crm_products_tenant_product' => 'the name of the index (sub_institute_id, product_master_id) that 2026_12_11_160000 adds to crm_products; an index name, not a column',
    ];

    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /** The code of a PHP file with every comment removed (documentation that names a column is not a use of it). */
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

    /**
     * The tables a migration works on, as far as they can be read from it.
     *
     * @return list<string>
     */
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
     * Every table some migration creates (a word that names a table is not a column, even when it contains crm_product). The real migrations are always
     * included, so a sample migration that merely names crm_products in a foreign key is not mistaken for one that defines a column of that name.
     *
     * @return array<string, true>
     */
    private function createdTables(string $dir): array
    {
        $created = [];
        foreach (array_unique([$dir, self::migrations()]) as $folder) {
            foreach (glob($folder . '/*.php') ?: [] as $path) {
                $code = $this->code($path);
                preg_match_all('/Schema::create\(\s*[\'"]([a-z0-9_]+)[\'"]/', $code, $m);
                foreach ($m[1] as $name) {
                    $created[$name] = true;
                }
                preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([a-z0-9_]+)`?/i', $code, $m);
                foreach ($m[1] as $name) {
                    $created[$name] = true;
                }
            }
        }

        return $created;
    }

    /**
     * The pointer-looking words of every migration: "file|word" => [file, word, own tables].
     *
     * @return array<string, array{file: string, word: string, tables: list<string>, code: string}>
     */
    private function mentions(string $dir): array
    {
        $created = $this->createdTables($dir);
        $mentions = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $code = $this->code($path);
            preg_match_all('/[\'"`]([a-z][a-z0-9_]*)[\'"`]/', $code, $m);
            foreach (array_unique($m[1]) as $word) {
                if (isset($created[$word]) || ! ProductPointers::looksLikeProductPointer($word)) {
                    continue;
                }
                $mentions[basename($path) . '|' . $word] = ['file' => basename($path), 'word' => $word, 'tables' => $this->ownTables($code), 'code' => $code];
            }
        }

        return $mentions;
    }

    /**
     * @param  list<array{table: string, column: string}>  $registry
     * @param  array<string, string>  $allowed  "table.column" => reason
     * @param  array<string, string>  $notColumns  word => reason
     * @return list<string> "file: word" of every mention that no registered or allow-listed column covers
     */
    private function unregistered(string $dir, array $registry, array $allowed, array $notColumns): array
    {
        $known = [];
        foreach ($registry as $r) {
            $known[$r['table']][$r['column']] = true;
        }
        foreach ($allowed as $key => $_) {
            [$table, $column] = explode('.', $key, 2);
            $known[$table][$column] = true;
        }

        $missing = [];
        foreach ($this->mentions($dir) as $mention) {
            if (isset($notColumns[$mention['word']])) {
                continue;
            }
            $tables = $mention['tables'];
            if ($tables === []) {
                // The file's own tables cannot be read: any registered table it names will do.
                $tables = array_values(array_filter(array_keys($known), fn (string $t): bool => preg_match('/(?<![a-z0-9_])' . preg_quote($t, '/') . '(?![a-z0-9_])/', $mention['code']) === 1));
            }
            $covered = false;
            foreach ($tables as $table) {
                if (isset($known[$table][$mention['word']])) {
                    $covered = true;

                    break;
                }
            }
            if (! $covered) {
                $missing[] = "{$mention['file']}: {$mention['word']} (tables read from the file: " . ($mention['tables'] === [] ? 'none' : implode(', ', $mention['tables'])) . ')';
            }
        }
        sort($missing);

        return $missing;
    }

    // ------------------------------------------------------------------ the guard

    public function test_every_product_pointer_in_the_migrations_is_registered_or_allow_listed(): void
    {
        $missing = $this->unregistered(self::migrations(), ProductPointers::columns(), ProductPointers::notPointers(), self::NOT_COLUMNS);

        $this->assertSame([], $missing, "These migrations define or mention a column that looks like a pointer at a product but is not in App\\Domain\\ProductMaster\\ProductPointers "
            . "(a merge of two records of one product would leave its rows on the retired one) nor in notPointers() with a reason. Register it, or allow-list it with the reason it is not:\n  "
            . implode("\n  ", $missing));
    }

    public function test_the_scan_really_reads_the_migrations_so_it_cannot_pass_by_looking_at_nothing(): void
    {
        $mentions = $this->mentions(self::migrations());
        $words = array_unique(array_map(fn (array $m): string => $m['word'], $mentions));
        sort($words);

        $this->assertSame([], array_values(array_diff(['component_product_id', 'crm_product_id', 'parent_product_id', 'product_id', 'product_master_id'], $words)),
            'the five column names the registered pointers are written with must all be found in the migrations');
        $this->assertGreaterThanOrEqual(22, count($mentions), 'at least one mention per migration that defines a registered pointer');
    }

    public function test_the_allow_lists_have_no_stale_or_contradictory_entries(): void
    {
        $registered = array_map(fn (array $r): string => "{$r['table']}.{$r['column']}", ProductPointers::columns());
        $words = array_unique(array_map(fn (array $m): string => $m['word'], $this->mentions(self::migrations())));

        foreach (self::NOT_COLUMNS as $word => $reason) {
            $this->assertContains($word, $words, "NOT_COLUMNS entry '{$word}' matches nothing in the migrations: remove it");
            $this->assertNotSame('', trim($reason), "'{$word}' needs a one-line reason");
        }
        foreach (ProductPointers::notPointers() as $key => $reason) {
            $this->assertNotContains($key, $registered, "{$key} is both registered and allow-listed");
            $this->assertNotSame('', trim($reason), "{$key} needs a one-line reason");
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+\.[a-z0-9_]+$/', $key);
        }
    }

    public function test_every_registered_column_is_really_defined_in_the_migrations(): void
    {
        // A typo in the registry would make the checker skip the column ("not on this host") for ever. Each name the registry relies on must be written in a
        // migration that works on the table it belongs to.
        $dir = self::migrations();
        $needs = [];
        foreach (ProductPointers::columns() as $r) {
            $needs[] = [$r['table'], $r['column']];
        }
        foreach (ProductPointers::all() as $e) {
            if (is_array($e['scope'])) {
                $needs[] = [$e['table'], $e['scope']['via']];
                $needs[] = [$e['scope']['parent'], $e['scope']['parent_tenant']];
            } else {
                $needs[] = [$e['table'], $e['scope']];
            }
            foreach ($e['measures'] as $measure) {
                $needs[] = [$e['table'], $measure];
            }
        }
        $needs[] = [ProductPointers::MASTER, 'kind'];

        $found = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $code = $this->code($path);
            $tables = $this->ownTables($code);
            preg_match_all('/[\'"`]([a-z][a-z0-9_]*)[\'"`]/', $code, $m);
            $words = array_flip($m[1]);
            foreach ($needs as [$table, $column]) {
                if (in_array($table, $tables, true) && isset($words[$column])) {
                    $found["{$table}.{$column}"] = true;
                }
            }
        }
        $absent = [];
        foreach ($needs as [$table, $column]) {
            if (! isset($found["{$table}.{$column}"])) {
                $absent["{$table}.{$column}"] = true;
            }
        }
        $this->assertSame([], array_keys($absent), 'registered columns that no migration defines on that table (a typo, or a column that was renamed)');

        $created = $this->createdTables($dir);
        foreach (ProductPointers::all() as $e) {
            $this->assertArrayHasKey($e['target'], $created, "{$e['table']}.{$e['column']} targets {$e['target']}, which no migration creates");
            if (is_array($e['scope'])) {
                $this->assertArrayHasKey($e['scope']['parent'], $created, "{$e['table']} is scoped through {$e['scope']['parent']}, which no migration creates");
            }
        }
    }

    public function test_the_registry_still_lists_every_pointer_known_when_it_was_written(): void
    {
        $registered = array_map(fn (array $r): string => "{$r['table']}.{$r['column']}", ProductPointers::columns());

        $this->assertSame([], array_values(array_diff([...self::KNOWN_CRM_PRODUCT, ...self::KNOWN_MASTER, ...self::KNOWN_OWN, ...self::KNOWN_OFFER], $registered)),
            'pointers that were in the registry when it was written and are gone now');
        $this->assertSame(array_unique($registered), $registered, 'a column is registered twice');
    }

    public function test_the_registry_has_the_shape_the_checker_relies_on(): void
    {
        $this->assertCount(11, ProductPointers::toCrmProduct(), '11 columns at crm_products.id');
        $this->assertCount(10, ProductPointers::toMaster(), '10 columns at g2g_product_master.id');
        $this->assertCount(21, ProductPointers::all());
        $this->assertCount(3, ProductPointers::masterOwn());
        // CHANGED ON PURPOSE (slice 15, WPA1, G-E2): the migration scan now also knows the numeric pointers at the About row, so columns() is 21 + the master row's own three + the
        // one column of ProductPointers::toOffer() (g2g_offer_readiness_log.offer_row_id). all(), the census and the checker's I1 are the 21 (22 until the Purchase Orders module went, 26 Nov 2026).
        $this->assertCount(25, ProductPointers::columns(), '21 + the master row\'s own three + the About row\'s one');
        $this->assertCount(1, ProductPointers::toOffer(), 'the readiness history is the one numeric pointer at the About row');
        $this->assertCount(19, ProductPointers::tables(), '11 + 10 columns live in 19 tables (crm_campaigns holds one of each family)');
        $this->assertCount(10, array_unique(array_column(ProductPointers::toCrmProduct(), 'table')), 'the 11 columns at crm_products.id are in 10 tables');

        foreach (ProductPointers::toCrmProduct() as $e) {
            $this->assertSame(ProductPointers::CRM_PRODUCTS, $e['target'], "{$e['table']}.{$e['column']}");
        }
        foreach (ProductPointers::toMaster() as $e) {
            $this->assertSame(ProductPointers::MASTER, $e['target'], "{$e['table']}.{$e['column']}");
            $this->assertSame('product_master_id', $e['column']);
        }

        $kinds = array_filter(ProductPointers::all(), fn (array $e): bool => isset($e['target_kind']));
        $this->assertSame(['crm_products.product_master_id'], array_values(array_map(fn (array $e): string => "{$e['table']}.{$e['column']}", $kinds)),
            'only the catalogue item\'s product line must point at a Product (level 1)');

        // The census takes one scope per table, so the entries of one table must agree.
        foreach (ProductPointers::tables() as $table) {
            $scopes = array_unique(array_map(fn (array $e): string => json_encode($e['scope']), ProductPointers::forTable($table)));
            $this->assertCount(1, $scopes, "{$table}: its pointers must be scoped to an organisation the same way");
        }
        foreach (ProductPointers::all() as $e) {
            foreach (['table', 'column', 'target', 'scope', 'measures', 'lines'] as $key) {
                $this->assertArrayHasKey($key, $e, "{$e['table']}.{$e['column']} lacks '{$key}'");
            }
            if ($e['lines']) {
                $this->assertSame(ProductPointers::CRM_PRODUCTS, $e['target'], 'rows per product id are counted for the pointers at the catalogue only');
            }
        }

        $this->assertSame(['amount'], ProductPointers::forTable('crm_opportunities')[0]['measures'], 'the value of a deal is summed in the census');

        $lineTables = array_values(array_unique(array_column(array_filter(ProductPointers::all(), fn (array $e): bool => $e['lines']), 'table')));
        foreach (['crm_quote_line_items', 'crm_sales_order_line_items', 'crm_invoice_line_items'] as $table) {
            $this->assertContains($table, $lineTables, "{$table} is one of the lines whose counts and totals a change must not move");
            $entry = ProductPointers::forTable($table)[0];
            $this->assertSame(['quantity', 'line_total'], $entry['measures'], "{$table} sums its quantity and its total");
        }
    }

    // ------------------------------------------------------------------ the guard can fail

    public function test_the_scan_names_the_file_and_the_column_when_a_registry_entry_is_missing(): void
    {
        $registry = array_values(array_filter(ProductPointers::columns(), fn (array $r): bool => ! ($r['table'] === 'crm_quote_line_items' && $r['column'] === 'product_id')));

        $missing = $this->unregistered(self::migrations(), $registry, [], self::NOT_COLUMNS);

        $this->assertCount(1, array_filter($missing, fn (string $line): bool => str_starts_with($line, '2026_11_24_080800_create_crm_quote_line_items_table.php: product_id')),
            'dropping crm_quote_line_items.product_id from the registry must be reported by file and column');
    }

    public function test_the_scan_names_the_file_when_a_const_driven_registry_entry_is_missing(): void
    {
        // Nine of the ten product_master_id migrations name their table in a constant: dropping one registered table must still be seen.
        $registry = array_values(array_filter(ProductPointers::columns(), fn (array $r): bool => ! ($r['table'] === 'gtm_playbooks' && $r['column'] === 'product_master_id')));

        $missing = $this->unregistered(self::migrations(), $registry, [], self::NOT_COLUMNS);

        $this->assertCount(1, array_filter($missing, fn (string $line): bool => str_contains($line, 'add_product_master_id_to_gtm_playbooks.php: product_master_id')),
            'dropping gtm_playbooks.product_master_id from the registry must be reported by file and column');
    }

    public function test_the_scan_catches_a_new_migration_with_an_unregistered_product_column(): void
    {
        $dir = sys_get_temp_dir() . '/product-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/2027_01_01_000000_create_crm_widgets_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        Schema::create('crm_widgets', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('sub_institute_id');
            $table->foreign('product_id')->references('id')->on('crm_products');
        });
    }
};
PHP);
        file_put_contents($dir . '/2027_01_02_000000_add_product_to_crm_gadgets.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    private const TABLE = 'crm_gadgets';
    public function up(): void
    {
        foreach (['product_master_id' => fn ($t) => $t->unsignedBigInteger('product_master_id')->nullable()] as $column => $define) {
            Schema::table(self::TABLE, $define);
        }
    }
};
PHP);
        file_put_contents($dir . '/2027_01_03_000000_raw_alter.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE `crm_sprockets` ADD COLUMN `alt_crm_product_id` BIGINT UNSIGNED NULL');
    }
};
PHP);
        file_put_contents($dir . '/2027_01_04_000000_foreign_key_only.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        // crm_quote_line_items is registered with product_id, but this migration works on crm_widgets2: a mention here is not covered by it.
        Schema::create('crm_widgets2', function ($table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreignId('quote_line_item')->constrained('crm_quote_line_items');
        });
    }
};
PHP);

        try {
            $missing = $this->unregistered($dir, ProductPointers::columns(), [], self::NOT_COLUMNS);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }

        $text = implode("\n", $missing);
        $this->assertStringContainsString('2027_01_01_000000_create_crm_widgets_table.php: product_id', $text, 'a new Blueprint column on a new table is named by file and column');
        $this->assertStringContainsString('2027_01_02_000000_add_product_to_crm_gadgets.php: product_master_id', $text, 'a column added in a loop to a table named by a constant is still reported');
        $this->assertStringContainsString('2027_01_03_000000_raw_alter.php: alt_crm_product_id', $text, 'a raw ALTER TABLE ... ADD COLUMN is read as well');
        $this->assertStringContainsString('2027_01_04_000000_foreign_key_only.php: product_id', $text, 'a table that is only the target of a foreign key does not cover a column added to another table');
        $this->assertCount(4, $missing);
    }

    // ------------------------------------------------------------------ the third family: pointers at the About row (slice 15, WPA1, G-E2)

    public function test_the_name_pattern_sees_the_numeric_pointer_at_the_about_row_and_not_the_text_offer_id(): void
    {
        $this->assertTrue(ProductPointers::looksLikeProductPointer('offer_row_id'), 'the readiness history and the master row hold the About row by its number');
        $this->assertTrue(ProductPointers::looksLikeProductPointer('alt_offer_row_id'));
        $this->assertFalse(ProductPointers::looksLikeProductPointer('offer_id'), 'the text offer id (P1, SCH-01) is not a row number: nothing can repoint it, it is documentation');
        $this->assertFalse(ProductPointers::looksLikeProductPointer('offer_row_id_note'), 'the pattern is anchored at the end of the name');
    }

    public function test_the_scan_names_the_file_when_the_readiness_history_pointer_is_missing_from_the_registry(): void
    {
        $registry = array_values(array_filter(ProductPointers::columns(), fn (array $r): bool => ! ($r['table'] === 'g2g_offer_readiness_log' && $r['column'] === 'offer_row_id')));

        $missing = $this->unregistered(self::migrations(), $registry, [], self::NOT_COLUMNS);

        $this->assertCount(1, array_filter($missing, fn (string $line): bool => str_starts_with($line, '2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers.php: offer_row_id')),
            'dropping g2g_offer_readiness_log.offer_row_id from the registry must be reported by file and column');
    }

    public function test_the_scan_catches_a_new_numeric_pointer_at_the_about_row_that_is_not_registered(): void
    {
        $dir = sys_get_temp_dir() . '/product-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/2027_03_01_000000_create_crm_offer_notes_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        Schema::create('crm_offer_notes', function ($table) {
            $table->id();
            $table->unsignedBigInteger('offer_row_id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('offer_id', 50)->nullable();
        });
    }
};
PHP);

        try {
            $missing = $this->unregistered($dir, ProductPointers::columns(), [], self::NOT_COLUMNS);
            $allowed = $this->unregistered($dir, ProductPointers::columns(), ['crm_offer_notes.offer_row_id' => 'a note filed by the About\'s number that dies with it by design'], self::NOT_COLUMNS);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }

        $this->assertSame(['2027_03_01_000000_create_crm_offer_notes_table.php: offer_row_id (tables read from the file: crm_offer_notes)'], $missing,
            'a new numeric pointer at the About row is named by file and column, and the text offer_id beside it is not');
        $this->assertSame([], $allowed, 'an allow-list entry with a reason covers it');
    }

    public function test_the_about_row_family_is_part_of_the_columns_the_scan_knows_but_not_of_the_census(): void
    {
        $registered = array_map(fn (array $r): string => "{$r['table']}.{$r['column']}", ProductPointers::columns());
        $this->assertSame([], array_values(array_diff(self::KNOWN_OFFER, $registered)));

        $census = array_map(fn (array $r): string => "{$r['table']}.{$r['column']}", ProductPointers::all());
        $this->assertSame([], array_values(array_intersect(self::KNOWN_OFFER, $census)), 'nothing merges two About rows, so the checker\'s I1 and the census stay the 22 pointers');
        $this->assertSame(self::KNOWN_OFFER, array_map(fn (array $r): string => "{$r['table']}.{$r['column']}", ProductPointers::toOffer()));
    }

    public function test_an_allow_list_entry_covers_exactly_its_own_table_and_column(): void
    {
        $dir = sys_get_temp_dir() . '/product-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/2027_02_01_000000_create_ai_trace_table.php', <<<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        Schema::create('ai_trace', function ($table) {
            $table->unsignedBigInteger('external_product_id')->nullable();
        });
    }
};
PHP);

        try {
            $without = $this->unregistered($dir, ProductPointers::columns(), [], []);
            $withEntry = $this->unregistered($dir, ProductPointers::columns(), ['ai_trace.external_product_id' => 'the supplier\'s own product number, not ours'], []);
            $wrongTable = $this->unregistered($dir, ProductPointers::columns(), ['ai_other.external_product_id' => 'the supplier\'s own product number, not ours'], []);
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }

        $this->assertCount(1, $without);
        $this->assertSame([], $withEntry, 'an allow-list entry for the table and column silences it');
        $this->assertCount(1, $wrongTable, 'an entry for another table does not');
    }
}
