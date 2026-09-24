<?php
/**
 * EVERY GUARDED MODEL MUST MASS-ASSIGN ON MARIADB 10.1.
 *
 *   php Docs/hrit-audit/_evidence/probe-mass-assignment-old-mariadb.php
 *
 * WHY THIS EXISTS
 *
 * A model that declares $guarded and no $fillable makes Eloquent ask the
 * database for the table's column list on its first mass-assignment, so it can
 * treat a NON-EXISTENT column as guarded. That query selects
 * `generation_expression` from information_schema.columns - a column that
 * arrived in MariaDB 10.2.
 *
 *   202.47.117.220  MariaDB 10.11.9  has it   -> every model works
 *   128.199.17.97   MariaDB 10.1.48  has NOT  -> every such model 500s
 *
 * So Leave Configuration's Approval Workflow and Roles & Access tabs died with
 *
 *   SQLSTATE[42S22]: Column not found: 1054
 *   Unknown column 'generation_expression' in 'field list'
 *
 * on the customer's server while being perfectly healthy on ours. The fault is
 * not in the leave code at all - LeaveWorkflowApiController merely calls
 * firstOrCreate(), and create() is what trips the guard.
 *
 * THE TRAP THIS PROBE HAD TO BE WRITTEN AROUND
 *
 * GuardsAttributes::$guardableColumns (framework line 33) is a STATIC cache
 * keyed by class name, populated on first use and never invalidated per
 * connection. Touching the healthy host first therefore answers for the broken
 * one, and the check passes while the bug is fully present. My first attempt at
 * this did exactly that and reported a clean result. So:
 *
 *   - the OLD host is queried FIRST, before anything warms the cache
 *   - the cache is cleared between models anyway, via reflection
 *
 * A probe that cannot see the failure it was written for is worse than none.
 *
 * NOTHING IS WRITTEN. fill() on an unsaved instance runs the guard check - the
 * thing under test - and performs no insert or update.
 */

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The connection whose engine actually has the bug. */
const OLD_HOST = 'live';

/**
 * Wipe Eloquent's static column cache.
 *
 * Without this the second model in a run could answer from the first model's
 * entry only if they shared a class - they do not - but the cache also survives
 * a connection switch for the SAME class, which is the masking case. Cleared
 * defensively so ordering can never produce a false pass.
 */
function clearGuardableCache(): void
{
    $ref = new ReflectionClass(Model::class);
    foreach ($ref->getTraits() as $trait) {
        if (!$trait->hasProperty('guardableColumns')) {
            continue;
        }
        $prop = $trait->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}

$listFile = __DIR__ . '/guarded-models.txt';
if (!is_file($listFile)) {
    fwrite(STDERR, "missing model list: $listFile\n");
    exit(2);
}

$classes = array_values(array_filter(array_map('trim', file($listFile))));

printf("\n================ Mass assignment on %s ================\n", OLD_HOST);
try {
    $version = DB::connection(OLD_HOST)->selectOne('select version() v')->v;
    $hasCol = DB::connection(OLD_HOST)->selectOne(
        'select count(*) c from information_schema.columns
          where table_schema = "information_schema" and table_name = "COLUMNS"
            and column_name = "GENERATION_EXPRESSION"'
    )->c;
} catch (\Throwable $e) {
    fwrite(STDERR, "cannot reach the " . OLD_HOST . " connection: " . $e->getMessage() . "\n");
    exit(2);
}

printf("  engine: %s\n", $version);
printf("  generation_expression present: %s\n", $hasCol ? 'YES' : 'NO');

/*
 * If the old host ever gets upgraded, this probe stops proving anything - every
 * model would pass whether or not it carries the trait. Say so rather than
 * reporting a green run that means nothing.
 */
if ($hasCol) {
    printf("\n  WARNING: this connection now HAS generation_expression, so it can no\n");
    printf("           longer demonstrate the fault. The assertions below still run,\n");
    printf("           but a pass no longer proves the trait is doing anything.\n");
}

printf("  %d model(s) under test\n\n", count($classes));

$pass = 0;
$fail = 0;
$skip = 0;

foreach ($classes as $class) {
    if (!class_exists($class)) {
        printf("  SKIP  %s (class not found)\n", $class);
        $skip++;
        continue;
    }

    clearGuardableCache();

    try {
        /** @var Model $model */
        $model = new $class();
        $model->setConnection(OLD_HOST);

        // A key that is a real column on essentially every tenant-scoped table
        // here, and harmless: fill() only populates the in-memory attribute bag.
        $model->fill(['sub_institute_id' => 0]);

        printf("  PASS  %s\n", $class);
        $pass++;
    } catch (\Throwable $e) {
        $msg = preg_replace('/\s+/', ' ', $e->getMessage());
        printf("  FAIL  %s\n          %s\n", $class, substr($msg, 0, 120));
        $fail++;
    }
}

/*
 * ── THE OTHER HALF: EXPLICIT Schema:: INTROSPECTION ─────────────────────────
 *
 * The trait covers Eloquent's mass-assignment guard. It cannot cover the ~30
 * places that call Schema::hasColumn() / getColumnListing() / getColumns()
 * directly - AI, Competency, custom_module, the user importer, tbluserController.
 * Those are made safe instead by App\Database\Schema\LegacyMariaDbSchemaGrammar,
 * installed on every mysql/mariadb connection by AppServiceProvider.
 *
 * Asserted here rather than assumed, because the first version of that grammar
 * DID NOTHING: it decided whether the server was old by looking for "mariadb"
 * in Connection::getServerVersion(), and PDO returns a bare "10.1.48" with no
 * vendor tag. The override was installed, inert, and the query still threw.
 * Only an assertion against the real engine catches that.
 */
printf("\n  Explicit Schema:: introspection on %s\n", OLD_HOST);

$schemaChecks = [
    'hasTable(tbluser)' => [fn () => Schema::connection(OLD_HOST)->hasTable('tbluser'), true],
    'hasColumn(tbluser,id)' => [fn () => Schema::connection(OLD_HOST)->hasColumn('tbluser', 'id'), true],
    // The negative case matters: a grammar that returned an empty column list
    // would answer false here AND false above, and look like it worked.
    'hasColumn(tbluser,not_a_column)' => [fn () => Schema::connection(OLD_HOST)->hasColumn('tbluser', 'zzz_not_a_column'), false],
    'getColumnListing(tbluser) is populated' => [fn () => count(Schema::connection(OLD_HOST)->getColumnListing('tbluser')) > 10, true],
];

foreach ($schemaChecks as $label => [$fn, $expected]) {
    try {
        $actual = $fn();
        if ($actual === $expected) {
            printf("  PASS  %s\n", $label);
            $pass++;
        } else {
            printf("  FAIL  %s - expected %s got %s\n", $label, var_export($expected, true), var_export($actual, true));
            $fail++;
        }
    } catch (\Throwable $e) {
        printf("  FAIL  %s - THREW %s\n", $label, substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 100));
        $fail++;
    }
}

printf("\n  ---------------------------------------------\n");
printf("  %d passed, %d failed, %d skipped\n\n", $pass, $fail, $skip);

exit($fail === 0 ? 0 : 1);
