<?php
/**
 * THE 9-BOX'S MISSING HALF - performance ratings in the tenant that already has
 * the capability axis.
 *
 * The join was built and draws nothing anywhere, because the two axes live in
 * different tenants: tenant 1 has the performance reviews, tenant 3 has the 23
 * `jobrole_competency_map` rows and the 34 KASBA ratings. **The grid was correct
 * and undemonstrable.**
 *
 * This adds reviews for the tenant-3 seeded employees, so the diagnosis's single
 * illustration becomes something that can be opened rather than argued.
 *
 * RATINGS ARE CHOSEN TO PUT PEOPLE IN DIFFERENT BOXES, DELIBERATELY. A grid where
 * everyone lands in one cell demonstrates nothing. Meera is measured and strong;
 * Vikram is measured with gaps; Divya has no capability measurement at all and
 * MUST come back unplaced - she is the row that proves the grid does not invent a
 * position for someone nobody assessed.
 *
 * Registered in SEED-REGISTER-2026-08-11.md. Nothing existing is touched.
 */
require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const TENANT = 3;

echo "\n================ 9-BOX: THE PERFORMANCE AXIS FOR TENANT 3 ================\n\n";

$people = [
    // email prefix        manager_rating  why this value
    ['meera.pillai',       4.4, 'measured and fully met - high/high'],
    ['vikram.sethi',       3.1, 'measured with two gaps - medium performance, low-ish capability'],
    ['joseph.mathew',      2.2, 'two gaps and one unassessed - low band'],
    ['anjali.bose',        3.8, 'partially measured'],
    ['imran.sheikh',       4.1, 'measured and met'],
    ['divya.nair',         3.6, 'NO capability measurement - must return UNPLACED'],
];

// s_performance_reviews requires a cycle. Use the tenant's own if it has one;
// otherwise create ONE, registered like everything else.
$cycleId = DB::table('s_performance_cycles')->where('sub_institute_id', TENANT)->value('id');
$cycleMade = null;
if (!$cycleId) {
    $cycleId = DB::table('s_performance_cycles')->insertGetId([
        'sub_institute_id' => TENANT,
        'name'             => 'FY2026 Review Cycle',
        'status'           => 'active',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    $cycleMade = $cycleId;
    printf("  cycle created: #%d (tenant had none)
", $cycleId);
}

$made = [];
$skipped = [];

foreach ($people as [$prefix, $rating, $why]) {
    $email = $prefix . '@healthcare.g2g';
    $user = DB::table('tbluser')->where('email', $email)->where('sub_institute_id', TENANT)->first(['id']);
    if (!$user) { $skipped[] = "$email (no such user)"; continue; }

    // NOTHING OVERWRITTEN. A person who already has a rated review keeps it.
    $existing = DB::table('s_performance_reviews')
        ->where('user_id', $user->id)->where('sub_institute_id', TENANT)
        ->whereNotNull('manager_rating')->exists();
    if ($existing) { $skipped[] = "$email (already rated)"; continue; }

    $id = DB::table('s_performance_reviews')->insertGetId([
        'sub_institute_id' => TENANT,
        'user_id'          => $user->id,
        'cycle_id'         => $cycleId,
        'status'           => 'completed',
        'manager_rating'   => $rating,
        'overall_rating'   => $rating,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
    $made[] = $id;
    printf("  #%-5d %-28s rating %.1f   %s\n", $id, $prefix, $rating, $why);
}

if ($skipped) {
    echo "\n  skipped (nothing overwritten): " . implode(', ', $skipped) . "\n";
}

$reg = __DIR__ . '/../_changes/SEED-REGISTER-2026-08-11.md';
if (is_file($reg) && $made) {
    file_put_contents($reg, "\n## 9-box performance axis (added " . date('Y-m-d') . ")\n\n"
        . "| Table | Rows | IDs |\n|---|---:|---|\n"
        . "| `s_performance_reviews` | " . count($made) . " | " . implode(', ', $made) . " |\n"
        . "\nAdded so the 9-box has both axes in ONE tenant. Removing them removes the\n"
        . "demonstration, not the capability.\n", FILE_APPEND);
    echo "\n  registered: " . count($made) . " rows\n";
}
