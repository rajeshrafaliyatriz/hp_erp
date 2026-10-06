<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITEM 4a — TRI-STATE RIGHTS COLUMNS. G-SEC-06.
 *
 * Additive. No behaviour change: nothing reads these columns yet. The legacy
 * `can_*` tinyints stay and remain authoritative until the resolver is live
 * (§10 step 11 drops them, and only then).
 *
 * ─── WHY TRI-STATE ───────────────────────────────────────────────────────────
 * Today a `0` is indistinguishable from "no row". Both read as "not granted", so
 * a deliberate DENY cannot be expressed and cannot override a group GRANT. That
 * is the whole reason G-SEC-06 was raised.
 *
 * NULL / no row = INHERIT. Never "deny". Absence is not a decision.
 *
 * ─── THE PRECEDENCE RULE — implemented exactly as stated ─────────────────────
 *
 *     individual DENY  >  group DENY  >  individual ALLOW  >  group ALLOW
 *                      >  role default  >  deny
 *
 * Read it as: an explicit DENY always wins, at either level, before any ALLOW is
 * considered. Only when no row at either level expresses an opinion does the role
 * default apply; and if there is no role default, the answer is deny.
 *
 * The same shape is applied to BOTH tables so they are consistent — a rule that
 * exists on one table and not the other is not a rule.
 *
 * SCOPE is never individually overridable (A6). These columns carry action
 * rights only; data scope stays on the role.
 *
 * ─── ROLLBACK ────────────────────────────────────────────────────────────────
 * down() drops exactly these columns. Nothing reads them, and the legacy `can_*`
 * columns are untouched, so reverting is inert.
 */
return new class extends Migration
{
    /** view/add/edit/delete/dashboard, tri-state. NULL = inherit. */
    private const RIGHTS = ['view', 'add', 'edit', 'delete', 'dashboard'];

    public function up(): void
    {
        foreach (['tblgroupwise_rights_g2g', 'tblindividual_rights'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (self::RIGHTS as $r) {
                    $col = 'right_' . $r;
                    if (!Schema::hasColumn($table, $col)) {
                        // NULL is the default and means INHERIT - not deny.
                        $t->enum($col, ['allow', 'deny'])->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['tblgroupwise_rights_g2g', 'tblindividual_rights'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach (self::RIGHTS as $r) {
                $col = 'right_' . $r;
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, function (Blueprint $t) use ($col) {
                        $t->dropColumn($col);
                    });
                }
            }
        }
    }
};
