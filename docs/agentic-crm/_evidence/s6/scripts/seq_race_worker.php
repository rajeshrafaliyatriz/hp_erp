<?php
/*
 * One competing process of seq_race_dev.php (dev connection `mysql`, MariaDB). usage:
 *   php seq_race_worker.php <key> <claims> <mode> <start file>
 * modes: claim       NumberSequence::claim() as production runs it (UPDATE ... LAST_INSERT_ID)
 *        claim-slow  the same, with a random pause after every statement (to widen the gaps between statements)
 *        racy-slow   a deliberately WRONG claim (read the counter, then write read+1, nothing held between the two) with the same pauses: it shows the
 *                    method can catch a race at all
 * Writes only rows whose key starts with `zz_probe:` in g2g_number_sequences.
 */
chdir('C:/Users/MILAN/Downloads/hp_erp');
require 'C:/Users/MILAN/Downloads/hp_erp/vendor/autoload.php';
$app = require 'C:/Users/MILAN/Downloads/hp_erp/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Numbering\NumberSequence;
use Illuminate\Support\Facades\DB;

[$script, $key, $claims, $mode, $startFile] = $argv;
if (! str_starts_with($key, 'zz_probe:')) {
    fwrite(STDERR, "refusing a key that is not a probe key\n");
    exit(1);
}
if (DB::getDefaultConnection() !== 'mysql') {
    fwrite(STDERR, "refusing: the default connection is not the dev connection\n");
    exit(1);
}
if ($mode !== 'claim') {
    DB::listen(function () {
        usleep(random_int(0, 1500));
    });
}

$deadline = microtime(true) + 90;
while (! is_file($startFile) && microtime(true) < $deadline) {
    usleep(500);
}

for ($i = 0; $i < (int) $claims; $i++) {
    if ($mode === 'racy-slow') {
        $row = DB::table('g2g_number_sequences')->where('seq_key', $key)->first();
        if ($row === null) {
            DB::table('g2g_number_sequences')->insertOrIgnore(['seq_key' => $key, 'next_value' => 1, 'updated_at' => now()]);
            $row = DB::table('g2g_number_sequences')->where('seq_key', $key)->first();
        }
        $n = (int) $row->next_value;
        DB::table('g2g_number_sequences')->where('seq_key', $key)->update(['next_value' => $n + 1]);
        echo $n, "\n";

        continue;
    }
    echo NumberSequence::claim($key, fn () => 1), "\n";
}
