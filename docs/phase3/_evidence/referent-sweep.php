<?php
/**
 * THE REFERENT SWEEP — "does any OTHER id column have more than one plausible
 * referent?", asked while it is cheap rather than after the next G-DATA-11.
 *
 * METHOD, and it is deliberately NOT an id-join:
 *
 *   G-DATA-11 taught that a join succeeding proves nothing. So a candidate table
 *   counts as PLAUSIBLE only if it satisfies BOTH:
 *     (1) COVERAGE   - every referenced value exists in it, and
 *     (2) TENANT AGREEMENT - where both sides carry sub_institute_id, they agree
 *                       on 100% of rows.
 *
 *   A column with TWO OR MORE surviving candidates is AMBIGUOUS and needs a
 *   declaration. A column with one is already unambiguous in the data - but if
 *   its NAME suggests a table that does not exist, it still gets declared,
 *   because the next person will search for that name.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tables Phase 3 created or gave new id columns to. */
const NEW_TABLES = [
    'competency', 'competency_kasba_item', 'competency_kasba_rating',
    'jobrole_competency_map', 'course_competency_map', 'course_jobrole_map',
    'certification_competency_map', 'g2g_notification', 'g2g_terminology',
    'g2g_event', 'g2g_event_delivery', 'g2g_audit_log', 'lms_assignments',
];

/** For each column suffix, every table a reasonable person might mean. */
const CANDIDATES = [
    'competency_id' => ['competency', 's_users_skills', 'master_skills'],
    'item_id'       => ['s_users_skills', 'master_skills', 'competency_kasba_item'],
    'kasba_item_id' => ['competency_kasba_item'],
    'jobrole_id'    => ['s_user_jobrole', 's_jobrole'],
    'course_id'     => ['sub_std_map', 'ai_course_outlines'],
    'user_id'       => ['tbluser', 'users'],
    'assessor_id'   => ['tbluser', 'users'],
    'actor_id'      => ['tbluser', 'users'],
    'created_by'    => ['tbluser', 'users'],
    'updated_by'    => ['tbluser', 'users'],
    'head_user_id'  => ['tbluser', 'users'],
    'event_id'      => ['g2g_event'],
    'certification_type_id' => ['certification_type'],
];

echo "\n================ REFERENT SWEEP ================\n\n";

$ambiguous = []; $unambiguous = []; $empty = []; $misnamed = [];

foreach (NEW_TABLES as $table) {
    if (!Schema::hasTable($table)) continue;

    foreach (DB::select("DESCRIBE `$table`") as $col) {
        $name = $col->Field;
        if ($name === 'id' || !isset(CANDIDATES[$name])) continue;

        $values = DB::table($table)->whereNotNull($name)->where($name, '!=', 0)
            ->distinct()->pluck($name)->all();

        if ($values === []) {
            $empty[] = "$table.$name";
            continue;
        }

        $holderHasTenant = Schema::hasColumn($table, 'sub_institute_id');
        $survivors = [];

        foreach (CANDIDATES[$name] as $cand) {
            if (!Schema::hasTable($cand)) continue;

            // (1) COVERAGE
            $found = DB::table($cand)->whereIn('id', $values)->count();
            if ($found !== count($values)) continue;

            // (2) TENANT AGREEMENT
            if ($holderHasTenant && Schema::hasColumn($cand, 'sub_institute_id')) {
                $total = DB::table("$table as h")->join("$cand as c", 'c.id', '=', "h.$name")
                    ->whereNotNull("h.$name")->where("h.$name", '!=', 0)->count();
                $agree = DB::table("$table as h")->join("$cand as c", 'c.id', '=', "h.$name")
                    ->whereNotNull("h.$name")->where("h.$name", '!=', 0)
                    ->whereColumn('c.sub_institute_id', '=', 'h.sub_institute_id')->count();
                if ($total > 0 && $agree !== $total) continue;
            }

            $survivors[] = $cand;
        }

        $row = [$table, $name, count($values), $survivors];
        if (count($survivors) > 1)      $ambiguous[] = $row;
        elseif (count($survivors) === 1) $unambiguous[] = $row;
        else                             $misnamed[] = $row;
    }
}

echo "⚠ AMBIGUOUS — more than one candidate survives BOTH tests. DECLARE THESE.\n\n";
if (!$ambiguous) echo "   (none)\n";
foreach ($ambiguous as [$t, $c, $n, $s]) {
    printf("   %-30s.%-22s %4d distinct -> %s\n", $t, $c, $n, implode(' | ', $s));
}

echo "\nUNAMBIGUOUS in the data — one survivor.\n\n";
foreach ($unambiguous as [$t, $c, $n, $s]) {
    printf("   %-30s.%-22s %4d distinct -> %s\n", $t, $c, $n, $s[0]);
}

echo "\nNO SURVIVOR — coverage or tenant agreement failed for every candidate.\n\n";
if (!$misnamed) echo "   (none)\n";
foreach ($misnamed as [$t, $c, $n, $s]) {
    printf("   %-30s.%-22s %4d distinct -> NONE\n", $t, $c, $n);
}

echo "\nNOT YET TESTABLE — column is empty, so the data cannot settle it.\n";
echo "   " . (count($empty) ? implode(', ', $empty) : '(none)') . "\n";
echo "\n   An EMPTY column is the most dangerous case: it has no wrong answer yet,\n";
echo "   which is exactly the state `competency_id` was in before X-20.\n\n";
