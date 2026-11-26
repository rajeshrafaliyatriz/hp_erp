<?php

namespace Tests\Unit;

use App\Domain\ProductMaster\ProductMerger;
use App\Domain\ProductMaster\ProductPointers;
use PHPUnit\Framework\TestCase;

/**
 * The guard behind the pair merge (slice 15, track P2-D). ProductMerger walks ProductPointers::toMaster(), so a pointer at a master row that is added to the registry is
 * moved without anybody remembering it; what a walk cannot know is which pointers sit in a UNIQUE key (a plain UPDATE would then break the merge) and which pointer must
 * NOT move. This file reads that from the migrations and from a hand-written anchor:
 *
 *   1. every registered pointer at a master row is either repointed or left alone with a written reason, and the anchor below says which, so a registry row added later
 *      fails HERE until its handling has been decided;
 *   2. every unique key in a migration that holds such a pointer is declared in ProductMerger::uniqueKeys() with the OTHER columns of the key (a row of the husk that
 *      says what the survivor already says is retired instead of moved);
 *   3. nothing is declared that no migration has.
 *
 * Plain PHPUnit, no database: it only reads files, like tests/Unit/ProductRepointerScanTest.php (the same guard for the eleven pointers at a catalogue row).
 */
class ProductMergerScanTest extends TestCase
{
    /**
     * Every pointer at a master row known when the merge was written, listed by hand: the independent anchor. "table.column" => [moves?, the OTHER columns of the unique
     * key it sits in]. Nine move; the Product pointer of the catalogue row does not.
     */
    private const KNOWN = [
        'crm_leads.product_master_id' => [true, []],
        'crm_opportunities.product_master_id' => [true, []],
        'crm_campaigns.product_master_id' => [true, []],
        'crm_products.product_master_id' => [false, []],
        'g2g_company_opportunities.product_master_id' => [true, []],
        'g2g_product_claims.product_master_id' => [true, ['sub_institute_id', 'claim_hash']],
        'g2g_research_runs.product_master_id' => [true, []],
        'gtm_campaigns.product_master_id' => [true, []],
        'gtm_outreach_messages.product_master_id' => [true, []],
        'gtm_playbooks.product_master_id' => [true, []],
    ];

    private static function migrations(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /** @return list<string> */
    private function registered(): array
    {
        return array_map(fn (array $e): string => $e['table'] . '.' . $e['column'], ProductPointers::toMaster());
    }

    // ---- the registry against the merge ----

    public function test_every_registered_pointer_is_repointed_or_left_alone_with_a_reason_and_the_anchor_agrees(): void
    {
        $registered = $this->registered();
        $known = array_keys(self::KNOWN);
        sort($registered);
        sort($known);
        $this->assertSame($known, $registered, 'ProductPointers::toMaster() and the anchor in this test must list the same pointers. A pointer was added or removed: decide what the merge does with it (a plain move, a unique key, or left alone with a reason), then change the anchor.');

        $handling = ProductMerger::handling();
        $this->assertEqualsCanonicalizing($registered, array_keys($handling), 'the merge says what it does with every registered pointer, and with nothing else');
        foreach ($handling as $key => $how) {
            [$moves, $unique] = self::KNOWN[$key];
            $this->assertSame($moves, $how['moves'], "{$key}: the anchor and the merge agree on whether it moves");
            $this->assertSame($unique, $how['unique'], "{$key}: the unique key the merge knows about is the one the anchor lists");
            if ($how['moves']) {
                $this->assertNull($how['reason'], "{$key}: a pointer that moves has no excuse");
            } else {
                $this->assertGreaterThanOrEqual(30, strlen((string) $how['reason']), "{$key}: a pointer the merge leaves alone needs a written reason a reviewer can disagree with");
            }
        }
        $this->assertSame(['crm_products.product_master_id'], array_keys(array_filter($handling, fn (array $how): bool => ! $how['moves'])), 'the only pointer left alone is the one that names the Product');
        $this->assertCount(9, ProductMerger::repointed());
    }

    public function test_what_is_left_alone_and_what_is_declared_are_real_pointers_of_the_registry(): void
    {
        $registered = $this->registered();
        foreach (array_keys(ProductMerger::leftAlone()) as $key) {
            $this->assertContains($key, $registered, "{$key} is listed as left alone but is not a registered pointer at a master row");
        }
        foreach (array_keys(ProductMerger::uniqueKeys()) as $key) {
            $this->assertContains($key, $registered, "{$key} has a declared unique key but is not a registered pointer at a master row");
        }
    }

    // ---- the migrations against the declarations ----

    /**
     * Every unique key in the files of one folder that contains a registered pointer at a master row, as findings.
     *
     * @return array{missing: list<string>, found: list<string>}
     */
    private function scan(string $dir): array
    {
        $missing = [];
        $found = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $code = $this->code($path);
            $tables = $this->ownTables($code);
            foreach ($this->uniqueKeys($code) as $columns) {
                foreach (ProductPointers::toMaster() as $e) {
                    if (! in_array($e['table'], $tables, true) || ! in_array($e['column'], $columns, true)) {
                        continue;
                    }
                    $key = $e['table'] . '.' . $e['column'];
                    $others = array_values(array_diff($columns, [$e['column']]));
                    $found[] = $key . ' [' . implode(', ', $columns) . ']';
                    $declared = ProductMerger::uniqueKeys()[$key] ?? null;
                    if ($declared === null || array_values(array_diff($declared, $others)) !== [] || array_values(array_diff($others, $declared)) !== []) {
                        $missing[] = basename($path) . ": {$key} is in the unique key (" . implode(', ', $columns) . ') but ProductMerger::uniqueKeys() declares ' . ($declared === null ? 'nothing' : '(' . implode(', ', $declared) . ')');
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
        $this->assertNotEmpty($scan['found'], 'the scan found the unique key of the claims table, so it cannot pass by looking at nothing');
    }

    public function test_the_scan_finds_the_one_unique_key_that_exists_and_every_declaration_has_a_real_key_behind_it(): void
    {
        $scan = $this->scan(self::migrations());
        $found = array_values(array_unique(array_map(fn (string $f): string => explode(' [', $f)[0], $scan['found'])));

        $this->assertSame(['g2g_product_claims.product_master_id'], $found, 'the only unique key that includes a pointer at a master row today');
        foreach (array_keys(ProductMerger::uniqueKeys()) as $key) {
            $this->assertContains($key, $found, "{$key} is declared as being in a unique key, but no migration has one: a stale declaration");
        }
    }

    public function test_the_scan_can_fail_a_new_unique_key_on_a_pointer_that_nobody_declared_is_reported(): void
    {
        $dir = sys_get_temp_dir() . '/pm-merger-scan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/2099_01_01_000000_add_unique_to_leads.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('crm_leads', function (\$table) {\n            \$table->unique(['sub_institute_id', 'product_master_id', 'email'], 'lead_pair');\n        });\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000001_unique_on_the_pointer_alone.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('gtm_playbooks', function (\$table) {\n            \$table->unsignedBigInteger('product_master_id')->unique();\n        });\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000002_raw_sql.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        DB::statement('ALTER TABLE `gtm_campaigns` ADD UNIQUE KEY `c_pair` (`product_master_id`, `name`)');\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000003_a_declared_key_with_other_columns.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('g2g_product_claims', function (\$table) {\n            \$table->unique(['sub_institute_id', 'product_master_id', 'source_url'], 'claims_other');\n        });\n    }\n};\n");
            file_put_contents($dir . '/2099_01_01_000004_unrelated.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('crm_widgets', function (\$table) {\n            \$table->unique(['product_master_id', 'name']);\n        });\n    }\n};\n");

            $scan = $this->scan($dir);

            $text = implode("\n", $scan['missing']);
            $this->assertStringContainsString('crm_leads.product_master_id is in the unique key (sub_institute_id, product_master_id, email)', $text, 'a Blueprint unique with three columns');
            $this->assertStringContainsString('gtm_playbooks.product_master_id is in the unique key (product_master_id)', $text, 'a unique on the pointer column alone, chained on its definition');
            $this->assertStringContainsString('gtm_campaigns.product_master_id is in the unique key (product_master_id, name)', $text, 'a raw ALTER TABLE ... UNIQUE KEY');
            $this->assertStringContainsString('g2g_product_claims.product_master_id is in the unique key (sub_institute_id, product_master_id, source_url) but ProductMerger::uniqueKeys() declares (sub_institute_id, claim_hash)', $text, 'a key on a declared pointer with different other columns');
            $this->assertStringNotContainsString('crm_widgets', $text, 'a table that is not in the registry is not this guard\'s business');
            $this->assertCount(4, $scan['missing']);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    // ---- reading migrations (the same way tests/Unit/ProductRepointerScanTest.php does) ----

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
