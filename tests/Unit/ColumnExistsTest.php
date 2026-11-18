<?php

namespace Tests\Unit;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ColumnExists::has() used to send `SHOW COLUMNS FROM t LIKE ?` with a bound parameter. The live MariaDB 10.1 rejects that form
 * (SQLSTATE 42000, error 1064) while dev's 10.11 accepts it, so every caller threw on live (defect D20, measured 2026-11-02).
 * These tests pin the statement shape and the portable path.
 */
class ColumnExistsTest extends TestCase
{
    public function test_the_mysql_statement_has_no_bound_placeholder(): void
    {
        $sql = ColumnExists::showColumnsLikeSql('g2g_research_runs', 'product_key', fn (string $v): string => "'" . $v . "'");

        $this->assertSame("SHOW COLUMNS FROM `g2g_research_runs` LIKE 'product_key'", $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function test_a_quote_in_the_column_name_is_escaped_by_the_pdo_quoter_not_concatenated_raw(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $sql = ColumnExists::showColumnsLikeSql('t', "o'brien", fn (string $v): string => (string) $pdo->quote($v));

        $this->assertSame("SHOW COLUMNS FROM `t` LIKE 'o''brien'", $sql);
    }

    public function test_backticks_in_the_table_name_are_removed(): void
    {
        $sql = ColumnExists::showColumnsLikeSql('t`; DROP TABLE x; --', 'c', fn (string $v): string => "'" . $v . "'");

        $this->assertSame("SHOW COLUMNS FROM `t; DROP TABLE x; --` LIKE 'c'", $sql);
    }

    public function test_has_and_all_answer_correctly_on_the_portable_path(): void
    {
        Schema::create('column_exists_probe', function ($t) {
            $t->id();
            $t->string('product_key')->nullable();
        });

        try {
            $this->assertTrue(ColumnExists::has('column_exists_probe', 'product_key'));
            $this->assertFalse(ColumnExists::has('column_exists_probe', 'no_such_column'));
            $this->assertContains('product_key', ColumnExists::all('column_exists_probe'));
        } finally {
            Schema::drop('column_exists_probe');
        }
    }
}
