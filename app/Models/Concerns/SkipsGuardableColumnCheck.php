<?php

namespace App\Models\Concerns;

/**
 * Let a guarded model mass-assign without introspecting the table's columns.
 *
 * ── THE PROBLEM THIS SOLVES (audit F-68) ────────────────────────────────────
 *
 * A model that uses `$guarded` and not `$fillable` makes Eloquent check, on the
 * first mass-assignment, whether each attribute is a real column - so it can
 * guard columns that do not physically exist. It answers that with
 * `getColumnListing()`, whose query selects `generation_expression` from
 * information_schema.
 *
 * That column arrived in MariaDB 10.2. The live host (128.199.17.97) runs
 * MariaDB 10.1.48, where it does not exist, so the introspection query itself
 * errors:
 *
 *   SQLSTATE[42S22]: Unknown column 'generation_expression' in 'field list'
 *
 * The consequence was not subtle: every `Model::create()` in the v2 talent
 * modules threw a 500 on live. It is why onboarding, offboarding and
 * performance had zero rows there - the create path had never once succeeded.
 * The application's own default database runs MariaDB 10.11 and does not hit
 * this, which is how it went unnoticed.
 *
 * ── WHY THIS IS SAFE, AND WHY NOT JUST ADD $fillable ────────────────────────
 *
 * `isGuardableColumn()` exists only to treat a NON-EXISTENT column as guarded.
 * For every column that really is on the table the answer is "yes, guardable",
 * so returning true unconditionally changes nothing for any real attribute -
 * `$guarded = ['id']` still guards id and still mass-assigns everything else,
 * exactly as before. The only behaviour that changes is for a key that is not a
 * column at all, and no caller passes one.
 *
 * An explicit `$fillable` would also avoid the introspection, but it would mean
 * re-listing every column on every model and keeping those lists in step with
 * the schema forever - a larger change with more to get wrong. This preserves
 * the models' existing guard rules untouched and removes only the one query the
 * old engine cannot answer.
 */
trait SkipsGuardableColumnCheck
{
    /**
     * Whether a given column can be mass-assigned.
     *
     * The parent implementation calls getColumnListing() to decide; that query
     * is what fails on MariaDB 10.1. Every real column is guardable, so this
     * answers true without asking the database.
     *
     * @param  string  $key
     * @return bool
     */
    protected function isGuardableColumn($key)
    {
        return true;
    }
}
