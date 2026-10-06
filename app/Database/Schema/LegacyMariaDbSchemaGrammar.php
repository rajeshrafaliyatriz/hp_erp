<?php

namespace App\Database\Schema;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;

/**
 * Schema introspection that works on MariaDB before 10.2.
 *
 * ── THE PROBLEM ─────────────────────────────────────────────────────────────
 *
 * Laravel's MySqlGrammar::compileColumns() selects `generation_expression` from
 * information_schema.columns. That column arrived in MariaDB 10.2. On an older
 * engine the introspection query itself errors:
 *
 *   SQLSTATE[42S22]: Column not found: 1054
 *   Unknown column 'generation_expression' in 'field list'
 *
 * Every caller of Schema::hasColumn(), getColumnListing() or getColumns() dies
 * with it. Measured on the two hosts this application runs against:
 *
 *   202.47.117.220  MariaDB 10.11.9  -> the column exists, everything works
 *   128.199.17.97   MariaDB 10.1.48  -> it does not, everything throws
 *
 * Which is exactly why this went unnoticed for so long: the default development
 * database is the new one.
 *
 * Note that `Schema::hasTable()` is NOT affected - it compiles against
 * information_schema.tables and never mentions the column. Only the three
 * column-level calls above are. A comment elsewhere in this codebase asserts
 * the opposite; it is wrong, and it is corrected where it sits.
 *
 * ── WHY A GRAMMAR AND NOT THIRTY EDITS ──────────────────────────────────────
 *
 * App\Models\Concerns\SkipsGuardableColumnCheck already removes the commonest
 * trigger - Eloquent's mass-assignment guard - from the models that need it.
 * But roughly thirty places call Schema:: introspection directly, spread across
 * the AI, Competency, custom-module and user-import code, and more will be
 * written. Patching each is a treadmill; correcting the query once is not.
 *
 * ── WHY THIS IS SAFE ────────────────────────────────────────────────────────
 *
 * The override is INERT on a modern server: information_schema is asked once
 * whether it actually has the column, and any server that does is handed
 * straight to the parent implementation, byte for byte.
 *
 * On an old server the only difference is that `expression` comes back as an
 * empty string rather than a real value. MySqlProcessor::processColumns() reads
 * it solely to decide whether a column is generated, so an empty string yields
 * `generation => null` - which is the truthful answer from an engine that does
 * not report generated columns here at all. The column list, types, nullability,
 * defaults, comments and ordering are unchanged.
 */
class LegacyMariaDbSchemaGrammar extends MySqlGrammar
{
    /** Resolved once per grammar instance; a server does not grow the column. */
    private ?bool $lacksGenerationExpression = null;

    /**
     * Compile the query to determine a table's columns.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table)
    {
        if (! $this->lacksGenerationExpression()) {
            return parent::compileColumns($schema, $table);
        }

        /*
         * The parent's query with one substitution: a literal empty string
         * stands in for the column this engine does not have, so the result set
         * keeps the exact shape every caller downstream expects.
         */
        return sprintf(
            'select column_name as `name`, data_type as `type_name`, column_type as `type`, '
            .'collation_name as `collation`, is_nullable as `nullable`, '
            .'column_default as `default`, column_comment as `comment`, '
            ."'' as `expression`, extra as `extra` "
            .'from information_schema.columns where table_schema = %s and table_name = %s '
            .'order by ordinal_position asc',
            $schema ? $this->quoteString($schema) : 'schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Whether this server's information_schema.columns lacks generation_expression.
     *
     * THE COLUMN IS ASKED FOR DIRECTLY, not inferred from a version string, and
     * that is a correction rather than a preference. The first version of this
     * method parsed Connection::getServerVersion() and looked for "mariadb" in
     * it - but PDO::ATTR_SERVER_VERSION returns a bare "10.1.48" with no vendor
     * tag, while `select version()` returns "10.1.48-MariaDB". The check
     * therefore never matched, the override silently did nothing, and the
     * introspection query still threw on the one server it was written for.
     *
     * Version numbers are a proxy for the question anyway, and a leaky one:
     * MariaDB gained the column in 10.2, MySQL in 5.7, and MySQL 8 reports an
     * "8.0.x" that sorts below MariaDB's "10.x". Asking information_schema
     * about itself answers the real question in one cheap query, cached for the
     * life of the grammar, and cannot be wrong about a vendor it has not met.
     */
    private function lacksGenerationExpression(): bool
    {
        if ($this->lacksGenerationExpression !== null) {
            return $this->lacksGenerationExpression;
        }

        try {
            $row = $this->connection->selectOne(
                'select count(*) as c from information_schema.columns '
                ."where table_schema = 'information_schema' and table_name = 'COLUMNS' "
                ."and column_name = 'GENERATION_EXPRESSION'"
            );

            return $this->lacksGenerationExpression = ((int) ($row->c ?? 0) === 0);
        } catch (\Throwable $e) {
            /*
             * Cannot tell: assume modern, and let the parent's query raise the
             * real error. Guessing "old" would strip generated-column
             * information from every healthy server on the strength of one
             * failed probe.
             */
            return $this->lacksGenerationExpression = false;
        }
    }
}
