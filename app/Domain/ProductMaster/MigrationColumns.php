<?php

namespace App\Domain\ProductMaster;

/**
 * The one reader of "which columns do the migrations give this table" (S15 WPA2-H3W3, 23 Nov 2026).
 *
 * Two users read the migration files through it, so they cannot drift apart: tests/Unit/OfferFieldsScanTest (every column of g2g_product_offers is mapped or
 * explained) and the W3 warning of ProductIntegrity / `product-master:check` (the columns of a table on the host against the columns its migrations declare).
 * It only reads files: no database, no framework helper, so a plain PHPUnit test can use it too. Same reading rules as tests/Unit/CatalogueFieldsScanTest:
 *
 *   - comments are removed first (documentation that names a column is not a definition of it)
 *   - a file is cut into segments at every table it names: a literal Schema::create / Schema::table name, a CREATE TABLE / ALTER TABLE name, or a `const TABLE`;
 *     the text before the first one belongs to the first one. A column belongs to the table of the segment it stands in, so a migration that works on two tables
 *     (the readiness migration also creates g2g_offer_readiness_log) gives each table only its own columns. A migration that names the table only through a
 *     variable is not read; the anchor lists of the tests are what notice that.
 *   - the columns of a segment are the Blueprint calls that define one ($table->string('sku'), ->decimal('unit_price', 15, 2), id(), timestamps(), softDeletes() ...),
 *     a raw ADD COLUMN, and a `const COLUMN` (the product_master_id migration names its column in a constant)
 *   - nothing is ever subtracted: a dropColumn in down() is not a drop, and a column a later migration removes stays declared (add it to the allow-list or fix the migration)
 */
final class MigrationColumns
{
    /** The Blueprint methods that define a column named by their first argument. */
    public const DEFINING_METHODS = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime', 'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignUlid',
        'foreignUuid', 'geometry', 'increments', 'integer', 'ipAddress', 'json', 'jsonb', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger', 'mediumText', 'set',
        'smallIncrements', 'smallInteger', 'string', 'text', 'time', 'timeTz', 'timestamp', 'timestampTz', 'tinyIncrements', 'tinyInteger', 'tinyText', 'ulid',
        'unsignedBigInteger', 'unsignedDecimal', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'uuid', 'year',
    ];

    /** The migrations folder of this application. */
    public static function directory(): string
    {
        return dirname(__DIR__, 3) . '/database/migrations';
    }

    /** The code of a PHP file with every comment removed. */
    public static function code(string $path): string
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
     * Every column the migrations of one folder define on one table.
     *
     * @return array<string, list<string>> column => the files that define it (a file name each)
     */
    public static function columns(string $dir, string $table): array
    {
        $found = [];
        foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $path) {
            $file = basename($path);
            foreach (self::segments(self::code($path)) as $segment) {
                if ($segment['table'] !== $table) {
                    continue;
                }
                foreach (self::columnsInText($segment['text']) as $column) {
                    if (! in_array($file, $found[$column] ?? [], true)) {
                        $found[$column][] = $file;
                    }
                }
            }
        }
        ksort($found);

        return $found;
    }

    /** @return list<string> the tables a piece of migration code works on, as far as they can be read from it */
    public static function tables(string $code): array
    {
        $tables = [];
        foreach (self::segments($code) as $segment) {
            $tables[$segment['table']] = true;
        }

        return array_keys($tables);
    }

    /**
     * The code cut at every table it names (see the class comment).
     *
     * @return list<array{table: string, text: string}>
     */
    public static function segments(string $code): array
    {
        $marks = [];
        foreach ([
            '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]/',
            '/(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|ALTER\s+TABLE)\s+`?([a-z0-9_]+)`?/i',
            '/\bconst\s+TABLE(?:_NAME)?\s*=\s*[\'"]([a-z0-9_]+)[\'"]/',
        ] as $pattern) {
            preg_match_all($pattern, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $hit) {
                $marks[] = ['at' => (int) $hit[0][1], 'table' => (string) $hit[1][0]];
            }
        }
        if ($marks === []) {
            return [];
        }
        usort($marks, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $out = [];
        foreach ($marks as $i => $mark) {
            $from = $i === 0 ? 0 : $mark['at'];
            $to = isset($marks[$i + 1]) ? $marks[$i + 1]['at'] : strlen($code);
            $out[] = ['table' => $mark['table'], 'text' => substr($code, $from, $to - $from)];
        }

        return $out;
    }

    /** @return list<string> the columns one piece of migration code defines */
    public static function columnsInText(string $code): array
    {
        $columns = [];

        // A Blueprint call that names a column: $table->string('sku', 100), ->decimal('unit_price', 15, 2), ->unsignedBigInteger('assigned_to') ...
        preg_match_all('/->\s*(?:' . implode('|', self::DEFINING_METHODS) . ')\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m);
        array_push($columns, ...$m[1]);

        // A Blueprint call that defines columns without naming them, or with a default name.
        if (preg_match('/->\s*id\(\s*\)/', $code) === 1) {
            $columns[] = 'id';
        }
        preg_match_all('/->\s*id\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m);
        array_push($columns, ...$m[1]);
        if (preg_match('/->\s*(?:timestamps|timestampsTz|nullableTimestamps)\(/', $code) === 1) {
            array_push($columns, 'created_at', 'updated_at');
        }
        if (preg_match('/->\s*softDeletes(?:Tz)?\(\s*\)/', $code) === 1) {
            $columns[] = 'deleted_at';
        }
        preg_match_all('/->\s*softDeletes(?:Tz)?\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m);
        array_push($columns, ...$m[1]);

        // Raw SQL: ALTER TABLE ... ADD COLUMN `x` ...
        preg_match_all('/ADD\s+COLUMN\s+`?([a-z][a-z0-9_]*)`?/i', $code, $m);
        array_push($columns, ...$m[1]);

        // A column named in a constant, the way 2026_12_11_160000_add_product_master_id_to_crm_products.php does it.
        preg_match_all('/\bconst\s+COLUMN\s*=\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $code, $m);
        array_push($columns, ...$m[1]);

        return array_values(array_unique($columns));
    }
}
