<?php
/**
 * L-05 — put the rule AT THE COLUMN, not only in the register.
 *
 * **A rule in a document is remembered. A rule at the column is met.**
 *
 * WHAT THIS IS: a COMMENT only. No data changes, no type changes, no nullability
 * change. The full definition is restated verbatim from information_schema so the
 * ALTER cannot silently reshape the column - restating a definition from memory is
 * how a MODIFY changes something nobody intended.
 *
 *   before : enum('Active','Inactive') NULL DEFAULT NULL, utf8mb4/utf8mb4_unicode_ci, comment ''
 *   after  : identical, plus the comment
 *
 * WHY: `where status = 'Active'` is exactly what L-05's row asked for, and it
 * would have removed 1,197 rows - 23% of the library - that nobody ever marked.
 * There is no deactivated skill to exclude: `Inactive` is a valid enum value with
 * ZERO rows. The filter would have eaten NULLs, not deactivations.
 *
 * 103 columns in this schema already carry comments, so this is the established
 * way to say something here.
 *
 *   php L-05-status-column-comment.php --dry
 *   php L-05-status-column-comment.php
 *   php L-05-status-column-comment.php --rollback
 */
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const TABLE = 's_users_skills';
const COL   = 'status';

$comment = 'L-05: filter with status != \'Inactive\', NEVER status = \'Active\'. '
         . 'NULL means nobody marked this row, not that it is inactive - 1,197 of 5,171 are NULL '
         . 'and 0 are Inactive. status = \'Active\' would silently drop 23% of the library.';

$dry      = in_array('--dry', $argv, true);
$rollback = in_array('--rollback', $argv, true);

// ── READ THE DEFINITION, never restate it from memory ───────────────────────
$c = DB::table('information_schema.columns')
    ->where('table_schema', env('DB_DATABASE'))
    ->where('table_name', TABLE)->where('column_name', COL)
    ->first(['column_type', 'is_nullable', 'column_default', 'column_comment', 'character_set_name', 'collation_name']);

if (!$c) { exit("REFUSING: column not found.\n"); }

printf("current definition:\n  type=%s null=%s default=%s charset=%s collate=%s\n  comment=%s\n\n",
    $c->column_type, $c->is_nullable, var_export($c->column_default, true),
    $c->character_set_name, $c->collation_name,
    $c->column_comment === '' ? '(none)' : '"' . substr($c->column_comment, 0, 40) . '..."');

$target = $rollback ? '' : $comment;

$sql = sprintf(
    "ALTER TABLE `%s` MODIFY COLUMN `%s` %s CHARACTER SET %s COLLATE %s %s DEFAULT %s COMMENT ?",
    TABLE, COL, $c->column_type, $c->character_set_name, $c->collation_name,
    $c->is_nullable === 'YES' ? 'NULL' : 'NOT NULL',
    // information_schema returns the STRING 'NULL' for a NULL default on some
    // MySQL versions, not PHP null. Quoting that produces DEFAULT 'NULL' - an
    // invalid enum value, which is exactly what MySQL refused on the first run.
    // THE DRY RUN PRINTED default='NULL' AND I RAN THE APPLY ANYWAY: a dry run
    // whose output nobody reads is not a safeguard.
    ($c->column_default === null || $c->column_default === 'NULL') ? 'NULL' : "'" . $c->column_default . "'"
);

printf("BLAST RADIUS\n");
printf("  rows changed        : 0 - this is a comment, not data\n");
printf("  type/null/default   : restated VERBATIM from information_schema, unchanged\n");
printf("  reversible          : yes, --rollback restores an empty comment\n");
printf("  statement           : %s\n\n", $sql);

if ($dry) { exit("--dry: nothing written.\n"); }

$rowsBefore = DB::table(TABLE)->count();
$nullBefore = DB::table(TABLE)->whereNull(COL)->count();

DB::statement($sql, [$target]);

$after = DB::table('information_schema.columns')
    ->where('table_schema', env('DB_DATABASE'))
    ->where('table_name', TABLE)->where('column_name', COL)
    ->first(['column_type', 'is_nullable', 'column_default', 'column_comment']);

$rowsAfter = DB::table(TABLE)->count();
$nullAfter = DB::table(TABLE)->whereNull(COL)->count();

printf("VERIFY\n");
printf("  type unchanged      : %s\n", $after->column_type === $c->column_type ? 'yes' : '*** CHANGED ***');
printf("  nullable unchanged  : %s\n", $after->is_nullable === $c->is_nullable ? 'yes' : '*** CHANGED ***');
printf("  rows unchanged      : %d -> %d %s\n", $rowsBefore, $rowsAfter, $rowsBefore === $rowsAfter ? '' : '*** DATA MOVED ***');
printf("  NULLs unchanged     : %d -> %d %s\n", $nullBefore, $nullAfter, $nullBefore === $nullAfter ? '' : '*** NULLS MOVED ***');
printf("  comment now         : %s\n", $after->column_comment === '' ? '(none)' : substr($after->column_comment, 0, 62) . '...');
printf("\nrollback: php %s --rollback\n", basename(__FILE__));
