<?php

/*
 * One of the competing processes of NumberSequenceTest::test_two_processes_never_claim_the_same_number. It is NOT a test: the test starts two of these at
 * once against ONE sqlite database FILE (two real connections, which the in-memory test database cannot give), waits for the start file so that both begin
 * together, claims the key many times and prints every number it got, one per line.
 *
 *   php number_sequence_worker.php <database file> <start file> <key> <claims> [<seed above>]
 *
 * It must be started with APP_ENV=testing and DB_CONNECTION=sqlite in its environment (the test does that); the database file is the only thing it writes to.
 */

use App\Domain\Numbering\NumberSequence;
use Illuminate\Support\Facades\DB;

[$script, $file, $startFile, $key, $claims] = $argv;
$firstNumber = (int) ($argv[5] ?? 1);

$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $file]);
DB::purge('sqlite');
// Wait for the other writer instead of failing at once: this is a lock, not an error.
DB::statement('PRAGMA busy_timeout = 30000');

// A pause after every statement, so the gap between two statements of ONE claim is long enough for the other process to land in it. Without it the
// window is microseconds wide and a claim that reads after it writes (no lock held between the two) would almost never be caught.
DB::listen(function () {
    usleep(random_int(0, 1500));
});

$deadline = microtime(true) + 60;
while (! is_file($startFile) && microtime(true) < $deadline) {
    usleep(500);
}

for ($i = 0; $i < (int) $claims; $i++) {
    echo NumberSequence::claim($key, fn () => $firstNumber), "\n";
}
